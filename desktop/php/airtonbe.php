<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('airtonbe');
sendVarToJS('eqType', $plugin->getId());
/* Noms des DP connus, pour le diagnostic. */
$dpNames = array();
foreach (airtonbe::PROFILE as $dp => $def) {
	$dpNames[$dp] = isset($def['name']) ? $def['name'] : __('Codes défaut', __FILE__);
}
sendVarToJS('airtonbeDpNames', $dpNames);
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor logoPrimary" id="bt_airtonbeDiscover">
				<i class="fas fa-search"></i>
				<br>
				<span>{{Rechercher sur le réseau}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>

		<legend><i class="fas fa-snowflake"></i> {{Mes climatiseurs}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucun climatiseur pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Récupérez la clé locale de la clim (16 caractères) : dans Home Assistant (LocalTuya), avec tinytuya, ou depuis la plateforme développeur Tuya. Voir la documentation.}}</li>';
			echo '<li>{{Cliquez sur « Rechercher sur le réseau » : les climatiseurs Tuya du réseau s\'annoncent d\'eux-mêmes en quelques secondes. Choisissez le vôtre et collez sa clé.}}</li>';
			echo '<li>{{Désactivez la clim dans tout autre système qui la pilote en local : elle n\'accepte qu\'une connexion à la fois.}}</li>';
			echo '</ol>';
			echo '<span class="help-block" style="margin:8px 0 0 0;">{{Tout se passe sur votre réseau local, sans cloud ni compte. Ce plugin n\'est pas affilié à Airton ni à Tuya.}}</span>';
			echo '</div>';
		}
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<img src="' . $plugin->getPathImgIcon() . '">';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo '<span class="label label-info">' . htmlspecialchars((string) $eqLogic->getConfiguration('ip', '')) . '</span> ';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Équipement}}</span></a></li>
			<li role="presentation"><a href="#diagtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-stethoscope"></i><span class="hidden-xs"> {{Diagnostic}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ========================= ÉQUIPEMENT ========================= -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Climatisation}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											echo '<option value="' . $object->getId() . '">' . $object->getHumanName(true, true) . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Activer}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Visible}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>

							<legend><i class="fas fa-magic"></i> {{Préréglages}}</legend>
							<span class="help-block">{{Chaque préréglage nommé devient une commande qui allume la clim dans ce mode, avec cette consigne et cette ventilation, en un seul ordre. Consigne et ventilation sont facultatives ; effacez le nom pour retirer la commande.}}</span>
							<?php
							$modes = airtonbe::PROFILE[4]['values'];
							$fans = airtonbe::PROFILE[5]['values'];
							for ($n = 1; $n <= airtonbe::PRESETS; $n++) {
								echo '<div class="form-group">';
								echo '<label class="col-sm-3 control-label">{{Préréglage}} ' . $n . '</label>';
								echo '<div class="col-sm-3"><input type="text" class="eqLogicAttr form-control input-sm" data-l1key="configuration" data-l2key="preset' . $n . '_name" placeholder="' . ($n == 1 ? '{{Froid 22}}' : '{{Nom}}') . '"></div>';
								echo '<div class="col-sm-2"><select class="eqLogicAttr form-control input-sm" data-l1key="configuration" data-l2key="preset' . $n . '_mode">';
								echo '<option value="">{{Mode}}</option>';
								foreach ($modes as $value => $label) {
									echo '<option value="' . $value . '">' . $label . '</option>';
								}
								echo '</select></div>';
								echo '<div class="col-sm-2"><input type="number" class="eqLogicAttr form-control input-sm" data-l1key="configuration" data-l2key="preset' . $n . '_target" min="' . airtonbe::TARGET_MIN . '" max="' . airtonbe::TARGET_MAX . '" placeholder="°C"></div>';
								echo '<div class="col-sm-2"><select class="eqLogicAttr form-control input-sm" data-l1key="configuration" data-l2key="preset' . $n . '_fan">';
								echo '<option value="">{{Ventilation}}</option>';
								foreach ($fans as $value => $label) {
									echo '<option value="' . $value . '">' . $label . '</option>';
								}
								echo '</select></div>';
								echo '</div>';
							}
							?>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-plug"></i> {{Connexion locale}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Adresse IP}}</label>
								<div class="col-sm-5">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ip" placeholder="192.168.0.50">
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Réservez-la dans votre routeur.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Identifiant de l'appareil}}</label>
								<div class="col-sm-5">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="dev_id" placeholder="bf0123456789abcdefgh">
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{« Device ID » Tuya, rempli par la recherche.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Clé locale}}</label>
								<div class="col-sm-5">
									<div class="input-group">
										<input type="password" class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="local_key" id="in_airtonbeKey" autocomplete="new-password">
										<span class="input-group-btn">
											<a class="btn btn-default roundedRight" id="bt_airtonbeShowKey" title="{{Afficher}}"><i class="fas fa-eye"></i></a>
										</span>
									</div>
									<span class="text-danger" id="span_airtonbeKeyProblem" style="display:none;"></span>
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{« local_key », 16 caractères. Elle change si la clim est réappairée dans l'appli.}}</span>
								</div>
							</div>

							<legend><i class="fas fa-info-circle"></i> {{Identité}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Clé produit}}</label>
								<div class="col-sm-9">
									<span class="eqLogicAttr label label-info" data-l1key="configuration" data-l2key="product_key"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Fonctions publiées}}</label>
								<div class="col-sm-9">
									<span id="span_airtonbeSeen"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label"></label>
								<div class="col-sm-9">
									<a class="btn btn-default btn-sm" id="bt_airtonbeRefresh"><i class="fas fa-sync"></i> {{Relever maintenant}}</a>
									<span id="span_airtonbeStatus" style="margin-left:10px;"></span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ========================== DIAGNOSTIC ========================= -->
			<div role="tabpanel" class="tab-pane" id="diagtab">
				<br>
				<div class="col-xs-12">
					<div class="alert alert-info" id="div_airtonbeState">{{Chargement…}}</div>
					<legend><i class="fas fa-code"></i> {{Derniers DP reçus}}</legend>
					<span class="help-block">{{Valeurs brutes telles que la clim les envoie (consigne et température multipliées par 10). C'est la pièce à joindre en cas de valeur douteuse.}}</span>
					<table class="table table-condensed table-bordered" style="max-width:700px;">
						<thead><tr><th style="width:70px;">DP</th><th>{{Fonction}}</th><th>{{Valeur brute}}</th></tr></thead>
						<tbody id="tb_airtonbeDps"></tbody>
					</table>
				</div>
			</div>

			<!-- ========================== COMMANDES ========================== -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="col-xs-12">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:300px;">{{Nom}}</th>
								<th style="width:130px;">{{Type}}</th>
								<th>{{Paramètres}}</th>
								<th style="width:160px;">{{Valeur}}</th>
								<th style="width:120px;">{{Actions}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('desktop', 'airtonbe', 'js', 'airtonbe'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
