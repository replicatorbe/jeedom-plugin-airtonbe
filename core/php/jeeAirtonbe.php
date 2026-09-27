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
 * Point d'entrée du démon, protégé par la clé API du plugin :
 *   GET  ?apikey=…&action=config&connected=1,2  → climatiseurs à suivre, en JSON
 *   POST ?apikey=…&action=push {"pushes":{ID:{dps,full,online,error}}}
 *                                                → états reçus des climatiseurs
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

/* La seule clé du plugin : jeedom::apiAccess() accepterait aussi celle de
 * n'importe quel utilisateur, qui obtiendrait ici les clés locales des
 * climatiseurs. apiAccess() reste appelé pour son contrôle d'origine (accès
 * du plugin limité à localhost). */
if (!is_string(init('apikey')) || !hash_equals(jeedom::getApiKey('airtonbe'), init('apikey'))
    || !jeedom::apiAccess(init('apikey'), 'airtonbe')) {
    http_response_code(401);
    echo 'Not authorized';
    die();
}

if (init('action') == 'config') {
    /* Preuve de vie : un démon qui ne joint plus Jeedom est déclaré arrêté
     * (airtonbe::deamon_info), puis relancé. */
    cache::set('airtonbe::daemon_seen', time());
    $connected = array_values(array_filter(array_map('intval', explode(',', (string) init('connected')))));
    cache::set('airtonbe::live', array('at' => time(), 'eqs' => $connected));
    header('Content-Type: application/json');
    echo json_encode(airtonbe::daemonConfig());
    die();
}

if (init('action') == 'push') {
    $payload = json_decode(file_get_contents('php://input'), true);
    foreach ((is_array($payload) && isset($payload['pushes']) && is_array($payload['pushes'])) ? $payload['pushes'] : array() as $id => $data) {
        $eqLogic = eqLogic::byId((int) $id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'airtonbe' || !$eqLogic->getIsEnable() || !is_array($data)) {
            continue;
        }
        try {
            $eqLogic->ingestDaemon($data);
        } catch (Throwable $e) {
            log::add('airtonbe', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
    echo 'OK';
    die();
}

http_response_code(400);
echo 'Unknown action';
