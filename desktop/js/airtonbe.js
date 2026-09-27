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

/* ================================================================== OUTILS */

function airtonbeEl(_id) {
  return document.getElementById(_id)
}

/* Ce qui vient d'un appareil ou du serveur est du texte, jamais du balisage. */
function airtonbeEscape(_text) {
  var div = document.createElement('div')
  div.textContent = (_text === null || _text === undefined) ? '' : String(_text)
  return div.innerHTML
}

/* Valeur d'attribut HTML : airtonbeEscape ne couvre pas les guillemets. */
function airtonbeAttr(_text) {
  return String((_text === null || _text === undefined) ? '' : _text)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;')
}

/* Les fenêtres du coeur : jeeDialog depuis Jeedom 4.4, bootbox sinon (il
   n'est chargé qu'avec jQuery). */
function airtonbeConfirm(_title, _message, _callback) {
  var options = { title: _title, message: _message, callback: function (_ok) { if (_ok) { _callback() } } }
  if (typeof jeeDialog !== 'undefined') {
    jeeDialog.confirm(options)
  } else {
    bootbox.confirm(options)
  }
}

/*
 * Appel au plugin. Le rappel d'erreur est toujours appelé, une seule fois :
 * sur une réponse 500 ou 504, domUtils.ajax réessaie puis abandonne sans
 * prévenir personne, et un statut « en cours » resterait affiché pour
 * toujours. Le coeur affiche déjà l'erreur HTTP : on ne la double pas.
 */
function airtonbeAjax(_action, _data, _success, _error, _timeoutMs) {
  var payload = { action: _action }
  for (var key in _data) {
    if (Object.prototype.hasOwnProperty.call(_data, key)) { payload[key] = _data[key] }
  }
  var settled = false
  var fail = function (_result) {
    if (settled) { return }
    settled = true
    clearTimeout(watchdog)
    if (typeof _error === 'function') { _error(_result) }
  }
  var watchdog = setTimeout(function () {
    fail({ result: '{{Pas de réponse du serveur.}}' })
  }, _timeoutMs || 30000)
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/airtonbe/core/ajax/airtonbe.ajax.php',
    data: payload,
    dataType: 'json',
    global: false,
    error: function (error) {
      fail(error)
    },
    success: function (result) {
      if (settled) { return }
      if (result.state !== 'ok') {
        if (typeof _error !== 'function') {
          jeedomUtils.showAlert({ message: airtonbeEscape(result.result), level: 'danger' })
        }
        fail(result)
        return
      }
      settled = true
      clearTimeout(watchdog)
      _success(result)
    }
  })
}

function airtonbeErrorText(_error, _default) {
  return (_error && typeof _error.result === 'string' && _error.result !== '') ? _error.result : _default
}

function airtonbeStatus(_text, _level) {
  var span = airtonbeEl('span_airtonbeStatus')
  if (span === null) { return }
  span.textContent = _text
  span.className = _level ? 'label label-' + _level : ''
}

function airtonbeCurrentId() {
  var id = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return (id === null) ? '' : id.value
}

/* La clé locale fait 16 caractères ; les espaces en bord viennent d'un
   copier-coller et sont retirés à l'enregistrement. */
function airtonbeKeyProblem(_key) {
  var key = String(_key || '').trim()
  if (key === '') { return '' }
  return key.length === 16 ? '' : '{{La clé locale doit faire 16 caractères (ici :}} ' + key.length + ')'
}

function airtonbeCheckKey() {
  var input = airtonbeEl('in_airtonbeKey')
  var help = airtonbeEl('span_airtonbeKeyProblem')
  if (input === null || help === null) { return }
  var problem = airtonbeKeyProblem(input.value)
  help.textContent = problem
  help.style.display = problem === '' ? 'none' : ''
}

/* ============================================================== DÉCOUVERTE */

var airtonbeSearching = false

function airtonbeDiscover(_button) {
  if (airtonbeSearching) { return }
  airtonbeSearching = true
  var icon = _button ? _button.querySelector('i') : null
  if (icon !== null) { icon.className = 'fas fa-spinner fa-spin' }
  var done = function () {
    airtonbeSearching = false
    if (icon !== null) { icon.className = 'fas fa-search' }
  }
  jeedomUtils.showAlert({ message: '{{Écoute des annonces des appareils Tuya… six secondes.}}', level: 'info' })
  airtonbeAjax('discover', {}, function (result) {
    done()
    jeedomUtils.hideAlert()
    airtonbeShowFound(result.result, {})
  }, function (error) {
    done()
    jeedomUtils.showAlert({ message: airtonbeEscape(airtonbeErrorText(error, '{{Échec de la recherche.}}')), level: 'danger' })
  })
}

/* _previous garde le choix, le nom et la clé d'un essai qui a échoué : la
   fenêtre se rouvre telle quelle, sans relancer la recherche. */
function airtonbeShowFound(_devices, _previous) {
  if (!isset(_devices) || _devices.length === 0) {
    jeedomUtils.showAlert({ message: '{{Aucun appareil Tuya ne s\'est annoncé. Vérifiez que la clim est sous tension et sur le même réseau que Jeedom. Certains appareils cessent de s\'annoncer tant qu\'un autre système (Home Assistant…) leur est connecté : ajoutez-la alors avec « Ajouter ».}}', level: 'warning' })
    return
  }
  var chosen = isset(_previous.index) ? _previous.index : 0
  var html = '<p>{{Choisissez le climatiseur, puis collez sa clé locale. La connexion est essayée avant la création.}}</p>'
  if (_previous.error) {
    html += '<div class="alert alert-danger">' + airtonbeEscape(_previous.error) + '</div>'
  }
  for (var i = 0; i < _devices.length; i++) {
    var d = _devices[i]
    html += '<div class="radio"><label>'
    html += '<input type="radio" name="airtonbeFound" value="' + i + '"' + (i === chosen ? ' checked' : '') + '> '
    html += '<b>' + airtonbeEscape(d.ip) + '</b> — ' + airtonbeEscape(d.gwId)
    html += ' <small>(' + airtonbeEscape(d.productKey) + ', v' + airtonbeEscape(d.version) + ')</small>'
    if (d.airton) { html += ' <span class="label label-success">Airton</span>' }
    if (d.known) { html += ' <span class="label label-default">{{déjà créé :}} ' + airtonbeEscape(d.known) + '</span>' }
    html += '</label></div>'
  }
  html += '<div class="form-group" style="margin-top:10px;"><label>{{Nom}}</label>'
  html += '<input class="form-control" id="in_airtonbeNewName" value="' + airtonbeAttr(isset(_previous.name) ? _previous.name : '{{Climatisation}}') + '"></div>'
  html += '<div class="form-group"><label>{{Clé locale (16 caractères)}}</label>'
  html += '<input class="form-control" id="in_airtonbeNewKey" autocomplete="off" value="' + airtonbeAttr(_previous.key || '') + '"'
  html += ' placeholder="{{laisser vide pour un appareil déjà créé}}"></div>'
  airtonbeConfirm('{{Appareils Tuya trouvés}}', html, function () {
    var checked = document.querySelector('input[name="airtonbeFound"]:checked')
    if (checked === null) { return }
    var index = parseInt(checked.value, 10)
    var d = _devices[index]
    var entered = { index: index, name: airtonbeEl('in_airtonbeNewName').value, key: airtonbeEl('in_airtonbeNewKey').value }
    var problem = airtonbeKeyProblem(entered.key)
    if (problem !== '' || (entered.key.trim() === '' && !d.known)) {
      entered.error = problem !== '' ? problem : '{{Collez la clé locale de la clim.}}'
      setTimeout(function () { airtonbeShowFound(_devices, entered) }, 300)
      return
    }
    jeedomUtils.showAlert({ message: '{{Essai de connexion à la clim…}}', level: 'info' })
    airtonbeAjax('create', {
      gwId: d.gwId,
      ip: d.ip,
      productKey: d.productKey,
      name: entered.name,
      key: entered.key
    }, function (result) {
      jeedomUtils.showAlert({ message: '{{Climatiseur enregistré.}}', level: 'success' })
      jeedomUtils.loadPage('index.php?v=d&m=airtonbe&p=airtonbe&id=' + result.result.id)
    }, function (error) {
      jeedomUtils.hideAlert()
      entered.error = airtonbeErrorText(error, '{{Échec de la création.}}')
      airtonbeShowFound(_devices, entered)
    })
  })
}

/* ============================================================== DIAGNOSTIC */

function airtonbeDpName(_dp) {
  return (typeof airtonbeDpNames !== 'undefined' && airtonbeDpNames[_dp]) ? airtonbeDpNames[_dp] : 'DP ' + _dp
}

function airtonbeRender(_data) {
  var state = airtonbeEl('div_airtonbeState')
  if (state !== null && isset(_data) && isset(_data.id)) {
    var link = _data.daemon !== 'ok' ? '{{démon arrêté — relevé par le cron, une fois par minute}}'
      : (_data.live ? '{{connexion directe active}}' : '{{démon actif, pas encore connecté à la clim}}')
    if (_data.dpsAt === '') {
      state.className = 'alert alert-warning'
      state.textContent = '{{Aucun relevé pour le moment.}} · ' + link
    } else if (_data.online) {
      state.className = 'alert alert-success'
      state.textContent = '{{Dernier état reçu :}} ' + _data.dpsAt + ' · ' + link
    } else {
      state.className = 'alert alert-warning'
      state.textContent = '{{Injoignable :}} ' + _data.failures + ' {{échec(s)}}'
        + (_data.problem ? ' — ' + _data.problem : '') + ' · {{dernier état reçu :}} ' + (_data.dpsAt || '{{jamais}}') + ' · ' + link
    }
  }
  var tbody = airtonbeEl('tb_airtonbeDps')
  if (tbody === null) { return }
  var dps = (isset(_data) && _data.dps) ? _data.dps : {}
  var keys = Object.keys(dps).sort(function (a, b) { return parseInt(a, 10) - parseInt(b, 10) })
  if (keys.length === 0) {
    tbody.innerHTML = '<tr><td colspan="3" class="text-muted">{{Aucun DP reçu pour le moment.}}</td></tr>'
    return
  }
  var html = ''
  for (var i = 0; i < keys.length; i++) {
    html += '<tr><td>' + airtonbeEscape(keys[i]) + '</td><td>' + airtonbeEscape(airtonbeDpName(keys[i])) + '</td><td><code>'
      + airtonbeEscape(JSON.stringify(dps[keys[i]])) + '</code></td></tr>'
  }
  tbody.innerHTML = html
}

/* Fonctions publiées par la clim, par leur nom plutôt que leur numéro. */
function airtonbeRenderSeen(_seen) {
  var el = airtonbeEl('span_airtonbeSeen')
  if (el === null) { return }
  var list = String(_seen || '').split(',').filter(function (_dp) { return _dp !== '' })
  el.textContent = list.length === 0 ? '{{connues au premier relevé}}'
    : list.map(function (_dp) { return airtonbeDpName(_dp) }).join(', ')
}

function airtonbeRefresh() {
  var id = airtonbeCurrentId()
  if (id === '') {
    jeedomUtils.showAlert({ message: '{{Enregistrez le climatiseur avant de le relever.}}', level: 'warning' })
    return
  }
  airtonbeStatus('{{Lecture en cours…}}', 'default')
  airtonbeAjax('refresh', { id: id }, function (result) {
    if (airtonbeCurrentId() !== id) { return }
    airtonbeStatus('{{Relevé effectué.}}', 'success')
    airtonbeRender(result.result)
  }, function (error) {
    if (airtonbeCurrentId() !== id) { return }
    airtonbeStatus(airtonbeErrorText(error, '{{Échec du relevé}}'), 'danger')
  })
}

function airtonbeToggleKey() {
  var input = airtonbeEl('in_airtonbeKey')
  if (input !== null) { input.type = (input.type === 'password') ? 'text' : 'password' }
}

/* ==================================================== APPELÉES PAR LE COEUR */

function printEqLogic(_eqLogic) {
  airtonbeStatus('', '')
  var state = airtonbeEl('div_airtonbeState')
  if (state !== null) {
    state.className = 'alert alert-info'
    state.textContent = '{{Chargement…}}'
  }
  airtonbeRender({ dps: {} })
  airtonbeRenderSeen(isset(_eqLogic.configuration) ? _eqLogic.configuration.dps_seen : '')
  var input = airtonbeEl('in_airtonbeKey')
  if (input !== null) { input.type = 'password' }
  airtonbeCheckKey()
  if (isset(_eqLogic.id) && _eqLogic.id !== '') {
    var id = String(_eqLogic.id)
    airtonbeAjax('data', { id: id }, function (result) {
      /* Réponse d'un équipement qu'on a quitté entre-temps : ignorée. */
      if (airtonbeCurrentId() !== id) { return }
      airtonbeRender(result.result)
    }, function (error) {
      if (airtonbeCurrentId() !== id || state === null) { return }
      state.className = 'alert alert-danger'
      state.textContent = airtonbeErrorText(error, '{{État indisponible.}}')
    })
  } else if (state !== null) {
    state.className = 'alert alert-info'
    state.textContent = '{{Enregistrez le climatiseur pour voir son état.}}'
  }
}

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  var tr = '<td>'
  /* Sans ce champ, chaque enregistrement détruit puis recrée les commandes. */
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  if (init(_cmd.type) === 'info') {
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>'
  }
  tr += '<span class="cmdAttr" data-l1key="unite" style="margin-left:8px;opacity:.7;"></span>'
  tr += '</td>'
  tr += '<td><span class="cmdAttr" data-l1key="htmlstate"></span></td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '</td>'

  /* Ligne créée en DOM : insertAdjacentHTML sur une table crée un <tbody> par
     insertion. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.querySelector('td').setAttribute('title', '{{Identifiant logique}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* =============================================================== ÉCOUTEURS */

/* Pages chargées en ajax : un seul écouteur, posé une fois sur le document,
   qui résout les fonctions au moment du clic : elles sont redéfinies à chaque
   chargement. */
if (!window.airtonbeListening) {
  window.airtonbeListening = true
  document.addEventListener('input', function (_event) {
    if (_event.target && _event.target.id === 'in_airtonbeKey') { airtonbeCheckKey() }
  })
  document.addEventListener('click', function (_event) {
    var target = _event.target
    if (target === null || typeof target.closest !== 'function') { return }
    var actions = {
      bt_airtonbeDiscover: function () { airtonbeDiscover(target.closest('#bt_airtonbeDiscover')) },
      bt_airtonbeRefresh: function () { airtonbeRefresh() },
      bt_airtonbeShowKey: function () { airtonbeToggleKey() }
    }
    for (var id in actions) {
      if (target.closest('#' + id) !== null) {
        _event.preventDefault()
        actions[id]()
        return
      }
    }
  })
}
