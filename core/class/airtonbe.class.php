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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/airtonbeTuya.class.php';

/*
 * Pilotage local des climatiseurs Airton à module Wi-Fi Tuya.
 *
 * Le climatiseur parle le protocole local Tuya 3.3 (airtonbeTuya) : aucun
 * cloud, aucun compte, seulement l'adresse IP, l'identifiant de l'appareil et
 * sa clé locale. L'appareil n'accepte qu'un client TCP à la fois : le démon
 * garde cette connexion, reçoit les changements que la clim pousse d'elle-même
 * (télécommande, appli…), et c'est par lui que passent les ordres. Démon
 * arrêté, le cron relève chaque minute et les ordres ouvrent une connexion
 * courte.
 *
 * Les fonctions de la clim sont des « DP » numérotés. PROFILE décrit ceux des
 * Airton (monosplit 409730 et famille, clé produit keyquxnsj75xc8se). Une
 * commande n'est créée que pour les DP que l'appareil publie réellement ; un
 * DP inconnu devient une info cachée « DP n », pour ne rien perdre.
 *
 * Deux particularités Airton, qui cassent les intégrations génériques :
 *   - le mode chaud vaut « heat » et la ventilation « fan », pas « hot » et
 *     « wind » comme dans la norme Tuya ;
 *   - allumer ET changer de mode doit partir dans une seule trame : envoyés
 *     séparément, le mode est ignoré (localtuya #1115, #1848, #2003).
 */
class airtonbe extends eqLogic {

    /* ============================================================= RÉGLAGES */

    const DEFAULT_PORT = 55133;

    /* Un appareil injoignable depuis ce nombre de relevés est signalé une
     * fois dans le journal, puis n'est plus relevé qu'une minute sur cinq. */
    const OFFLINE_AFTER = 3;

    /* Le cron de tous les plugins passe dans un seul processus : le relevé de
     * secours ne doit pas y faire attendre les autres. */
    const CRON_BUDGET = 15;
    const CRON_TIMEOUT = 3;

    const TARGET_MIN = 16;
    const TARGET_MAX = 31;

    /*
     * DP des Airton.
     *   key   : base des identifiants logiques ;
     *   order : rang sur la tuile, fixe, pour qu'un DP apparu tard (la
     *           minuterie ne remonte qu'à son changement) ne décale rien ;
     *   name  : nom de l'info ; set : nom de l'action d'une valeur réglable ;
     *   write : la clim accepte l'écriture ; scale : diviseur de la valeur brute.
     * Les noms évitent l'apostrophe droite, que le coeur retire des noms de
     * commande.
     */
    const PROFILE = array(
        1   => array('key' => 'power', 'order' => 1, 'name' => 'État', 'type' => 'bool', 'write' => true, 'visible' => 1, 'generic' => 'ENERGY_STATE',
                     'on' => 'Marche', 'off' => 'Arrêt', 'on_generic' => 'ENERGY_ON', 'off_generic' => 'ENERGY_OFF'),
        3   => array('key' => 'temperature', 'order' => 2, 'name' => 'Température', 'type' => 'value', 'scale' => 10, 'unit' => '°C',
                     'visible' => 1, 'hist' => 1, 'generic' => 'THERMOSTAT_TEMPERATURE'),
        2   => array('key' => 'target', 'order' => 3, 'name' => 'État consigne', 'type' => 'value', 'write' => true, 'scale' => 10, 'unit' => '°C',
                     'min' => self::TARGET_MIN, 'max' => self::TARGET_MAX, 'visible' => 1, 'hist' => 1,
                     'generic' => 'THERMOSTAT_SETPOINT', 'set_generic' => 'THERMOSTAT_SET_SETPOINT', 'set' => 'Consigne'),
        4   => array('key' => 'mode', 'order' => 4, 'name' => 'État mode', 'type' => 'enum', 'write' => true, 'visible' => 1,
                     'values' => array('auto' => 'Auto', 'cold' => 'Froid', 'wet' => 'Déshumidification', 'heat' => 'Chauffage', 'fan' => 'Ventilation'),
                     'generic' => 'THERMOSTAT_MODE', 'set_generic' => 'THERMOSTAT_SET_MODE', 'set' => 'Mode'),
        5   => array('key' => 'fan', 'order' => 5, 'name' => 'État ventilation', 'type' => 'enum', 'write' => true, 'visible' => 1,
                     'values' => array('auto' => 'Auto', 'mute' => 'Silence', 'low' => 'Basse', 'low_mid' => 'Moyenne-basse', 'mid' => 'Moyenne',
                                       'mid_high' => 'Moyenne-haute', 'high' => 'Haute', 'turbo' => 'Turbo'),
                     'set' => 'Ventilation'),
        107 => array('key' => 'swing_v', 'order' => 6, 'name' => 'État balayage vertical', 'type' => 'enum', 'write' => true, 'visible' => 1,
                     'values' => array('off' => 'Arrêt', '15' => 'Balayage', '1' => 'Position 1 (haut)', '2' => 'Position 2', '3' => 'Position 3',
                                       '4' => 'Position 4', '5' => 'Position 5 (bas)'),
                     'set' => 'Balayage vertical'),
        106 => array('key' => 'swing_h', 'order' => 7, 'name' => 'État balayage horizontal', 'type' => 'enum', 'write' => true, 'visible' => 1,
                     'values' => array('off' => 'Arrêt', 'same' => 'Même sens', 'opposite' => 'Sens opposés'),
                     'set' => 'Balayage horizontal'),
        8   => array('key' => 'eco', 'order' => 8, 'name' => 'ECO', 'type' => 'bool', 'write' => true, 'visible' => 1),
        109 => array('key' => 'sleep', 'order' => 9, 'name' => 'Nuit', 'type' => 'bool', 'write' => true, 'visible' => 1),
        13  => array('key' => 'display', 'order' => 10, 'name' => 'Affichage', 'type' => 'bool', 'write' => true, 'visible' => 1),
        108 => array('key' => 'swing_3d', 'order' => 11, 'name' => 'Balayage 3D', 'type' => 'bool', 'write' => true),
        110 => array('key' => 'health', 'order' => 12, 'name' => 'Santé (ioniseur)', 'type' => 'bool', 'write' => true),
        111 => array('key' => 'clean', 'order' => 13, 'name' => 'Auto-nettoyage', 'type' => 'bool', 'write' => true),
        115 => array('key' => 'frost', 'order' => 14, 'name' => 'Hors-gel 8 °C', 'type' => 'bool', 'write' => true),
        12  => array('key' => 'aux_heat', 'order' => 15, 'name' => 'Chauffage d’appoint', 'type' => 'bool', 'write' => true),
        9   => array('key' => 'drying', 'order' => 16, 'name' => 'Séchage anti-moisissure', 'type' => 'bool', 'write' => true),
        15  => array('key' => 'swing', 'order' => 17, 'name' => 'État balayage', 'type' => 'enum', 'write' => true,
                     'values' => array('off' => 'Arrêt', 'un_down' => 'Haut-bas', 'left_right' => 'Gauche-droite', 'all' => 'Complet'),
                     'set' => 'Balayage'),
        21  => array('key' => 'timer', 'order' => 18, 'name' => 'État minuterie', 'type' => 'enum', 'write' => true, 'values' => 'timer', 'set' => 'Minuterie'),
        22  => array('key' => 'timer_left', 'order' => 19, 'name' => 'Minuterie restante', 'type' => 'value', 'unit' => 'min'),
        114 => array('key' => 'current_mode', 'order' => 20, 'name' => 'Mode effectif', 'type' => 'enum',
                     'values' => array('cold' => 'Froid', 'wet' => 'Déshumidification', 'heat' => 'Chauffage', 'fan' => 'Ventilation')),
        20  => array('key' => 'fault', 'order' => 21, 'type' => 'bitmap',
                     'bits' => array('CL', 'E4', 'E5', 'H6', 'H9', 'HE', 'L0', 'L1', 'L2', 'L3', 'L6', 'L7', 'L8', 'L9', 'LA', 'Ld',
                                     'P0', 'P1', 'P6', 'P8', 'PA', 'PC', 'Pd', 'PE')),
        113 => array('key' => 'fault', 'order' => 21, 'type' => 'bitmap',
                     'bits' => array('PF', 'SC', 'U0', 'U1', 'U2', 'U3', 'U4', 'U5', 'U6', 'U7', 'U8', 'U9', 'UC', 'Ud', 'E1', 'E2')),
        103 => array('key' => 'energy', 'order' => 22, 'name' => 'Consommation', 'type' => 'value', 'scale' => 10, 'unit' => 'kWh', 'hist' => 1,
                     'generic' => 'CONSUMPTION'),
        102 => array('key' => 'usage_time', 'order' => 23, 'name' => 'Durée d’utilisation', 'type' => 'value', 'unit' => 'h'),
        /* L'unité reste en lecture : en °F, la consigne changerait d'échelle
         * et le curseur 16–31 n'aurait plus de sens. */
        105 => array('key' => 'unit', 'order' => 24, 'name' => 'Unité', 'type' => 'enum', 'values' => array('c' => '°C', 'f' => '°F')),
        112 => array('key' => 'ac_type', 'order' => 25, 'name' => 'Type', 'type' => 'enum', 'values' => array('cold' => 'Froid seul', 'cold_heat' => 'Réversible')),
        101 => array('key' => 'usage_reports', 'order' => 26, 'name' => 'Remontées de durée', 'type' => 'value'),
        104 => array('key' => 'energy_reports', 'order' => 27, 'name' => 'Remontées de consommation', 'type' => 'value'),
    );

    /* DP dont les commandes existent avant le premier relevé. */
    const CORE_DPS = array(1, 2, 3, 4, 5);

    /* ================================================================ CRON */

    /* Relevé de secours quand le démon ne tourne pas. Le démon, lui, reçoit
     * les changements en direct et relit l'état complet toutes les cinq
     * minutes. */
    public static function cron() {
        if (self::deamon_info()['state'] == 'ok') {
            return;
        }
        $slowTurn = ((int) date('i')) % 5 === 0;
        $start = time();
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            if (!$eqLogic->isConfigured()) {
                continue;
            }
            if ((int) $eqLogic->getCache('failures', 0) >= self::OFFLINE_AFTER && !$slowTurn) {
                continue;
            }
            if (time() - $start > self::CRON_BUDGET) {
                break;
            }
            try {
                $eqLogic->pollDirect(self::CRON_TIMEOUT);
            } catch (Throwable $e) {
                $eqLogic->noteFailure($e->getMessage());
            }
        }
    }

    public static function health() {
        $devices = array_filter(self::byType(__CLASS__, true), function ($eq) { return $eq->isConfigured(); });
        $offline = array_filter($devices, function ($eq) { return (int) $eq->getCache('failures', 0) >= self::OFFLINE_AFTER; });
        $faulty = array_filter($devices, function ($eq) { return (string) $eq->getCache('fault_codes', '') !== ''; });
        $names = function ($_list) {
            return htmlspecialchars(implode(', ', array_map(function ($eq) { return $eq->getHumanName(); }, $_list)), ENT_QUOTES);
        };
        return array(
            array(
                'test'   => __('Climatiseurs joignables', __FILE__),
                'result' => (count($devices) - count($offline)) . '/' . count($devices) . (empty($offline) ? '' : ' — ' . $names($offline)),
                'advice' => empty($offline) ? '' : __('Vérifiez l\'alimentation, le Wi-Fi et l\'adresse IP ; un autre client connecté à la clim (Home Assistant…) empêche aussi la connexion.', __FILE__),
                'state'  => empty($offline),
            ),
            array(
                'test'   => __('Codes défaut', __FILE__),
                'result' => empty($faulty) ? __('Aucun', __FILE__) : $names($faulty),
                'advice' => empty($faulty) ? '' : __('Voir la commande « Codes défaut » de ces climatiseurs et la notice Airton.', __FILE__),
                'state'  => empty($faulty),
            ),
        );
    }

    /* =============================================================== DÉMON */

    public static function deamon_info() {
        $return = array('log' => __CLASS__ . 'd', 'state' => 'nok', 'launchable' => 'ok');
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if ($pid != '' && @posix_getsid((int) $pid)) {
                $return['state'] = 'ok';
            } else {
                @unlink($pid_file);
            }
        }
        /* Un démon vivant qui ne joint plus Jeedom est déclaré arrêté : la
         * gestion automatique le relance, le cron prend le relais. Il relit
         * sa configuration au moins chaque minute. */
        if ($return['state'] == 'ok' && time() - (int) @filemtime($pid_file) > 120
            && time() - (int) cache::byKey('airtonbe::daemon_seen')->getValue(0) > 150) {
            $return['state'] = 'nok';
        }
        return $return;
    }

    public static function deamon_start() {
        self::deamon_stop();
        $daemon = realpath(__DIR__ . '/../../resources/airtonbed/airtonbed.php');
        $cmd  = 'php ' . escapeshellarg($daemon);
        $cmd .= ' --callback ' . escapeshellarg(self::getCallbackUrl());
        $cmd .= ' --pid ' . escapeshellarg(jeedom::getTmpFolder(__CLASS__) . '/deamon.pid');
        $cmd .= ' --stamp ' . escapeshellarg(self::stampFile());
        $cmd .= ' --port ' . (int) self::daemonPort();
        cache::set('airtonbe::daemon_port', self::daemonPort());
        $cmd .= ' --loglevel ' . escapeshellarg(log::convertLogLevel(log::getLogLevel(__CLASS__)));
        $cmd .= ' --timezone ' . escapeshellarg(date_default_timezone_get());

        /* La clé API passe par un fichier lisible du seul www-data, effacé par
         * le démon dès lu : ps montre les arguments à tout utilisateur. */
        $keyFile = jeedom::getTmpFolder(__CLASS__) . '/daemon.key';
        @unlink($keyFile);
        $old = umask(0077);
        file_put_contents($keyFile, jeedom::getApiKey(__CLASS__));
        umask($old);
        $cmd .= ' --keyfile ' . escapeshellarg($keyFile);
        log::add(__CLASS__, 'info', __('Lancement du démon', __FILE__));
        exec($cmd . ' >> ' . log::getPathToLog(__CLASS__ . 'd') . ' 2>&1 &');

        for ($i = 1; $i <= 20; $i++) {
            if (self::deamon_info()['state'] == 'ok') {
                message::removeAll(__CLASS__, 'unableStartDeamon');
                return true;
            }
            sleep(1);
        }
        log::add(__CLASS__, 'error', __('Le démon n\'a pas démarré. Consultez le journal', __FILE__) . ' ' . __CLASS__ . 'd.');
        return false;
    }

    public static function deamon_stop() {
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if ($pid != '') {
                system::kill($pid);
            }
            @unlink($pid_file);
        }
        system::kill('resources/airtonbed/airtonbed.php');
        return true;
    }

    public static function daemonPort() {
        $port = (int) config::byKey('daemon_port', __CLASS__, self::DEFAULT_PORT);
        return ($port > 1024 && $port < 65536) ? $port : self::DEFAULT_PORT;
    }

    public static function getCallbackUrl() {
        return network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp')
             . '/plugins/airtonbe/core/php/jeeAirtonbe.php';
    }

    public static function stampFile() {
        return jeedom::getTmpFolder(__CLASS__) . '/config.stamp';
    }

    /* Le démon relit sa configuration quand le contenu de ce fichier change. */
    public static function notifyDaemon() {
        @file_put_contents(self::stampFile(), sprintf('%.6f', microtime(true)));
    }

    /* Ce que le démon doit tenir : une connexion par climatiseur configuré. */
    public static function daemonConfig() {
        $devices = array();
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            if (!$eqLogic->isConfigured()) {
                continue;
            }
            $devices[] = array(
                'eq'    => (int) $eqLogic->getId(),
                'name'  => $eqLogic->getHumanName(),
                'ip'    => $eqLogic->getConfiguration('ip'),
                'devId' => $eqLogic->getConfiguration('dev_id'),
                'key'   => $eqLogic->getConfiguration('local_key'),
            );
        }
        return array('devices' => $devices);
    }

    /* État poussé par le démon : DP reçus, connexion ouverte ou perdue. */
    public function ingestDaemon($_data) {
        if (isset($_data['online'])) {
            $this->setCache('daemon_link', (int) $_data['online'] === 1 ? time() : 0);
            if ((int) $_data['online'] === 1) {
                $this->clearFailure();
            } else {
                $this->noteFailure(isset($_data['error']) ? (string) $_data['error'] : __('connexion perdue', __FILE__));
            }
        }
        if (isset($_data['dps']) && is_array($_data['dps'])) {
            $this->ingest($_data['dps'], !empty($_data['full']));
        }
    }

    /* Connexion directe ouverte : le démon le signale aussitôt qu'elle
     * s'ouvre ou tombe, et le redit à chaque relecture de configuration. */
    public function isLive() {
        if ((int) $this->getCache('daemon_link', 0) === 0) {
            return false;
        }
        $live = cache::byKey('airtonbe::live')->getValue(array());
        /* Un démon qui ne donne plus signe de vie n'est plus connecté à rien. */
        return is_array($live) && isset($live['at']) && time() - (int) $live['at'] < 150;
    }

    /* ======================================================== CYCLE DE VIE */

    /* Aucune exception ici : le coeur crée l'équipement avec son seul nom. */
    public function preSave() {
        if ($this->getId() == '') {
            $this->setIsEnable(1);
            $this->setIsVisible(1);
        }
        foreach (array('ip', 'dev_id', 'local_key') as $key) {
            /* Un espace en bord vient d'un copier-coller. */
            $this->setConfiguration($key, trim((string) $this->getConfiguration($key, '')));
        }
        if ($this->getConfiguration('dev_id') !== '') {
            $this->setLogicalId($this->getConfiguration('dev_id'));
        }
    }

    public function postSave() {
        $this->createCommands();
        $sig = md5($this->getConfiguration('ip') . '|' . $this->getConfiguration('dev_id') . '|' . $this->getConfiguration('local_key'));
        if ($this->getCache('conn_sig', '') !== $sig) {
            $this->setCache('conn_sig', $sig);
            $this->setCache('failures', 0);
            $this->setCache('problem', '');
        }
        self::notifyDaemon();
    }

    public function postRemove() {
        self::notifyDaemon();
    }

    /* Appelée à chaque enregistrement de la configuration, même sans
     * changement : on ne relance le démon que si son port a changé. */
    public static function postConfig_daemon_port($_value) {
        if (self::deamon_info()['state'] == 'ok' && (int) cache::byKey('airtonbe::daemon_port')->getValue(0) !== self::daemonPort()) {
            self::deamon_start();
        }
    }

    public function isConfigured() {
        return $this->getConfiguration('ip', '') !== '' && $this->getConfiguration('dev_id', '') !== ''
            && strlen((string) $this->getConfiguration('local_key', '')) === 16;
    }

    /* ============================================================ DÉCOUVERTE */

    public static function discover($_seconds = 6) {
        $found = array();
        foreach (airtonbeTuya::discover($_seconds) as $gwId => $info) {
            $known = self::byLogicalId($gwId, __CLASS__);
            $found[] = array(
                'gwId'       => $gwId,
                'ip'         => isset($info['ip']) ? $info['ip'] : '',
                'productKey' => isset($info['productKey']) ? $info['productKey'] : '',
                'version'    => isset($info['version']) ? $info['version'] : '',
                'airton'     => isset($info['productKey']) && $info['productKey'] === 'keyquxnsj75xc8se',
                'known'      => is_object($known) ? $known->getHumanName() : '',
                'knownId'    => is_object($known) ? $known->getId() : '',
            );
        }
        return $found;
    }

    /* Crée l'équipement d'un appareil trouvé, ou met son adresse à jour. La
     * connexion est essayée avant l'enregistrement : une clé fausse est
     * signalée tout de suite. */
    public static function createFromDiscovery($_gwId, $_ip, $_key, $_name, $_productKey = '') {
        $_gwId = trim((string) $_gwId);
        $_key = trim((string) $_key);
        if ($_gwId === '' || $_ip === '') {
            throw new Exception(__('Identifiant ou adresse IP manquant.', __FILE__));
        }
        $eqLogic = self::byLogicalId($_gwId, __CLASS__);
        if ($_key === '' && is_object($eqLogic)) {
            $_key = $eqLogic->getConfiguration('local_key');
        }
        if (strlen($_key) !== 16) {
            throw new Exception(__('La clé locale doit faire exactement 16 caractères.', __FILE__));
        }
        /* Un appareil que le démon tient déjà n'accepterait pas une seconde
         * connexion : sa clé a fait ses preuves, l'état arrivera par le démon. */
        $dps = (is_object($eqLogic) && $eqLogic->isLive() && $_key === $eqLogic->getConfiguration('local_key'))
            ? null : airtonbeTuya::exchange($_ip, $_gwId, $_key);
        if (!is_object($eqLogic)) {
            $eqLogic = new airtonbe();
            $eqLogic->setEqType_name(__CLASS__);
            $name = trim((string) $_name) !== '' ? trim((string) $_name) : __('Climatisation', __FILE__);
            $base = $name;
            /* Unicité (name, object_id) en base, tous plugins confondus :
             * l'équipement naît sans objet parent. */
            for ($i = 2; self::nameTaken($name); $i++) {
                $name = $base . ' ' . $i;
            }
            $eqLogic->setName($name);
        }
        $eqLogic->setConfiguration('ip', $_ip);
        $eqLogic->setConfiguration('dev_id', $_gwId);
        $eqLogic->setConfiguration('local_key', $_key);
        if ($_productKey !== '') {
            $eqLogic->setConfiguration('product_key', $_productKey);
        }
        $eqLogic->save();
        if ($dps !== null) {
            $eqLogic->ingest($dps, true);
        }
        return $eqLogic;
    }

    /* Unicité (name, object_id) de la table, tous plugins confondus. Requête
     * directe : eqLogic::byObjectNameEqLogicName() compare le nom d'objet à
     * « Aucun » traduit, et rate le cas hors français. */
    private static function nameTaken($_name) {
        $row = DB::Prepare('SELECT COUNT(*) AS n FROM eqLogic WHERE name=:name AND object_id IS NULL', array('name' => $_name), DB::FETCH_TYPE_ROW);
        return is_array($row) && (int) $row['n'] > 0;
    }

    /* =============================================================== RELEVÉ */

    /* Démon actif, c'est toujours lui qui relit : la clim n'accepterait pas
     * une seconde connexion. On ne se connecte soi-même que s'il ne connaît
     * pas encore l'appareil (configuration pas encore relue). */
    public function pollNow() {
        if (self::deamon_info()['state'] == 'ok' && $this->daemonRequest(array('refresh' => 1))) {
            return;
        }
        $this->pollDirect();
    }

    public function pollDirect($_timeout = 5) {
        if (!$this->isConfigured()) {
            throw new Exception(__('Climatiseur non configuré : adresse IP, identifiant et clé locale (16 caractères) sont nécessaires.', __FILE__));
        }
        $dps = airtonbeTuya::exchange($this->getConfiguration('ip'), $this->getConfiguration('dev_id'), $this->getConfiguration('local_key'), null, $_timeout);
        $this->clearFailure();
        $this->ingest($dps, true);
    }

    /*
     * DP reçus, complets (relevé) ou partiels (changement poussé). Les DP
     * s'accumulent dans le cache : un changement poussé ne contient que ce
     * qui a changé.
     */
    public function ingest($_dps, $_full = false) {
        $all = $_full ? array() : (array) $this->getCache('dps', array());
        foreach ($_dps as $dp => $value) {
            $all[(string) $dp] = $value;
        }
        if ($_full) {
            /* Les DP qui ne remontent qu'à leur changement (minuterie…) sont
             * gardés d'un relevé complet à l'autre. */
            $all = $all + (array) $this->getCache('dps', array());
        }
        $this->setCache('dps', $all);
        $this->setCache('dps_at', date('Y-m-d H:i:s'));

        $seen = $this->seenDps();
        $new = array_diff(array_map('intval', array_keys($_dps)), $seen);
        if (!empty($new)) {
            $seen = array_values(array_unique(array_merge($seen, $new)));
            sort($seen);
            $this->setConfiguration('dps_seen', implode(',', $seen));
            /* Écriture sur une copie fraîche : cet objet peut dater du début
             * d'un cron, et réécrire toute sa ligne rendrait une adresse IP
             * modifiée entre-temps. */
            $fresh = self::byId($this->getId());
            if (is_object($fresh)) {
                $fresh->setConfiguration('dps_seen', implode(',', $seen));
                $fresh->save(true);
            }
            $this->createCommands();
        }
        foreach (self::valuesFromDps($_dps, $all) as $logicalId => $value) {
            if ($logicalId === 'fault_codes') {
                /* Une valeur vide n'est jamais vue comme inchangée par le
                 * coeur : elle relancerait un événement à chaque relevé. */
                if ($this->getCache('fault_codes', null) === $value) {
                    continue;
                }
                $this->setCache('fault_codes', $value);
                if ($value !== '') {
                    log::add(__CLASS__, 'warning', $this->getHumanName() . ' : ' . __('code(s) défaut', __FILE__) . ' ' . $value);
                }
            }
            $this->publishCmd($logicalId, $value);
        }
    }

    public function seenDps() {
        $raw = trim((string) $this->getConfiguration('dps_seen', ''));
        return $raw === '' ? array() : array_map('intval', explode(',', $raw));
    }

    /*
     * Valeurs des commandes info tirées des DP reçus. $_all, l'état complet
     * connu, sert aux valeurs qui en combinent plusieurs (codes défaut).
     */
    public static function valuesFromDps($_dps, $_all = null) {
        $_all = $_all === null ? $_dps : $_all;
        $values = array();
        $fault = false;
        foreach ($_dps as $dp => $raw) {
            $dp = (int) $dp;
            if (!isset(self::PROFILE[$dp])) {
                $values['dp_' . $dp] = is_bool($raw) ? (int) $raw : (is_scalar($raw) ? $raw : json_encode($raw));
                continue;
            }
            $def = self::PROFILE[$dp];
            switch ($def['type']) {
                case 'bool':
                    $values[$def['key']] = $raw ? 1 : 0;
                    break;
                case 'value':
                    if (!is_numeric($raw)) {
                        break;
                    }
                    $values[$def['key']] = isset($def['scale']) ? round($raw / $def['scale'], 1) : $raw;
                    break;
                case 'enum':
                    $values[$def['key']] = (string) $raw;
                    break;
                case 'bitmap':
                    $fault = true;
                    break;
            }
        }
        if ($fault) {
            $codes = self::faultCodes(isset($_all['20']) ? $_all['20'] : 0, isset($_all['113']) ? $_all['113'] : 0);
            $values['fault'] = empty($codes) ? 0 : 1;
            $values['fault_codes'] = implode(', ', $codes);
        }
        return $values;
    }

    public static function faultCodes($_fault1, $_fault2) {
        $codes = array();
        foreach (array(20 => (int) $_fault1, 113 => (int) $_fault2) as $dp => $bits) {
            foreach (self::PROFILE[$dp]['bits'] as $i => $code) {
                if ($bits & (1 << $i)) {
                    $codes[] = $code;
                }
            }
        }
        return $codes;
    }

    /* ============================================================= COMMANDES */

    public static function enumValues($_def) {
        if ($_def['values'] === 'timer') {
            $values = array('0' => __('Aucune', __FILE__));
            for ($h = 1; $h <= 24; $h++) {
                $values[(string) $h] = $h . ' h';
            }
            return $values;
        }
        return $_def['values'];
    }

    public static function listValue($_values) {
        $items = array();
        foreach ($_values as $value => $label) {
            $items[] = $value . '|' . $label;
        }
        return implode(';', $items);
    }

    /*
     * Sur la tuile, une valeur réglable n'apparaît qu'une fois : le curseur
     * ou la liste affichent l'état de l'info liée, et un interrupteur
     * (core::binarySwitch) ne montre que celle de ses deux commandes qui
     * s'applique. L'info reste disponible pour les scénarios et l'historique.
     */
    public function createCommands() {
        $dps = array_unique(array_merge(self::CORE_DPS, $this->seenDps()));
        $this->addCmdIfMissing('online', 'En ligne', 'info', 'binary', array('order' => 0));
        foreach ($dps as $dp) {
            if (!isset(self::PROFILE[$dp])) {
                $this->addCmdIfMissing('dp_' . $dp, 'DP ' . $dp, 'info', 'string', array('order' => 1000 + $dp));
                continue;
            }
            $def = self::PROFILE[$dp];
            $key = $def['key'];
            $visible = isset($def['visible']) ? $def['visible'] : 0;
            $base = 10 * $def['order'];
            if ($def['type'] === 'bitmap') {
                $this->addCmdIfMissing('fault', 'Défaut', 'info', 'binary', array('order' => $base));
                $this->addCmdIfMissing('fault_codes', 'Codes défaut', 'info', 'string', array('order' => $base + 1));
                continue;
            }
            $writable = !empty($def['write']);
            $subType = array('bool' => 'binary', 'value' => 'numeric', 'enum' => 'string')[$def['type']];
            $info = $this->addCmdIfMissing($key, $def['name'], 'info', $subType, array(
                'order' => $base, 'isVisible' => $writable ? 0 : $visible,
                'isHistorized' => isset($def['hist']) ? $def['hist'] : 0,
                'unite' => isset($def['unit']) ? $def['unit'] : '', 'generic' => isset($def['generic']) ? $def['generic'] : '',
            ));
            if (!$writable) {
                continue;
            }
            switch ($def['type']) {
                case 'bool':
                    /* Noms en « … On » / « … Off » (ou Marche / Arrêt) : c'est
                     * ce que le widget interrupteur reconnaît. */
                    $this->addCmdIfMissing($key . '_on', isset($def['on']) ? $def['on'] : $def['name'] . ' On', 'action', 'other',
                        array('order' => $base + 1, 'isVisible' => $visible, 'value' => $info, 'template' => 'core::binarySwitch',
                              'generic' => isset($def['on_generic']) ? $def['on_generic'] : ''));
                    $this->addCmdIfMissing($key . '_off', isset($def['off']) ? $def['off'] : $def['name'] . ' Off', 'action', 'other',
                        array('order' => $base + 2, 'isVisible' => $visible, 'value' => $info, 'template' => 'core::binarySwitch',
                              'generic' => isset($def['off_generic']) ? $def['off_generic'] : ''));
                    break;
                case 'value':
                    $this->addCmdIfMissing($key . '_set', $def['set'], 'action', 'slider',
                        array('order' => $base + 1, 'isVisible' => $visible, 'value' => $info, 'min' => $def['min'], 'max' => $def['max'],
                              'unite' => isset($def['unit']) ? $def['unit'] : '', 'generic' => isset($def['set_generic']) ? $def['set_generic'] : ''));
                    break;
                case 'enum':
                    $this->addCmdIfMissing($key . '_set', $def['set'], 'action', 'select',
                        array('order' => $base + 1, 'isVisible' => $visible, 'value' => $info, 'listValue' => self::listValue(self::enumValues($def)),
                              'generic' => isset($def['set_generic']) ? $def['set_generic'] : ''));
                    break;
            }
        }
        $this->addCmdIfMissing('refresh', 'Rafraîchir', 'action', 'other', array('order' => 900, 'isVisible' => 1));
        $this->addCmdIfMissing('send_dps', 'Envoyer des DP', 'action', 'message', array('order' => 901,
            'display' => array('title_disable' => 1, 'message_placeholder' => '{"1":true,"4":"cold","2":220}')));
    }

    private function addCmdIfMissing($_logicalId, $_name, $_type, $_subType, $_options = array()) {
        $cmd = $this->getCmd($_type, $_logicalId);
        if (is_object($cmd)) {
            return $cmd;
        }
        $cmd = new airtonbeCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_logicalId);
        /* Unicité (eqLogic_id, name) en base : un nom déjà pris ferait échouer
         * l'enregistrement de tout l'équipement. */
        $name = cleanComponanteName(__($_name, __FILE__));
        if (is_object(cmd::byEqLogicIdCmdName($this->getId(), $name))) {
            $name .= ' (' . $_logicalId . ')';
        }
        $cmd->setName($name);
        $cmd->setType($_type);
        $cmd->setSubType($_subType);
        $cmd->setIsVisible(isset($_options['isVisible']) ? $_options['isVisible'] : 0);
        $cmd->setIsHistorized(isset($_options['isHistorized']) ? $_options['isHistorized'] : 0);
        if (isset($_options['order']))      { $cmd->setOrder($_options['order']); }
        if (!empty($_options['unite']))     { $cmd->setUnite($_options['unite']); }
        if (!empty($_options['generic']))   { $cmd->setGeneric_type($_options['generic']); }
        if (isset($_options['min']))        { $cmd->setConfiguration('minValue', $_options['min']); }
        if (isset($_options['max']))        { $cmd->setConfiguration('maxValue', $_options['max']); }
        if (isset($_options['listValue']))  { $cmd->setConfiguration('listValue', $_options['listValue']); }
        if (isset($_options['value']) && is_object($_options['value'])) {
            $cmd->setValue($_options['value']->getId());
        }
        if (isset($_options['template'])) {
            $cmd->setTemplate('dashboard', $_options['template']);
            $cmd->setTemplate('mobile', $_options['template']);
        }
        foreach (isset($_options['display']) ? $_options['display'] : array() as $key => $value) {
            $cmd->setDisplay($key, $value);
        }
        $cmd->save();
        return $cmd;
    }

    public static function rebuildCommands() {
        foreach (self::byType(__CLASS__) as $eqLogic) {
            try {
                $eqLogic->createCommands();
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /* Écriture d'une valeur. Ce nom, et surtout pas setCmd() : utils::a2o()
     * appelle « set » + chaque clé du formulaire, et la page envoie « cmd ». */
    private function publishCmd($_logicalId, $_value) {
        if ($_value === null) {
            return;
        }
        $cmd = $this->getCmd('info', $_logicalId);
        if (!is_object($cmd)) {
            return;
        }
        /* Le coeur ne voit jamais une valeur vide comme inchangée. */
        if ($_value === '' && $cmd->execCmd() === '') {
            return;
        }
        $this->checkAndUpdateCmd($cmd, $_value);
    }

    /* =============================================================== ORDRES */

    public function runAction($_logicalId, $_options) {
        if ($_logicalId === 'refresh') {
            $this->pollNow();
            return;
        }
        $dps = self::dpsForAction($_logicalId, $_options, (array) $this->getCache('dps', array()));
        if (empty($dps)) {
            throw new Exception(__('Commande inconnue :', __FILE__) . ' ' . $_logicalId);
        }
        $this->sendDps($dps);
    }

    /*
     * DP à envoyer pour une commande action. $_state est le dernier état
     * connu de la clim, pour les règles qui en dépendent.
     */
    public static function dpsForAction($_logicalId, $_options, $_state) {
        if ($_logicalId === 'send_dps') {
            $dps = json_decode(isset($_options['message']) ? trim((string) $_options['message']) : '', true);
            if (!is_array($dps) || empty($dps) || array_keys($dps) === range(0, count($dps) - 1)) {
                throw new Exception(__('Attendu un objet JSON de DP, par exemple {"1":true,"4":"cold"}', __FILE__));
            }
            $out = array();
            foreach ($dps as $dp => $value) {
                if (!ctype_digit((string) $dp)) {
                    throw new Exception(__('Numéro de DP invalide :', __FILE__) . ' ' . $dp);
                }
                $out[(string) $dp] = $value;
            }
            return $out;
        }
        if (!preg_match('/^(.+)_(on|off|set)$/', $_logicalId, $m)) {
            return array();
        }
        $dp = null;
        foreach (self::PROFILE as $n => $def) {
            if ($def['key'] === $m[1] && !empty($def['write'])) {
                $dp = $n;
                break;
            }
        }
        if ($dp === null) {
            return array();
        }
        $def = self::PROFILE[$dp];
        $powered = !empty($_state['1']);
        $mode = isset($_state['4']) ? (string) $_state['4'] : '';

        if ($def['type'] === 'bool' && $m[2] !== 'set') {
            $on = $m[2] === 'on';
            if ($dp === 8 && $on && $mode !== '' && $mode !== 'cold') {
                throw new Exception(__('Le mode ECO n\'existe qu\'en mode Froid.', __FILE__));
            }
            return array((string) $dp => $on);
        }
        if ($def['type'] === 'value' && $m[2] === 'set') {
            $value = isset($_options['slider']) ? $_options['slider'] : null;
            if (!is_numeric($value)) {
                throw new Exception(__('Valeur de consigne manquante.', __FILE__));
            }
            $value = max($def['min'], min($def['max'], (int) round((float) $value)));
            return array((string) $dp => $value * (isset($def['scale']) ? $def['scale'] : 1));
        }
        if ($def['type'] === 'enum' && $m[2] === 'set') {
            $value = isset($_options['select']) ? (string) $_options['select'] : '';
            if (!array_key_exists($value, self::enumValues($def))) {
                throw new Exception(__('Valeur inconnue pour', __FILE__) . ' ' . $def['name'] . ' : ' . $value);
            }
            /* Clim éteinte : choisir un mode l'allume dans ce mode, dans la
             * même trame. Séparés, les Airton ignorent le mode. */
            if ($dp === 4 && !$powered) {
                return array('1' => true, '4' => $value);
            }
            return array((string) $dp => $value);
        }
        return array();
    }

    /* Envoie des DP, par le démon s'il tient la connexion, sinon par une
     * connexion courte. */
    public function sendDps($_dps) {
        if (!$this->isConfigured()) {
            throw new Exception(__('Climatiseur non configuré : adresse IP, identifiant et clé locale (16 caractères) sont nécessaires.', __FILE__));
        }
        log::add(__CLASS__, 'debug', $this->getHumanName() . ' → ' . airtonbeTuya::json(array('dps' => $_dps)));
        if (self::deamon_info()['state'] == 'ok' && $this->daemonRequest(array('dps' => $_dps))) {
            return;
        }
        $dps = airtonbeTuya::exchange($this->getConfiguration('ip'), $this->getConfiguration('dev_id'),
            $this->getConfiguration('local_key'), $_dps);
        $this->clearFailure();
        $this->ingest($dps, true);
    }

    /*
     * Une requête au démon, sur sa socket locale : une ligne JSON, une ligne
     * de réponse. L'état qui en résulte revient par le chemin habituel des
     * changements poussés.
     *
     * Rend false quand le démon ne connaît pas encore l'appareil (il n'a pas
     * relu sa configuration) : il n'a alors pas de connexion, l'appelant peut
     * ouvrir la sienne.
     *
     * La requête porte une échéance : un démon occupé qui la lirait après
     * l'abandon de Jeedom ne doit pas envoyer à la clim un ordre annoncé en
     * échec.
     */
    private function daemonRequest($_request) {
        $sock = @stream_socket_client('tcp://127.0.0.1:' . self::daemonPort(), $errno, $error, 3);
        if ($sock === false) {
            throw new Exception(__('Démon injoignable :', __FILE__) . ' ' . $error);
        }
        stream_set_timeout($sock, 10);
        $_request['apikey'] = jeedom::getApiKey(__CLASS__);
        $_request['eq'] = (int) $this->getId();
        $_request['until'] = microtime(true) + 3;
        fwrite($sock, json_encode($_request) . "\n");
        $line = fgets($sock);
        fclose($sock);
        $reply = is_string($line) ? json_decode($line, true) : null;
        if (!is_array($reply)) {
            throw new Exception(__('Pas de réponse du démon.', __FILE__));
        }
        if (!empty($reply['unknown'])) {
            return false;
        }
        if (empty($reply['ok'])) {
            throw new Exception(isset($reply['error']) ? (string) $reply['error'] : __('Échec de l\'ordre.', __FILE__));
        }
        return true;
    }

    /* ==================================================== ÉCHECS ET ÉTAT */

    public function noteFailure($_message) {
        $failures = (int) $this->getCache('failures', 0) + 1;
        $this->setCache('failures', $failures);
        $this->setCache('problem', $_message);
        $this->publishCmd('online', 0);
        /* Un seul avertissement par panne. */
        log::add(__CLASS__, $failures === self::OFFLINE_AFTER ? 'warning' : 'debug', $this->getHumanName() . ' : ' . $_message);
    }

    private function clearFailure() {
        $failures = (int) $this->getCache('failures', 0);
        if ($failures >= self::OFFLINE_AFTER) {
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . __('de nouveau joignable.', __FILE__));
        }
        if ($failures > 0) {
            $this->setCache('failures', 0);
            $this->setCache('problem', '');
        }
        $this->publishCmd('online', 1);
    }

    public function toAjax() {
        return array(
            'id'       => $this->getId(),
            'daemon'   => self::deamon_info()['state'],
            'live'     => $this->isLive(),
            'online'   => (int) $this->getCache('failures', 0) === 0 && $this->getCache('dps_at', '') !== '',
            'failures' => (int) $this->getCache('failures', 0),
            'problem'  => (string) $this->getCache('problem', ''),
            'dpsAt'    => (string) $this->getCache('dps_at', ''),
            'dps'      => (object) $this->getCache('dps', array()),
        );
    }
}

/* Obligatoire même réduite : sans elle, le coeur refuse de créer ou d'ouvrir
 * un équipement du plugin. */
class airtonbeCmd extends cmd {

    public function execute($_options = array()) {
        if ($this->getType() !== 'action') {
            return;
        }
        $eqLogic = $this->getEqLogic();
        if (is_object($eqLogic)) {
            $eqLogic->runAction($this->getLogicalId(), $_options);
        }
    }
}
