<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-plug"></i> {{Démon}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Port local du démon}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="daemon_port" placeholder="55133" min="1025" max="65535">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Port sur lequel le démon reçoit les ordres de Jeedom, en boucle locale (127.0.0.1) seulement. À changer uniquement s'il est déjà pris par un autre programme.}}</span>
			</div>
		</div>
		<div class="form-group">
			<div class="col-md-offset-4 col-md-7">
				<span class="help-block" style="margin:0;">{{Un climatiseur Tuya n'accepte qu'une connexion locale à la fois. Le démon la garde ouverte : désactivez la clim dans tout autre système qui la pilote en local (Home Assistant avec LocalTuya ou tuya-local…), sinon les deux se disputeront la connexion.}}</span>
			</div>
		</div>
	</fieldset>
</form>
