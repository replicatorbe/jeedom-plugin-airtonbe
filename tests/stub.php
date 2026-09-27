<?php
/* Remplaçants minimaux du coeur de Jeedom, pour rejouer la classe du plugin
 * hors d'une installation : traduction, configuration, journal, et une table
 * des commandes en mémoire. Le but n'est pas de tester Jeedom, mais de
 * vérifier ce que le plugin tire des DP d'une clim, et ce qu'il lui envoie. */

date_default_timezone_set('Europe/Brussels');

function __($_text, $_file = null) { return $_text; }

/* Recopie de la fonction du coeur (core/php/utils.inc.php). */
function cleanComponanteName($_name) {
    $return = strip_tags(str_replace(array('&', '#', ']', '[', '%', "\\", "/", "'", '"', "*"), '', $_name));
    return preg_replace('/\s+/', ' ', $return);
}

class config {
    public static $values = array();
    public static function byKey($_key, $_plugin = 'core', $_default = '') {
        $k = $_plugin . '::' . $_key;
        return isset(self::$values[$k]) ? self::$values[$k] : $_default;
    }
}

class log {
    public static $lines = array();
    public static function add($_plugin, $_level, $_message, $_logicalId = '') {
        self::$lines[] = $_level . ' : ' . $_message;
    }
}

class cmd {
    public static $table = array();

    public $id = '';
    public $eqLogic_id = '';
    public $logicalId = '';
    public $name = '';
    public $type = '';
    public $subType = '';
    public $isVisible = 0;
    public $isHistorized = 0;
    public $order = 0;
    public $unite = '';
    public $generic_type = '';
    public $value = '';
    public $configuration = array();
    public $display = array();
    public $template = array();
    public $current = '';          /* dernière valeur publiée, pour execCmd() */

    public static function reset() { self::$table = array(); }

    public function getId() { return $this->id; }
    public function getLogicalId() { return $this->logicalId; }
    public function getName() { return $this->name; }
    public function getType() { return $this->type; }
    public function getSubType() { return $this->subType; }
    public function getIsVisible() { return $this->isVisible; }
    public function getValue() { return $this->value; }
    public function getConfiguration($_key, $_default = '') {
        return isset($this->configuration[$_key]) ? $this->configuration[$_key] : $_default;
    }

    public function setEqLogic_id($_v) { $this->eqLogic_id = $_v; return $this; }
    public function setLogicalId($_v) { $this->logicalId = $_v; return $this; }
    public function setName($_v) { $this->name = trim(substr(cleanComponanteName($_v), 0, 127)); return $this; }
    public function setType($_v) { $this->type = $_v; return $this; }
    public function setSubType($_v) { $this->subType = $_v; return $this; }
    public function setIsVisible($_v) { $this->isVisible = $_v; return $this; }
    public function setIsHistorized($_v) { $this->isHistorized = $_v; return $this; }
    public function setOrder($_v) { $this->order = $_v; return $this; }
    public function setUnite($_v) { $this->unite = $_v; return $this; }
    public function setGeneric_type($_v) { $this->generic_type = $_v; return $this; }
    public function setValue($_v) { $this->value = $_v; return $this; }
    public function setConfiguration($_k, $_v) { $this->configuration[$_k] = $_v; return $this; }
    public function setDisplay($_k, $_v) { $this->display[$_k] = $_v; return $this; }
    public function setTemplate($_k, $_v) { $this->template[$_k] = $_v; return $this; }
    public function execCmd() { return $this->current; }
    public function remove() { unset(self::$table[$this->id]); }

    /* La contrainte d'unicité (eqLogic_id, name) de la vraie table est
     * reproduite : un doublon lève, comme DB::save() le ferait. */
    public function save() {
        foreach (self::$table as $other) {
            if ($other !== $this && $other->eqLogic_id == $this->eqLogic_id && $other->name === $this->name) {
                throw new Exception('Duplicate entry \'' . $this->eqLogic_id . '-' . $this->name . '\' for key \'unique\'');
            }
        }
        if ($this->id === '') {
            $this->id = count(self::$table) + 1;
            self::$table[$this->id] = $this;
        }
        return true;
    }

    public static function byEqLogicIdCmdName($_eqLogic_id, $_name) {
        foreach (self::$table as $cmd) {
            if ($cmd->eqLogic_id == $_eqLogic_id && $cmd->name === $_name) {
                return $cmd;
            }
        }
        return null;
    }
}

class eqLogic {
    public $id = 1;
    public $configuration = array();
    public $published = array();
    public $store = array();
    public $saved = 0;

    public function getId() { return $this->id; }
    public function getHumanName() { return '[Test][Climatisation]'; }
    public function getConfiguration($_key, $_default = '') {
        return array_key_exists($_key, $this->configuration) ? $this->configuration[$_key] : $_default;
    }
    public function setConfiguration($_key, $_value) {
        $this->configuration[$_key] = $_value;
        return $this;
    }
    public function getCmd($_type = null, $_logicalId = null) {
        foreach (cmd::$table as $cmd) {
            if ($cmd->eqLogic_id == $this->id && $cmd->type === $_type && $cmd->logicalId === $_logicalId) {
                return $cmd;
            }
        }
        return null;
    }
    public $events = 0;
    public function checkAndUpdateCmd($_cmd, $_value, $_when = null) {
        $this->published[is_object($_cmd) ? $_cmd->getLogicalId() : $_cmd] = $_value;
        if (is_object($_cmd)) {
            $_cmd->current = $_value;
        }
        $this->events++;
    }
    /* Publiques dans eqLogic : les redéclarer en privé dans le plugin serait
     * une erreur fatale au chargement de la classe. */
    public function getCache($_key = '', $_default = '') {
        return isset($this->store[$_key]) ? $this->store[$_key] : $_default;
    }
    public function setCache($_key, $_value = null) {
        $this->store[$_key] = $_value;
    }
    public function setIsEnable($_v) {}
    public function setIsVisible($_v) {}
    public function setLogicalId($_v) {}
    public function setName($_v) {}
    public function setEqType_name($_v) {}
    public function save($_direct = false) { $this->saved++; }
    public static function byType($_type, $_onlyEnable = false) { return array(); }
    /* Rend l'équipement enregistré par le jeu d'essai, comme la base. */
    public static $saved_eqs = array();
    public static function byId($_id) { return isset(self::$saved_eqs[$_id]) ? self::$saved_eqs[$_id] : null; }
    public static function byLogicalId($_logicalId, $_eqType, $_multiple = false) { return null; }
}
