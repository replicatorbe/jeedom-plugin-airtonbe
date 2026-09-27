<?php
/* Essai du démon de bout en bout, sans clim ni Jeedom :
 *
 *   php tests/daemon.php
 *
 * Une fausse clim (protocole Tuya 3.3, sur 127.0.0.2:6668) et un faux Jeedom
 * (serveur intégré de PHP) entourent le vrai démon. On lui envoie des
 * requêtes comme le ferait le plugin, et on regarde ce qu'il répond, ce que
 * la clim reçoit et ce que Jeedom reçoit. Sort en code 2 (ignoré) si le
 * port 6668 de 127.0.0.2 n'est pas disponible. */

require_once __DIR__ . '/../core/class/airtonbeTuya.class.php';

const KEY = '0123456789abcdef';
const DEV = 'bf0123456789abcdefgh';
const API = 'cle-api-essai';

$dir = sys_get_temp_dir() . '/airtonbe-daemon-test-' . getmypid();
@mkdir($dir);
$procs = array();
register_shutdown_function(function () use (&$procs, $dir) {
    foreach ($procs as $p) {
        $status = proc_get_status($p);
        if ($status['running']) {
            posix_kill($status['pid'], SIGTERM);
        }
        proc_close($p);
    }
    foreach (glob($dir . '/*') as $f) {
        unlink($f);
    }
    @rmdir($dir);
});

function freePort() {
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr($name, strrpos($name, ':') + 1);
}

function spawn($_cmd, $_log) {
    global $procs;
    $procs[] = proc_open($_cmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $_log, 'a'), 2 => array('file', $_log, 'a')), $pipes);
}

/* ---------------------------------------------------------- FAUSSE CLIM */

$probe = @stream_socket_server('tcp://127.0.0.2:6668');
if ($probe === false) {
    echo "127.0.0.2:6668 indisponible : essai du démon ignoré.\n";
    exit(2);
}
fclose($probe);

file_put_contents($dir . '/device.php', '<?php
require ' . var_export(realpath(__DIR__ . '/../core/class/airtonbeTuya.class.php'), true) . ';
$key = ' . var_export(KEY, true) . ';
$log = ' . var_export($dir . '/device.log', true) . ';
$state = array("1" => false, "2" => 240, "3" => 225, "4" => "cold");
function reply($seq, $cmd, $payload, $rc = 0) {
    $body = pack("N", $rc) . $payload;
    $h = pack("NNNN", 0x55AA, $seq, $cmd, strlen($body) + 8);
    return $h . $body . pack("NN", crc32($h . $body), 0xAA55);
}
$srv = stream_socket_server("tcp://127.0.0.2:6668");
$pushSeq = 100;
while ($c = stream_socket_accept($srv, 60)) {
    $buf = "";
    while (!feof($c)) {
        $chunk = fread($c, 8192);
        if ($chunk === "" || $chunk === false) { continue; }
        $buf .= $chunk;
        foreach (airtonbeTuya::parse($buf, false) as $f) {
            $data = airtonbeTuya::decode($f["payload"], $key);
            file_put_contents($log, $f["cmd"] . " " . json_encode($data) . "\n", FILE_APPEND);
            if ($f["cmd"] === 10) {
                fwrite($c, reply($f["seq"], 10, airtonbeTuya::encrypt(json_encode(array("devId" => "x", "dps" => $state)), $key)));
            } elseif ($f["cmd"] === 9) {
                fwrite($c, reply($f["seq"], 9, ""));
            } elseif ($f["cmd"] === 7) {
                /* DP 99 : refusé, en clair, comme le font les appareils Tuya. */
                if (isset($data["dps"]["99"])) {
                    fwrite($c, reply($f["seq"], 7, "data format error", 1));
                    continue;
                }
                fwrite($c, reply($f["seq"], 7, ""));
                foreach ($data["dps"] as $k => $v) { $state[$k] = $v; }
                fwrite($c, reply($pushSeq++, 8, "3.3" . str_repeat("\0", 12) . airtonbeTuya::encrypt(json_encode(array("dps" => $data["dps"], "t" => time())), $key)));
            }
        }
    }
}
');
spawn(array(PHP_BINARY, $dir . '/device.php'), $dir . '/device.out');

/* ---------------------------------------------------------- FAUX JEEDOM */

file_put_contents($dir . '/cb.php', '<?php
if (($_GET["apikey"] ?? "") !== ' . var_export(API, true) . ') { http_response_code(401); die(); }
if ($_GET["action"] === "config") {
    echo json_encode(array("devices" => array(array("eq" => 1, "name" => "[Test][Clim]", "ip" => "127.0.0.2", "devId" => ' . var_export(DEV, true) . ', "key" => ' . var_export(KEY, true) . '))));
    die();
}
file_put_contents(__DIR__ . "/pushes.log", file_get_contents("php://input") . "\n", FILE_APPEND);
echo "OK";
');
$httpPort = freePort();
spawn(array(PHP_BINARY, '-S', '127.0.0.1:' . $httpPort, '-t', $dir), $dir . '/http.log');

/* ----------------------------------------------------------------- DÉMON */

$daemonPort = freePort();
file_put_contents($dir . '/key', API);
usleep(300000);
spawn(array(PHP_BINARY, realpath(__DIR__ . '/../resources/airtonbed/airtonbed.php'),
    '--callback', 'http://127.0.0.1:' . $httpPort . '/cb.php', '--pid', $dir . '/d.pid', '--stamp', $dir . '/stamp',
    '--port', (string) $daemonPort, '--keyfile', $dir . '/key', '--loglevel', 'debug'), $dir . '/daemon.log');

function request($_req) {
    global $daemonPort;
    $s = @stream_socket_client('tcp://127.0.0.1:' . $daemonPort, $errno, $error, 2);
    if ($s === false) {
        return null;
    }
    stream_set_timeout($s, 10);
    fwrite($s, json_encode($_req + array('apikey' => API)) . "\n");
    $line = fgets($s);
    fclose($s);
    return is_string($line) ? json_decode($line, true) : null;
}

function waitFor($_label, $_test, $_seconds = 8) {
    $end = microtime(true) + $_seconds;
    while (microtime(true) < $end) {
        if ($_test()) {
            return true;
        }
        usleep(100000);
    }
    return false;
}

$passed = 0;
$failed = 0;
function check($_label, $_ok, $_detail = '') {
    global $passed, $failed;
    $_ok ? $passed++ : $failed++;
    printf("  %s %-62s %s\n", $_ok ? 'ok   ' : 'ECHEC', $_label, $_ok ? '' : $_detail);
}
$pushes = function () use ($dir) { return (string) @file_get_contents($dir . '/pushes.log'); };
$device = function () use ($dir) { return (string) @file_get_contents($dir . '/device.log'); };

echo "\nDémon\n-----\n";
check('connexion et état complet transmis à Jeedom',
    waitFor('état', function () use ($pushes) { return strpos($pushes(), '"full":true') !== false; }), $pushes());
check('appareil annoncé en ligne', strpos($pushes(), '"online":1') !== false);

$r = request(array('eq' => 1, 'dps' => array('1' => true, '4' => 'heat'), 'until' => microtime(true) + 3));
check('ordre acquitté', is_array($r) && !empty($r['ok']), json_encode($r));
check('marche et mode dans une seule trame', strpos($device(), '7 {"devId":"' . DEV . '","uid":"' . DEV . '","t":') !== false
    && strpos($device(), '"dps":{"1":true,"4":"heat"}') !== false, $device());
check('changement poussé transmis', waitFor('push', function () use ($pushes) { return strpos($pushes(), '"dps":{"1":true,"4":"heat"},"full":false') !== false; }), $pushes());

$r = request(array('eq' => 1, 'dps' => array('99' => 1), 'until' => microtime(true) + 3));
check('refus en clair : erreur rendue, pas « clé fausse »', is_array($r) && empty($r['ok']) && strpos($r['error'], 'refusé') !== false, json_encode($r));
$r = request(array('eq' => 1, 'refresh' => 1));
check('connexion gardée après un refus', is_array($r) && !empty($r['ok']), json_encode($r));

$r = request(array('eq' => 1, 'dps' => array('13' => true), 'until' => microtime(true) - 1));
usleep(300000);
check('requête périmée : refusée', is_array($r) && empty($r['ok']), json_encode($r));
check('requête périmée : rien envoyé à la clim', strpos($device(), '"13":true') === false);

$r = request(array('eq' => 7, 'refresh' => 1));
check('appareil inconnu signalé comme tel', is_array($r) && !empty($r['unknown']), json_encode($r));
$r = request(array('eq' => 1, 'refresh' => 1, 'apikey' => 'mauvaise'));
check('mauvaise clé API refusée', is_array($r) && empty($r['ok']), json_encode($r));

check('heartbeat envoyé et connexion tenue 12 s',
    waitFor('hb', function () use ($device) { return preg_match('/^9 /m', $device()) === 1; }, 13)
    && strpos((string) @file_get_contents($dir . '/daemon.log'), 'perdue') === false, (string) @file_get_contents($dir . '/daemon.log'));

printf("\n%d réussi(s), %d échec(s)\n", $passed, $failed);
if ($failed > 0) {
    echo "\n--- journal du démon\n" . @file_get_contents($dir . '/daemon.log');
}
exit($failed === 0 ? 0 : 1);
