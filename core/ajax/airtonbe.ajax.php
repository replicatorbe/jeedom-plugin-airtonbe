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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    ajax::init();

    function airtonbeEq() {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'airtonbe') {
            throw new Exception(__('Climatiseur introuvable :', __FILE__) . ' ' . init('id'));
        }
        return $eqLogic;
    }

    /* Écoute des annonces Tuya du réseau, sans rien créer. */
    if (init('action') == 'discover') {
        unautorizedInDemo();
        ajax::success(airtonbe::discover());
    }

    /* Crée l'équipement d'un appareil trouvé, après un essai de connexion. */
    if (init('action') == 'create') {
        unautorizedInDemo();
        $eqLogic = airtonbe::createFromDiscovery(init('gwId'), init('ip'), init('key'), init('name'), init('productKey'));
        ajax::success(array('id' => $eqLogic->getId()));
    }

    if (init('action') == 'refresh') {
        unautorizedInDemo();
        $eqLogic = airtonbeEq();
        $eqLogic->pollNow();
        /* Démon actif : l'état revient par lui, une fraction de seconde plus tard. */
        usleep(800000);
        ajax::success($eqLogic->toAjax());
    }

    /* Lit le cache, n'interroge jamais l'appareil. */
    if (init('action') == 'data') {
        ajax::success(airtonbeEq()->toAjax());
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

/* Throwable : en PHP 8 une Error n'hérite pas d'Exception. */
} catch (Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
