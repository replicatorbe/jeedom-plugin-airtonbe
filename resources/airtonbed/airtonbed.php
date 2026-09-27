<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * Démon Airton : tient la connexion Tuya de chaque climatiseur.
 *
 * Un appareil Tuya n'accepte qu'un client TCP à la fois. Le démon garde donc
 * la connexion ouverte (heartbeat toutes les 10 s), reçoit les changements que
 * la clim pousse d'elle-même — télécommande, appli, ordre de Jeedom — et les
 * transmet au plugin. Les ordres de Jeedom lui arrivent sur une socket locale
 * (127.0.0.1), une ligne JSON par requête :
 *
 *   {"apikey":"…","eq":12,"dps":{"1":true,"4":"cold"}}   → ordre
 *   {"apikey":"…","eq":12,"refresh":1}                   → relecture complète
 *
 * et répondent par {"ok":true} une fois l'ordre acquitté par la clim, ou
 * {"ok":false,"error":"…"}.
 *
 * Il ne charge pas core.inc.php : PHP en ligne de commande, curl, openssl.
 *
 *   php airtonbed.php --callback URL --pid FICHIER --stamp FICHIER --port 55133 --keyfile FICHIER --loglevel debug --timezone Europe/Brussels
 */

require_once __DIR__ . '/../../core/class/airtonbeTuya.class.php';

error_reporting(E_ALL);
set_time_limit(0);
$options = getopt('', array('callback:', 'pid:', 'stamp:', 'port:', 'keyfile:', 'loglevel:', 'timezone:'));
if (!empty($options['timezone']) && in_array($options['timezone'], timezone_identifiers_list(), true)) {
    date_default_timezone_set($options['timezone']);
}
$callback = isset($options['callback']) ? $options['callback'] : '';
$pidFile  = isset($options['pid']) ? $options['pid'] : '';
$stamp    = isset($options['stamp']) ? $options['stamp'] : '';
$port     = isset($options['port']) ? (int) $options['port'] : 55133;
$logLevel = isset($options['loglevel']) ? $options['loglevel'] : 'error';
$apiKey   = '';
if (!empty($options['keyfile']) && is_readable($options['keyfile'])) {
    $apiKey = trim((string) file_get_contents($options['keyfile']));
    @unlink($options['keyfile']);
}

$levels = array('debug' => 0, 'info' => 1, 'notice' => 1, 'warning' => 2, 'error' => 3,
                'critical' => 3, 'alert' => 3, 'emergency' => 3, 'none' => 4);
$threshold = isset($levels[$logLevel]) ? $levels[$logLevel] : 3;

function atLog($_level, $_message) {
    global $levels, $threshold;
    if ($levels[$_level] >= $threshold) {
        echo '[' . date('Y-m-d H:i:s') . '][' . strtoupper($_level) . '] : ' . $_message . "\n";
    }
}

if ($callback === '' || $pidFile === '' || $apiKey === '') {
    atLog('error', 'Arguments manquants : --callback, --pid et --keyfile (contenant la clé API) sont obligatoires.');
    exit(1);
}
foreach (array('curl_init' => 'curl', 'openssl_encrypt' => 'openssl') as $function => $extension) {
    if (!function_exists($function)) {
        atLog('error', 'L\'extension PHP ' . $extension . ' est absente.');
        exit(1);
    }
}

$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
if ($server === false) {
    atLog('error', 'Port local ' . $port . ' indisponible (' . $error . ') : changez-le dans la configuration du plugin.');
    exit(1);
}
stream_set_blocking($server, false);

file_put_contents($pidFile, (string) getmypid());

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running) { $running = false; };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

/* Horloge monotone : un recalage NTP ne doit pas fausser les délais. */
function atClock() {
    return hrtime(true) / 1e9;
}

/* Rythme de la connexion Tuya. */
const HEARTBEAT_EVERY = 10;
const SILENCE_MAX     = 35;   /* sans aucune trame : connexion morte */
const QUERY_EVERY     = 300;  /* relecture complète de sécurité */
const ORDER_TIMEOUT   = 5;
const STABLE_AFTER    = 60;

/* ------------------------------------------------------------ JEEDOM */

function atCall($_query, $_body = null) {
    global $callback, $apiKey, $pidFile;
    $url = $callback . (strpos($callback, '?') === false ? '?' : '&') . 'apikey=' . rawurlencode($apiKey) . '&' . $_query;
    $ch = curl_init($url);
    $opts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_PROXY          => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    );
    if ($_body !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $_body;
        $opts[CURLOPT_HTTPHEADER] = array('Content-Type: application/json');
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    /* Clé refusée : inutile d'insister, la gestion automatique du coeur
     * relancera le démon avec la bonne. */
    if ($code === 401 || $code === 403) {
        atLog('error', 'Jeedom refuse l\'accès (HTTP ' . $code . ') : clé API changée ou accès API restreint. Arrêt du démon.');
        @unlink($pidFile);
        exit(1);
    }
    return array($code, $body, $err);
}

/* Ce qui attend d'être transmis : [eq] => array('dps' => …, 'full' => bool,
 * 'online' => 0|1, 'error' => …). */
$pushes = array();
$pushedAt = -INF;
$pushRetryAt = -INF;
$pushFailures = 0;

function atQueue($_eq, $_data) {
    global $pushes;
    $cur = isset($pushes[$_eq]) ? $pushes[$_eq] : array();
    if (isset($_data['dps'])) {
        $full = !empty($_data['full']);
        $cur['dps'] = ($full || !isset($cur['dps'])) ? $_data['dps'] : $_data['dps'] + $cur['dps'];
        $cur['full'] = $full || !empty($cur['full']);
        unset($_data['dps'], $_data['full']);
    }
    $pushes[$_eq] = $_data + $cur;
    if (isset($_data['online'])) {
        $pushes[$_eq]['online'] = $_data['online'];
    }
}

/* Au plus deux envois par seconde ; en cas d'échec, les états sont gardés et
 * les essais s'espacent. */
function atFlush() {
    global $pushes, $pushedAt, $pushRetryAt, $pushFailures;
    $clock = atClock();
    if (empty($pushes) || $clock - $pushedAt < 0.5 || $clock < $pushRetryAt) {
        return;
    }
    $sending = $pushes;
    $pushes = array();
    $pushedAt = $clock;
    $data = array();
    foreach ($sending as $eq => $p) {
        if (isset($p['dps'])) {
            $p['dps'] = (object) $p['dps'];
        }
        $data[(string) $eq] = $p;
    }
    list($code, , $err) = atCall('action=push', json_encode(array('pushes' => (object) $data)));
    if ($code === 200) {
        $pushFailures = 0;
        return;
    }
    foreach ($sending as $eq => $p) {
        atQueue($eq, $p);
    }
    $pushFailures++;
    $pushRetryAt = atClock() + min(30, 2 * (1 << min(4, $pushFailures - 1)));
    if ($pushFailures === 1) {
        atLog('warning', 'Transmission à Jeedom en échec (HTTP ' . $code . ' ' . $err . '), nouvel essai en s\'espaçant');
    }
}

/* ------------------------------------------------------- CONNEXIONS TUYA */

$devices = array();   /* [eq] => connexion */

function atNew($_d) {
    return array('eq' => (int) $_d['eq'], 'name' => $_d['name'], 'ip' => $_d['ip'], 'devId' => $_d['devId'], 'key' => $_d['key'],
                 'sock' => null, 'state' => 'idle', 'buf' => '', 'out' => '', 'seq' => 1, 'fails' => 0, 'down' => false,
                 'next' => atClock(), 'last' => 0, 'hbAt' => 0, 'queryAt' => 0, 'openedAt' => 0, 'deadline' => 0,
                 'pending' => array());
}

function atSync($_wanted) {
    global $devices;
    $seen = array();
    foreach ($_wanted as $d) {
        $eq = (int) $d['eq'];
        $seen[$eq] = true;
        if (isset($devices[$eq])) {
            $c = &$devices[$eq];
            $c['name'] = $d['name'];
            if ($c['ip'] !== $d['ip'] || $c['devId'] !== $d['devId'] || $c['key'] !== $d['key']) {
                atClose($c, 'configuration changée', false);
                $devices[$eq] = atNew($d);
            }
            unset($c);
            continue;
        }
        $devices[$eq] = atNew($d);
    }
    foreach (array_keys($devices) as $eq) {
        if (!isset($seen[$eq])) {
            atClose($devices[$eq], 'équipement retiré', false);
            unset($devices[$eq]);
        }
    }
}

function atSend(&$_c, $_cmd, $_data) {
    $seq = $_c['seq']++;
    $_c['out'] .= airtonbeTuya::message($seq, $_cmd, $_data, $_c['key']);
    atWrite($_c);
    return $seq;
}

function atWrite(&$_c) {
    if ($_c['out'] === '' || !is_resource($_c['sock'])) {
        return;
    }
    $n = @fwrite($_c['sock'], $_c['out']);
    if ($n === false) {
        atClose($_c, 'écriture impossible');
        return;
    }
    $_c['out'] = (string) substr($_c['out'], $n);
}

function atClose(&$_c, $_why, $_report = true) {
    if (is_resource($_c['sock'])) {
        @fclose($_c['sock']);
    }
    $clock = atClock();
    $wasOpen = $_c['state'] === 'open';
    $stable = $wasOpen && $clock - $_c['openedAt'] >= STABLE_AFTER;
    $_c['fails'] = $stable ? 1 : $_c['fails'] + 1;
    $_c['sock'] = null;
    $_c['state'] = 'idle';
    $_c['buf'] = '';
    $_c['out'] = '';
    /* 5 s, 10 s, 20 s… jusqu'à la minute. */
    $_c['next'] = $clock + min(60, 5 * (1 << min(4, $_c['fails'] - 1)));
    foreach ($_c['pending'] as $p) {
        atReply($p['client'], false, 'connexion à la clim perdue (' . $_why . ')');
    }
    $_c['pending'] = array();
    if (!$_report) {
        return;
    }
    /* Une connexion fermée aussitôt ouverte : un autre client tient
     * probablement la clim. */
    if ($wasOpen && $clock - $_c['openedAt'] < 3) {
        $_why .= ' — un autre client (Home Assistant, appli en local…) est peut-être connecté à la clim';
    }
    if (!$_c['down']) {
        $_c['down'] = true;
        atLog('info', 'Connexion à ' . $_c['name'] . ' perdue (' . $_why . ')');
        atQueue($_c['eq'], array('online' => 0, 'error' => $_why));
    } else {
        atLog('debug', 'Connexion à ' . $_c['name'] . ' : ' . $_why);
    }
}

function atConnect(&$_c) {
    $sock = @stream_socket_client('tcp://' . $_c['ip'] . ':' . airtonbeTuya::PORT, $errno, $error, 0,
        STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
    if ($sock === false) {
        atClose($_c, $error !== '' ? $error : 'connexion impossible');
        return;
    }
    stream_set_blocking($sock, false);
    $_c['sock'] = $sock;
    $_c['state'] = 'connecting';
    $_c['deadline'] = atClock() + 5;
}

/* Connexion établie : relecture complète, puis le heartbeat prend le relais. */
function atOpened(&$_c) {
    $clock = atClock();
    $_c['state'] = 'open';
    $_c['openedAt'] = $clock;
    $_c['last'] = $clock;
    $_c['hbAt'] = $clock;
    $_c['queryAt'] = $clock;
    $_c['seq'] = 1;
    atSend($_c, airtonbeTuya::DP_QUERY, airtonbeTuya::queryData($_c['devId']));
}

function atRead(&$_c) {
    $chunk = @fread($_c['sock'], 8192);
    if ($chunk === false || ($chunk === '' && feof($_c['sock']))) {
        atClose($_c, 'fermée par la clim');
        return;
    }
    if ($chunk === '') {
        return;
    }
    $_c['buf'] .= $chunk;
    $_c['last'] = atClock();
    foreach (airtonbeTuya::parse($_c['buf']) as $f) {
        if (!$f['crc_ok']) {
            atLog('debug', $_c['name'] . ' : trame au CRC faux ignorée');
            continue;
        }
        $data = airtonbeTuya::decode($f['payload'], $_c['key']);
        if (is_string($data) && $data !== '' && $data[0] === '!') {
            atClose($_c, 'réponse indéchiffrable, clé locale fausse ?');
            return;
        }
        atFrame($_c, $f, $data);
        if ($_c['state'] !== 'open') {
            return;
        }
    }
}

function atFrame(&$_c, $_f, $_data) {
    $cmd = $_f['cmd'];
    $dps = (is_array($_data) && isset($_data['dps']) && is_array($_data['dps'])) ? $_data['dps'] : null;
    if ($cmd === airtonbeTuya::DP_QUERY && $dps !== null) {
        atLog('debug', $_c['name'] . ' : état complet ' . json_encode($dps));
        atQueue($_c['eq'], array('online' => 1, 'dps' => $dps, 'full' => true));
        /* Une ligne à la première connexion et à chaque retour, pas une par
         * relecture. */
        if ($_c['down'] || empty($_c['ever'])) {
            atLog('info', 'Connexion à ' . $_c['name'] . ' établie');
        }
        $_c['down'] = false;
        $_c['ever'] = true;
    } elseif (($cmd === airtonbeTuya::STATUS || $cmd === airtonbeTuya::UPDATEDPS) && $dps !== null) {
        atLog('debug', $_c['name'] . ' : changement ' . json_encode($dps));
        atQueue($_c['eq'], array('dps' => $dps));
    } elseif ($cmd === airtonbeTuya::CONTROL) {
        /* Accusé d'un ordre : même numéro de séquence que l'envoi. */
        if (isset($_c['pending'][$_f['seq']])) {
            $p = $_c['pending'][$_f['seq']];
            unset($_c['pending'][$_f['seq']]);
            if ($_f['retcode']) {
                atReply($p['client'], false, 'la clim a refusé l\'ordre (code ' . $_f['retcode'] . ')');
            } else {
                atReply($p['client'], true);
            }
        }
    }
    /* HEART_BEAT : rien à faire, la trame a suffi à prouver la vie. */
}

/* ----------------------------------------------------- SOCKET LOCALE */

$clients = array();   /* [id] => array('sock', 'buf', 'since') */
$clientSeq = 0;

function atReply($_clientId, $_ok, $_error = '') {
    global $clients;
    if (!isset($clients[$_clientId])) {
        return;
    }
    $reply = $_ok ? array('ok' => true) : array('ok' => false, 'error' => $_error);
    @fwrite($clients[$_clientId]['sock'], json_encode($reply) . "\n");
    @fclose($clients[$_clientId]['sock']);
    unset($clients[$_clientId]);
}

function atRequest($_clientId, $_line) {
    global $devices, $apiKey;
    $req = json_decode($_line, true);
    if (!is_array($req) || !isset($req['apikey']) || !hash_equals($apiKey, (string) $req['apikey'])) {
        atReply($_clientId, false, 'requête refusée');
        return;
    }
    $eq = isset($req['eq']) ? (int) $req['eq'] : 0;
    if (!isset($devices[$eq])) {
        atReply($_clientId, false, 'climatiseur inconnu du démon (désactivé, ou configuration pas encore relue)');
        return;
    }
    $c = &$devices[$eq];
    if ($c['state'] !== 'open') {
        atReply($_clientId, false, 'pas de connexion à la clim en ce moment : ' . ($c['down'] ? 'elle ne répond pas' : 'connexion en cours'));
        return;
    }
    if (!empty($req['refresh'])) {
        $c['queryAt'] = atClock();
        atSend($c, airtonbeTuya::DP_QUERY, airtonbeTuya::queryData($c['devId']));
        atReply($_clientId, true);
        return;
    }
    if (!isset($req['dps']) || !is_array($req['dps']) || empty($req['dps'])) {
        atReply($_clientId, false, 'aucun DP à envoyer');
        return;
    }
    atLog('debug', $c['name'] . ' : ordre ' . json_encode((object) $req['dps']));
    $seq = atSend($c, airtonbeTuya::CONTROL, airtonbeTuya::controlData($c['devId'], $req['dps']));
    if ($c['state'] === 'open') {
        $c['pending'][$seq] = array('client' => $_clientId, 'at' => atClock());
    } else {
        atReply($_clientId, false, 'connexion à la clim perdue à l\'envoi');
    }
}

/* -------------------------------------------------------------- BOUCLE */

function atPoll($_timeout) {
    global $devices, $server, $clients, $clientSeq;
    $clock = atClock();
    $read = array('server' => $server);
    $write = array();
    foreach ($devices as $eq => &$c) {
        if ($c['state'] === 'idle' && $clock >= $c['next']) {
            atConnect($c);
        }
        if ($c['state'] === 'connecting') {
            if ($clock > $c['deadline']) {
                atClose($c, 'délai de connexion dépassé');
                continue;
            }
            $write['d' . $eq] = $c['sock'];
        } elseif ($c['state'] === 'open') {
            if ($clock - $c['hbAt'] >= HEARTBEAT_EVERY) {
                $c['hbAt'] = $clock;
                atSend($c, airtonbeTuya::HEART_BEAT, airtonbeTuya::heartbeatData($c['devId']));
            }
            if ($c['state'] === 'open' && $clock - $c['queryAt'] >= QUERY_EVERY) {
                $c['queryAt'] = $clock;
                atSend($c, airtonbeTuya::DP_QUERY, airtonbeTuya::queryData($c['devId']));
            }
            if ($c['state'] !== 'open') {
                continue;
            }
            $read['d' . $eq] = $c['sock'];
            if ($c['out'] !== '') {
                $write['d' . $eq] = $c['sock'];
            }
        }
    }
    unset($c);
    foreach ($clients as $id => $cl) {
        $read['c' . $id] = $cl['sock'];
    }
    $r = array_values($read);
    $w = array_values($write);
    $e = null;
    $n = @stream_select($r, $w, $e, 0, (int) ($_timeout * 1000000));
    if ($n === false || $n === 0) {
        atExpire();
        return;
    }
    foreach ($write as $key => $sock) {
        $eq = (int) substr($key, 1);
        if (!in_array($sock, $w, true) || !isset($devices[$eq]) || $devices[$eq]['sock'] !== $sock) {
            continue;
        }
        if ($devices[$eq]['state'] === 'connecting') {
            /* Une connexion non bloquante se dit prête même refusée : c'est
             * l'adresse du pair qui tranche. */
            if (@stream_socket_get_name($sock, true) === false) {
                atClose($devices[$eq], 'connexion refusée');
            } else {
                atOpened($devices[$eq]);
            }
        } else {
            atWrite($devices[$eq]);
        }
    }
    foreach ($read as $key => $sock) {
        if (!in_array($sock, $r, true)) {
            continue;
        }
        if ($key === 'server') {
            $client = @stream_socket_accept($server, 0);
            if ($client !== false) {
                stream_set_blocking($client, false);
                $clients[++$clientSeq] = array('sock' => $client, 'buf' => '', 'since' => atClock());
            }
            continue;
        }
        $id = (int) substr($key, 1);
        if ($key[0] === 'd') {
            if (isset($devices[$id]) && $devices[$id]['sock'] === $sock) {
                atRead($devices[$id]);
            }
            continue;
        }
        if (!isset($clients[$id])) {
            continue;
        }
        $chunk = @fread($sock, 8192);
        if ($chunk === false || ($chunk === '' && feof($sock))) {
            @fclose($sock);
            unset($clients[$id]);
            continue;
        }
        $clients[$id]['buf'] .= $chunk;
        if (strlen($clients[$id]['buf']) > 65536) {
            atReply($id, false, 'requête démesurée');
            continue;
        }
        $pos = strpos($clients[$id]['buf'], "\n");
        if ($pos !== false) {
            $line = substr($clients[$id]['buf'], 0, $pos);
            /* Plus lu : la réponse viendra quand la clim aura acquitté. */
            $clients[$id]['buf'] = '';
            $clients[$id]['waiting'] = true;
            atRequest($id, $line);
        }
    }
    atExpire();
}

/* Ordres sans accusé, clients muets, connexions silencieuses. */
function atExpire() {
    global $devices, $clients;
    $clock = atClock();
    foreach ($devices as $eq => &$c) {
        foreach ($c['pending'] as $seq => $p) {
            if ($clock - $p['at'] > ORDER_TIMEOUT) {
                unset($c['pending'][$seq]);
                atReply($p['client'], false, 'pas d\'accusé de la clim en ' . ORDER_TIMEOUT . ' s');
            }
        }
        if ($c['state'] === 'open' && $clock - $c['last'] > SILENCE_MAX) {
            atClose($c, 'silence');
        }
    }
    unset($c);
    foreach ($clients as $id => $cl) {
        if (empty($cl['waiting']) && $clock - $cl['since'] > 10) {
            @fclose($cl['sock']);
            unset($clients[$id]);
        }
    }
}

function atConnected() {
    global $devices;
    $eqs = array();
    foreach ($devices as $eq => $c) {
        if ($c['state'] === 'open' && !$c['down']) {
            $eqs[] = $eq;
        }
    }
    return $eqs;
}

$config = null;
$stampSeen = null;
$fetchedAt = -INF;
$failures = 0;
$retryAt = -INF;

atLog('info', 'Démarrage du démon Airton (PID ' . getmypid() . ', port local ' . $port . ')');

while ($running) {
    $clock = atClock();
    /* Configuration relue quand le plugin le signale (fichier témoin), et de
     * toute façon chaque minute. */
    $content = ($stamp !== '' && is_readable($stamp)) ? @file_get_contents($stamp) : '';
    if (($config === null || $content !== $stampSeen || $clock - $fetchedAt > 60) && $clock >= $retryAt) {
        list($code, $body, $err) = atCall('action=config&connected=' . implode(',', atConnected()));
        $fresh = $code === 200 ? json_decode((string) $body, true) : null;
        if (is_array($fresh) && isset($fresh['devices']) && is_array($fresh['devices'])) {
            if ($failures > 0) {
                atLog('info', 'Jeedom de nouveau joignable');
            }
            if ($config === null || count($fresh['devices']) !== count($config['devices'])) {
                atLog('info', count($fresh['devices']) . ' climatiseur(s) à suivre');
            }
            atSync($fresh['devices']);
            $config = $fresh;
            $stampSeen = $content;
            $fetchedAt = $clock;
            $failures = 0;
        } else {
            $failures++;
            $retryAt = $clock + min(60, 5 * (1 << min(4, $failures - 1)));
            if ($failures === 1) {
                atLog('warning', 'Configuration illisible (HTTP ' . $code . ' ' . $err . '), nouvel essai en s\'espaçant');
            }
        }
    }
    atPoll(0.2);
    atFlush();
}

foreach ($devices as $eq => $c) {
    if (is_resource($c['sock'])) {
        @fclose($c['sock']);
    }
}
foreach ($clients as $cl) {
    @fclose($cl['sock']);
}
@fclose($server);
@unlink($pidFile);
atLog('info', 'Arrêt du démon Airton');
