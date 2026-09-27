<?php
/* Rejeu hors ligne : protocole Tuya 3.3 et traduction des DP Airton.
 *
 *   php tests/run.php
 *
 * tests/fixtures/airton_409730_dps.json est l'état complet d'un vrai monosplit
 * Airton 409730 (clé produit keyquxnsj75xc8se), relevé en local. Les trames
 * sont chiffrées avec une clé d'essai : aucune clé réelle ici. */

require_once __DIR__ . '/stub.php';
require_once __DIR__ . '/../core/class/airtonbeTuya.class.php';

/* La classe charge le coeur en tête ; hors installation, on la recopie sans
 * ce require, et on l'efface en sortant, même sur erreur fatale. */
$original = __DIR__ . '/../core/class/airtonbe.class.php';
$copy = __DIR__ . '/../core/class/.airtonbe.test.php';
$source = preg_replace('#^\s*require_once .*core\.inc\.php.*$#m', '', file_get_contents($original));
file_put_contents($copy, $source);
register_shutdown_function(function () use ($copy) {
    if (file_exists($copy)) { unlink($copy); }
});
require_once $copy;

$passed = 0;
$failed = 0;

function check($_label, $_actual, $_expected) {
    global $passed, $failed;
    if ($_actual === $_expected) {
        $passed++;
        printf("  ok    %-60s %s\n", $_label, var_export($_actual, true));
    } else {
        $failed++;
        printf("  ECHEC %-60s obtenu %s, attendu %s\n", $_label, var_export($_actual, true), var_export($_expected, true));
    }
}

function throws($_label, $_callback, $_contains) {
    global $passed, $failed;
    try {
        $_callback();
        $failed++;
        printf("  ECHEC %-60s aucune exception\n", $_label);
    } catch (Throwable $e) {
        $ok = strpos($e->getMessage(), $_contains) !== false;
        $ok ? $passed++ : $failed++;
        printf("  %s %-60s %s\n", $ok ? 'ok   ' : 'ECHEC', $_label, $e->getMessage());
    }
}

function section($_title) {
    echo "\n" . $_title . "\n" . str_repeat('-', mb_strlen($_title)) . "\n";
}

/* Une trame telle que l'appareil l'envoie : avec retcode. */
function deviceFrame($_seq, $_cmd, $_payload, $_retcode = 0) {
    $body = pack('N', $_retcode) . $_payload;
    $head = pack('NNNN', 0x55AA, $_seq, $_cmd, strlen($body) + 8);
    return $head . $body . pack('NN', crc32($head . $body), 0xAA55);
}

const KEY = '0123456789abcdef';
const DEV = 'bf0123456789abcdefgh';
$state = json_decode(file_get_contents(__DIR__ . '/fixtures/airton_409730_dps.json'), true);

section('Trames');
$hb = airtonbeTuya::message(1, airtonbeTuya::HEART_BEAT, airtonbeTuya::heartbeatData(DEV), KEY);
check('heartbeat : préfixe, séquence, commande', bin2hex(substr($hb, 0, 12)), '000055aa0000000100000009');
check('heartbeat : longueur = 64 chiffrés + 8', unpack('N', substr($hb, 12, 4))[1], 72);
check('heartbeat : suffixe', bin2hex(substr($hb, -4)), '0000aa55');
check('heartbeat : sans en-tête de version', substr($hb, 16, 3) !== '3.3', true);
$q = airtonbeTuya::message(2, airtonbeTuya::DP_QUERY, airtonbeTuya::queryData(DEV), KEY);
check('DP_QUERY : sans en-tête de version', substr($q, 16, 3) !== '3.3', true);
$ctl = airtonbeTuya::message(3, airtonbeTuya::CONTROL, airtonbeTuya::controlData(DEV, array('1' => true, '4' => 'cold')), KEY);
check('CONTROL : en-tête « 3.3 » + 12 octets nuls', bin2hex(substr($ctl, 16, 15)), bin2hex('3.3' . str_repeat("\0", 12)));

$buf = $ctl;
$frames = airtonbeTuya::parse($buf, false);
check('relecture d\'une trame envoyée : une trame', count($frames), 1);
check('relecture : CRC juste', $frames[0]['crc_ok'], true);
$data = airtonbeTuya::decode($frames[0]['payload'], KEY);
check('relecture : DP rendus', $data['dps'], array('1' => true, '4' => 'cold'));
check('JSON sans espaces, dps en objet', airtonbeTuya::json(array('dps' => array('1' => true))), '{"dps":{"1":true}}');

section('Réception');
$query = deviceFrame(1, 10, airtonbeTuya::encrypt(json_encode(array('devId' => DEV, 'dps' => $state)), KEY));
$push = deviceFrame(7, 8, '3.3' . str_repeat("\0", 12) . airtonbeTuya::encrypt('{"dps":{"1":true},"t":1790000000}', KEY));
$ack = deviceFrame(3, 7, '');
$buf = "\x00\x13garbage" . substr($query, 0, 30);
check('trame incomplète : rien encore', count(airtonbeTuya::parse($buf)), 0);
$buf .= substr($query, 30) . $push . $ack;
$frames = airtonbeTuya::parse($buf);
check('octets parasites jetés, trois trames', count($frames), 3);
check('tampon vidé', $buf, '');
check('réponse DP_QUERY : retcode 0', $frames[0]['retcode'], 0);
check('réponse DP_QUERY : 24 DP', count(airtonbeTuya::decode($frames[0]['payload'], KEY)['dps']), 24);
check('changement poussé (avec en-tête) décodé', airtonbeTuya::decode($frames[1]['payload'], KEY)['dps'], array('1' => true));
check('accusé d\'ordre : charge vide', airtonbeTuya::decode($frames[2]['payload'], KEY), '');
check('accusé d\'ordre : séquence reprise', $frames[2]['seq'], 3);
check('mauvaise clé : réponse signalée illisible', airtonbeTuya::decode($frames[0]['payload'], 'fedcba9876543210')[0], '!');
$bad = $ack;
$bad[20] = "\xff";
$b = $bad;
check('CRC faux détecté', airtonbeTuya::parse($b)[0]['crc_ok'], false);

section('Découverte');
$announce = '{"ip":"192.168.0.50","gwId":"' . DEV . '","active":2,"encrypt":true,"productKey":"keyquxnsj75xc8se","version":"3.3"}';
$udp = deviceFrame(0, 0x13, openssl_encrypt($announce, 'AES-128-ECB', md5('yGAdlopoPVldABfn', true), OPENSSL_RAW_DATA));
$info = airtonbeTuya::decodeAnnounce($udp);
check('annonce chiffrée (6667) : gwId', $info['gwId'], DEV);
check('annonce chiffrée : clé produit', $info['productKey'], 'keyquxnsj75xc8se');
check('annonce en clair (6666)', airtonbeTuya::decodeAnnounce(deviceFrame(0, 0, $announce))['version'], '3.3');

section('Valeurs tirées des DP (Airton 409730)');
$v = airtonbe::valuesFromDps($state);
check('état : éteinte', $v['power'], 0);
check('consigne 160 → 16 °C', $v['target'], 16.0);
check('température 230 → 23 °C', $v['temperature'], 23.0);
check('mode brut', $v['mode'], 'cold');
check('ventilation', $v['fan'], 'turbo');
check('affichage', $v['display'], 1);
check('balayage vertical', $v['swing_v'], 'off');
check('type de clim', $v['ac_type'], 'cold');
check('aucun défaut', $v['fault'], 0);
check('aucun code défaut', $v['fault_codes'], '');
check('DP inconnu gardé en brut', airtonbe::valuesFromDps(array('150' => true))['dp_150'], 1);
check('consommation 123 → 12,3 kWh', airtonbe::valuesFromDps(array('103' => 123))['energy'], 12.3);
check('codes défaut : bits 1 et 16 de DP 20', airtonbe::faultCodes((1 << 1) | (1 << 16), 0), array('E4', 'P0'));
check('codes défaut : bit 14 de DP 113', airtonbe::faultCodes(0, 1 << 14), array('E1'));
$f = airtonbe::valuesFromDps(array('113' => 1), array('20' => 4, '113' => 1));
check('défaut combiné des deux DP', $f['fault_codes'], 'E5, PF');

section('Ordres');
$off = $state;
$on = array('1' => true) + $state;
$heat = array('1' => true, '4' => 'heat') + $state;
check('mode clim éteinte : marche + mode dans la même trame', airtonbe::dpsForAction('mode_set', array('select' => 'heat'), $off), array('1' => true, '4' => 'heat'));
check('mode clim allumée : le mode seul', airtonbe::dpsForAction('mode_set', array('select' => 'fan'), $on), array('4' => 'fan'));
check('allumer', airtonbe::dpsForAction('power_on', array(), $off), array('1' => true));
check('éteindre', airtonbe::dpsForAction('power_off', array(), $on), array('1' => false));
check('consigne 22 → 220', airtonbe::dpsForAction('target_set', array('slider' => '22'), $on), array('2' => 220));
check('consigne 21,6 arrondie → 220', airtonbe::dpsForAction('target_set', array('slider' => 21.6), $on), array('2' => 220));
check('consigne 40 bornée → 310', airtonbe::dpsForAction('target_set', array('slider' => 40), $on), array('2' => 310));
check('consigne 5 bornée → 160', airtonbe::dpsForAction('target_set', array('slider' => 5), $on), array('2' => 160));
check('ventilation', airtonbe::dpsForAction('fan_set', array('select' => 'mute'), $on), array('5' => 'mute'));
check('balayage vertical position 3 (chaîne)', airtonbe::dpsForAction('swing_v_set', array('select' => '3'), $on), array('107' => '3'));
check('minuterie 2 h (chaîne)', airtonbe::dpsForAction('timer_set', array('select' => '2'), $on), array('21' => '2'));
check('ECO en froid', airtonbe::dpsForAction('eco_on', array(), $on), array('8' => true));
check('ECO off en chaud accepté', airtonbe::dpsForAction('eco_off', array(), $heat), array('8' => false));
throws('ECO en chaud refusé', function () use ($heat) { airtonbe::dpsForAction('eco_on', array(), $heat); }, 'Froid');
throws('mode inconnu refusé (« hot » n\'est pas Airton)', function () use ($on) { airtonbe::dpsForAction('mode_set', array('select' => 'hot'), $on); }, 'hot');
check('DP bruts', airtonbe::dpsForAction('send_dps', array('message' => '{"1":true,"4":"cold","2":220}'), $on), array('1' => true, '4' => 'cold', '2' => 220));
throws('DP bruts : liste refusée', function () use ($on) { airtonbe::dpsForAction('send_dps', array('message' => '[true]'), $on); }, 'objet JSON');
throws('DP bruts : clé non numérique refusée', function () use ($on) { airtonbe::dpsForAction('send_dps', array('message' => '{"power":true}'), $on); }, 'power');
check('info en lecture seule : aucun ordre', airtonbe::dpsForAction('temperature_set', array('slider' => 3), $on), array());

section('Commandes');
cmd::reset();
$eq = new airtonbe();
$eq->id = 12;
eqLogic::$saved_eqs[12] = $eq;
$eq->createCommands();
$before = count(cmd::$table);
check('avant le premier relevé : commandes de base', $eq->getCmd('action', 'mode_set') !== null && $eq->getCmd('action', 'eco_on') === null, true);
$eq->ingest($state, true);
check('après relevé : DP publiés mémorisés', $eq->getConfiguration('dps_seen'), '1,2,3,4,5,8,9,12,13,15,20,22,101,102,105,106,107,108,109,110,111,112,113,115');
check('après relevé : ECO créé', $eq->getCmd('action', 'eco_on') !== null, true);
check('après relevé : pas de minuterie réglable (DP 21 absent)', $eq->getCmd('action', 'timer_set'), null);
check('valeur publiée : consigne', $eq->published['target'], 16.0);
check('valeur publiée : défaut', $eq->published['fault'], 0);
$sel = $eq->getCmd('action', 'mode_set');
check('liste des modes Airton', $sel->getConfiguration('listValue'), 'auto|Auto;cold|Froid;wet|Déshumidification;heat|Chauffage;fan|Ventilation');
check('sélecteur de mode lié à l\'info', $sel->getValue(), $eq->getCmd('info', 'mode')->getId());
check('curseur de consigne 16–31', array($eq->getCmd('action', 'target_set')->getConfiguration('minValue'), $eq->getCmd('action', 'target_set')->getConfiguration('maxValue')), array(16, 31));
$n = count(cmd::$table);
$eq->createCommands();
check('recréer ne duplique rien', count(cmd::$table), $n);
$eq->ingest(array('1' => true));
check('changement poussé : état fusionné', $eq->getCache('dps')['1'] === true && $eq->getCache('dps')['2'] === 160, true);
check('changement poussé : publié', $eq->published['power'], 1);
$eq->ingest(array('21' => '3'));
check('DP apparu plus tard : commande créée', $eq->getCmd('action', 'timer_set') !== null, true);
$eq->ingest($state, true);
check('relevé complet : DP 21 (poussé seulement) gardé', $eq->getCache('dps')['21'], '3');

section('Tuile');
$visible = array();
foreach (cmd::$table as $c) {
    if ($c->isVisible) { $visible[$c->order] = $c->name; }
}
ksort($visible);
check('commandes visibles, dans l\'ordre', array_values($visible), array('Marche', 'Arrêt', 'Température', 'Consigne', 'Mode', 'Ventilation',
    'Balayage vertical', 'Balayage horizontal', 'ECO On', 'ECO Off', 'Nuit On', 'Nuit Off', 'Affichage On', 'Affichage Off', 'Rafraîchir'));
check('interrupteur : widget binarySwitch', $eq->getCmd('action', 'power_on')->template['dashboard'], 'core::binarySwitch');
check('info d\'une valeur réglable cachée', $eq->getCmd('info', 'target')->isVisible, 0);
check('ordre fixe : minuterie après les interrupteurs', $eq->getCmd('info', 'timer')->order > $eq->getCmd('action', 'display_off')->order, true);
check('unité en lecture seule', $eq->getCmd('action', 'unit_set'), null);
check('apostrophe gardée dans les noms', $eq->getCmd('info', 'aux_heat')->name, 'Chauffage d’appoint');

section('Événements');
$before = $eq->events;
$eq->ingest($state, true);
$eq->ingest($state, true);
$repeat = $eq->events - $before;
$eq->ingest(array('20' => 2));
check('codes défaut vides : pas republiés à chaque relevé', array_key_exists('fault_codes', $eq->published) && $repeat === 2 * (count(airtonbe::valuesFromDps($state)) - 1), true);
check('code défaut apparu : publié', $eq->published['fault_codes'], 'E4');
check('valeur non numérique ignorée', array_key_exists('temperature', airtonbe::valuesFromDps(array('3' => 'n/a'))), false);

printf("\n%d réussi(s), %d échec(s)\n", $passed, $failed);

/* Le démon, de bout en bout, dans un processus à part (code 2 : ignoré, faute
 * de pouvoir écouter sur 127.0.0.2:6668). */
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/daemon.php'), $rc);
exit(($failed === 0 && ($rc === 0 || $rc === 2)) ? 0 : 1);
