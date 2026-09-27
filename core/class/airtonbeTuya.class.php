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
 * Protocole local Tuya, version 3.3, tel que le parlent les climatiseurs
 * Airton (module Wi-Fi Tuya, port TCP 6668).
 *
 * Aucune dépendance au coeur de Jeedom : le démon charge ce fichier seul, et
 * les tests le rejouent hors ligne. Seules les extensions openssl et sockets
 * de PHP sont nécessaires.
 *
 * Une trame, en entiers big-endian :
 *
 *   000055AA | seqno | cmd | longueur | [retcode] | charge | crc32 | 0000AA55
 *
 * La longueur compte ce qui suit son champ : retcode éventuel, charge, CRC et
 * suffixe. Le CRC couvre tout ce qui le précède. Seules les trames de
 * l'appareil portent un retcode (0 = succès).
 *
 * La charge est du JSON sans espaces, chiffré en AES-128-ECB (bourrage PKCS7)
 * avec la « local_key » de l'appareil, prise telle quelle (16 caractères). Pour
 * les commandes d'écriture, elle est précédée de l'en-tête « 3.3 » suivi de
 * douze octets nuls, en clair.
 *
 * Référence : pytuya de LocalTuya (rospogrigio/localtuya et
 * xZetsubou/hass-localtuya) et tinytuya.
 */
class airtonbeTuya {

    const PORT = 6668;

    const PREFIX = 0x000055AA;
    const SUFFIX = 0x0000AA55;

    const CONTROL     = 7;
    const STATUS      = 8;
    const HEART_BEAT  = 9;
    const DP_QUERY    = 10;
    const CONTROL_NEW = 13;
    const UPDATEDPS   = 18;

    const VERSION = '3.3';

    /* Commandes envoyées sans l'en-tête de version : l'appareil les
     * rejetterait avec. */
    const NO_HEADER = array(self::DP_QUERY, self::HEART_BEAT, self::UPDATEDPS, 16, 3, 4, 5, 64);

    /* Clé des annonces UDP (port 6667) : md5("yGAdlopoPVldABfn"), commune à
     * tous les appareils. */
    const UDP_SECRET = 'yGAdlopoPVldABfn';

    /* =============================================================== TRAMES */

    public static function frame($_seq, $_cmd, $_payload) {
        $head = pack('NNNN', self::PREFIX, $_seq, $_cmd, strlen($_payload) + 8);
        return $head . $_payload . pack('NN', crc32($head . $_payload), self::SUFFIX);
    }

    public static function encrypt($_plain, $_key) {
        $out = openssl_encrypt($_plain, 'AES-128-ECB', $_key, OPENSSL_RAW_DATA);
        if ($out === false) {
            throw new Exception('Chiffrement impossible : clé locale invalide.');
        }
        return $out;
    }

    public static function decrypt($_data, $_key) {
        if ($_data === '' || strlen($_data) % 16 !== 0) {
            return false;
        }
        return openssl_decrypt($_data, 'AES-128-ECB', $_key, OPENSSL_RAW_DATA);
    }

    /* Une trame prête à envoyer : JSON chiffré, en-tête de version si la
     * commande en demande un. */
    public static function message($_seq, $_cmd, $_data, $_key) {
        $payload = self::encrypt(self::json($_data), $_key);
        if (!in_array($_cmd, self::NO_HEADER, true)) {
            $payload = self::VERSION . str_repeat("\0", 12) . $payload;
        }
        return self::frame($_seq, $_cmd, $payload);
    }

    /* JSON sans espaces (un appareil ne répond pas sinon), « dps » toujours en
     * objet même quand ses clés sont des nombres consécutifs. */
    public static function json($_data) {
        if (isset($_data['dps']) && is_array($_data['dps'])) {
            $_data['dps'] = (object) $_data['dps'];
        }
        return json_encode($_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /*
     * Découpe un tampon de réception en trames complètes. Rend les trames
     * trouvées ; le tampon garde le début d'une trame incomplète. Les octets
     * qui précèdent un préfixe sont jetés, une trame au CRC faux aussi.
     *
     * Chaque trame : array('seq', 'cmd', 'retcode', 'payload', 'crc_ok').
     * Les trames d'un appareil portent un retcode avant la charge, celles
     * qu'on lui envoie non : $_fromDevice à false pour relire les siennes.
     */
    public static function parse(&$_buffer, $_fromDevice = true) {
        $frames = array();
        $prefix = pack('N', self::PREFIX);
        while (true) {
            $pos = strpos($_buffer, $prefix);
            if ($pos === false) {
                /* Garder trois octets : un préfixe peut être coupé en deux. */
                $_buffer = strlen($_buffer) > 3 ? substr($_buffer, -3) : $_buffer;
                break;
            }
            if ($pos > 0) {
                $_buffer = substr($_buffer, $pos);
            }
            if (strlen($_buffer) < 16) {
                break;
            }
            $h = unpack('Nprefix/Nseq/Ncmd/Nlen', $_buffer);
            if ($h['len'] < 8 || $h['len'] > 65536) {
                /* Longueur absurde : ce préfixe n'en était pas un. */
                $_buffer = substr($_buffer, 4);
                continue;
            }
            $total = 16 + $h['len'];
            if (strlen($_buffer) < $total) {
                break;
            }
            $raw = substr($_buffer, 0, $total);
            $_buffer = (string) substr($_buffer, $total);
            $tail = unpack('Ncrc/Nsuffix', substr($raw, -8));
            $body = substr($raw, 16, $h['len'] - 8);
            $retcode = null;
            if ($_fromDevice && strlen($body) >= 4) {
                $retcode = unpack('N', substr($body, 0, 4))[1];
                $body = (string) substr($body, 4);
            }
            $frames[] = array(
                'seq'     => $h['seq'],
                'cmd'     => $h['cmd'],
                'retcode' => $retcode,
                'payload' => $body,
                'crc_ok'  => ((crc32(substr($raw, 0, -8)) & 0xFFFFFFFF) === $tail['crc']) && $tail['suffix'] === self::SUFFIX,
            );
        }
        return $frames;
    }

    /*
     * Charge d'une trame reçue, déchiffrée : un tableau (JSON), '' pour un
     * simple accusé de réception, ou une chaîne commençant par « ! » quand
     * elle est illisible (mauvaise clé, en général).
     */
    public static function decode($_payload, $_key) {
        if ($_payload === '') {
            return '';
        }
        if (strncmp($_payload, self::VERSION, 3) === 0) {
            $_payload = (string) substr($_payload, 15);
        }
        if ($_payload === '') {
            return '';
        }
        $plain = self::decrypt($_payload, $_key);
        if ($plain === false) {
            /* Certains accusés arrivent en clair. */
            $data = json_decode($_payload, true);
            return is_array($data) ? $data : '!indéchiffrable';
        }
        $data = json_decode($plain, true);
        if (!is_array($data)) {
            return '!' . $plain;
        }
        /* Les passerelles rangent les DP sous « data ». */
        if (!isset($data['dps']) && isset($data['data']['dps'])) {
            $data['dps'] = $data['data']['dps'];
        }
        return $data;
    }

    /* Charge que decode() n'a pas su lire. */
    public static function unreadable($_data) {
        return is_string($_data) && $_data !== '' && $_data[0] === '!';
    }

    /* ============================================================ COMMANDES */

    public static function queryData($_devId) {
        return array('gwId' => $_devId, 'devId' => $_devId, 'uid' => $_devId, 't' => (string) time());
    }

    public static function controlData($_devId, $_dps) {
        return array('devId' => $_devId, 'uid' => $_devId, 't' => (string) time(), 'dps' => $_dps);
    }

    public static function heartbeatData($_devId) {
        return array('gwId' => $_devId, 'devId' => $_devId);
    }

    /* ======================================================= SESSION SIMPLE */

    /*
     * Une connexion courte, bloquante, pour quand le démon ne tourne pas :
     * relevé par le cron, ordre depuis Jeedom, essai depuis la page. L'appareil
     * n'accepte qu'un client à la fois ; le démon, lui, garde la sienne.
     *
     * Rend les DP connus après l'échange (ceux de la réponse et ceux poussés
     * entre-temps).
     */
    public static function exchange($_ip, $_devId, $_key, $_dps = null, $_timeout = 5) {
        $sock = @stream_socket_client('tcp://' . $_ip . ':' . self::PORT, $errno, $error, $_timeout);
        if ($sock === false) {
            throw new Exception('Connexion impossible à ' . $_ip . ':' . self::PORT . ' (' . ($error !== '' ? $error : 'code ' . $errno) . ')');
        }
        stream_set_timeout($sock, $_timeout);
        $buffer = '';
        $dps = array();
        try {
            if ($_dps !== null) {
                self::write($sock, self::message(1, self::CONTROL, self::controlData($_devId, $_dps), $_key));
                self::await($sock, $buffer, $_key, self::CONTROL, $_timeout, $dps);
                /* L'état qui suit un ordre arrive en STATUS : on laisse une
                 * courte fenêtre avant de relire le tout. */
                usleep(200000);
            }
            $data = self::await($sock, $buffer, $_key, self::DP_QUERY, $_timeout, $dps,
                self::message(2, self::DP_QUERY, self::queryData($_devId), $_key));
            if (is_array($data) && isset($data['dps']) && is_array($data['dps'])) {
                $dps = $data['dps'] + $dps;
            }
        } finally {
            fclose($sock);
        }
        return $dps;
    }

    private static function write($_sock, $_frame) {
        if (@fwrite($_sock, $_frame) !== strlen($_frame)) {
            throw new Exception('Écriture impossible : l\'appareil a fermé la connexion (un autre client, Home Assistant par exemple, est peut-être déjà connecté).');
        }
    }

    /* Attend la réponse à une commande, en récoltant au passage les DP
     * poussés par l'appareil. */
    private static function await($_sock, &$_buffer, $_key, $_cmd, $_timeout, &$_dps, $_send = null) {
        if ($_send !== null) {
            self::write($_sock, $_send);
        }
        $deadline = microtime(true) + $_timeout;
        while (microtime(true) < $deadline) {
            foreach (self::parse($_buffer) as $f) {
                if (!$f['crc_ok']) {
                    continue;
                }
                /* Un refus arrive souvent en clair (« data format error ») :
                 * le retcode se lit avant de conclure à une clé fausse. */
                if ($f['retcode'] && $f['cmd'] === $_cmd) {
                    throw new Exception('L\'appareil a refusé la commande (code ' . $f['retcode'] . ').');
                }
                if ($f['retcode']) {
                    continue;
                }
                $data = self::decode($f['payload'], $_key);
                if (self::unreadable($data)) {
                    if ($f['cmd'] === self::DP_QUERY || $f['cmd'] === self::STATUS) {
                        throw new Exception('Réponse illisible : la clé locale est probablement fausse.');
                    }
                    $data = null;
                }
                if ($f['cmd'] === self::STATUS && is_array($data) && isset($data['dps']) && is_array($data['dps'])) {
                    $_dps = $data['dps'] + $_dps;
                }
                if ($f['cmd'] === $_cmd) {
                    return $data;
                }
            }
            $r = array($_sock);
            $w = $e = null;
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                continue;
            }
            $n = @stream_select($r, $w, $e, 0, (int) ($left * 1000000));
            if ($n === false) {
                /* Interrompu par un signal : sans pause, la boucle tournerait
                 * à vide jusqu'à l'échéance. */
                usleep(50000);
                continue;
            }
            if ($n === 0) {
                continue;
            }
            $chunk = @fread($_sock, 8192);
            if ($chunk === false || ($chunk === '' && feof($_sock))) {
                throw new Exception('Connexion fermée par l\'appareil (un autre client, Home Assistant par exemple, est peut-être déjà connecté).');
            }
            $_buffer .= $chunk;
        }
        throw new Exception('Pas de réponse de l\'appareil en ' . $_timeout . ' s.');
    }

    /* ============================================================ DÉCOUVERTE */

    /*
     * Écoute les annonces que chaque appareil Tuya diffuse toutes les cinq
     * secondes environ : en clair sur 6666 (anciens protocoles), chiffrées
     * sur 6667. Rend array(gwId => annonce décodée).
     */
    public static function discover($_seconds = 6) {
        if (!function_exists('socket_create')) {
            throw new Exception('L\'extension PHP sockets est absente.');
        }
        $socks = array();
        $errors = array();
        foreach (array(6666, 6667) as $port) {
            $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($s === false) {
                continue;
            }
            @socket_set_option($s, SOL_SOCKET, SO_REUSEADDR, 1);
            /* Pour écouter à côté d'un autre programme (Home Assistant sur la
             * même machine…). */
            if (defined('SO_REUSEPORT')) {
                @socket_set_option($s, SOL_SOCKET, SO_REUSEPORT, 1);
            }
            if (!@socket_bind($s, '0.0.0.0', $port)) {
                $errors[] = $port . ' : ' . socket_strerror(socket_last_error($s));
                socket_close($s);
                continue;
            }
            $socks[] = $s;
        }
        if (empty($socks)) {
            throw new Exception('Écoute UDP impossible (' . implode(' ; ', $errors) . ').');
        }
        $found = array();
        $end = microtime(true) + $_seconds;
        while (($left = $end - microtime(true)) > 0) {
            $r = $socks;
            $w = $e = null;
            $n = @socket_select($r, $w, $e, 0, (int) ($left * 1000000));
            if ($n === false) {
                usleep(50000);
                continue;
            }
            if ($n === 0) {
                continue;
            }
            foreach ($r as $s) {
                $buf = '';
                if (@socket_recvfrom($s, $buf, 4096, 0, $ip, $port) === false) {
                    continue;
                }
                $info = self::decodeAnnounce($buf);
                if (is_array($info) && !empty($info['gwId'])) {
                    if (empty($info['ip'])) {
                        $info['ip'] = $ip;
                    }
                    $found[$info['gwId']] = $info;
                }
            }
        }
        foreach ($socks as $s) {
            socket_close($s);
        }
        return $found;
    }

    public static function decodeAnnounce($_datagram) {
        $frames = self::parse($_datagram);
        if (empty($frames)) {
            return null;
        }
        $payload = $frames[0]['payload'];
        $data = json_decode($payload, true);
        if (is_array($data)) {
            return $data;
        }
        $plain = self::decrypt($payload, md5(self::UDP_SECRET, true));
        $data = $plain === false ? null : json_decode($plain, true);
        return is_array($data) ? $data : null;
    }
}
