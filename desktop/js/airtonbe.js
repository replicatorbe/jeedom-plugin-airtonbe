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

/* Ce qui vient d'un appareil est du texte, jamais du balisage. */
function airtonbeEscape(_text) {
  var div = document.createElement('div')
  div.textContent = (_text === null || _text === undefined) ? '' : String(_text)
  return div.innerHTML
}

function airtonbeAjax(_action, _data, _success, _error) {
  var payload = { action: _action }
  for (var key in _data) {
    if (Object.prototype.hasOwnProperty.call(_data, key)) { payload[key] = _data[key] }
  }
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/airtonbe/core/ajax/airtonbe.ajax.php',
    data: payload,
    dataType: 'json',
    global: false,
    error: function (error) {
      if (typeof _error === 'function') { _error(error); return }
      domUtils.handleAjaxError(error)
    },
    success: function (result) {
      if (result.state !== 'ok') {
        if (typeof _error === 'function') { _error(result); return }
        jeedomUtils.showAlert({ message: result.result, level: 'danger' })
        return
      }
      _success(result)
    }
  })
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

/* ============================================================== DÉCOUVERTE */

function airtonbeDiscover() {
  jeedomUtils.showAlert({ message: '{{Écoute des annonces des appareils Tuya… six secondes.}}', level: 'info' })
  airtonbeAjax('discover', {}, function (result) {
    jeedomUtils.hideAlert()
    airtonbeShowFound(result.result)
  })
}

function airtonbeShowFound(_devices) {
  if (!isset(_devices) || _devices.length === 0) {
    jeedomUtils.showAlert({ message: '{{Aucun appareil Tuya ne s\'est annoncé. Vérifiez que la clim est sous tension et sur le même réseau que Jeedom, ou ajoutez-la à la main.}}', level: 'warning' })
    return
  }
  var html = '<p>{{Choisissez le climatiseur, puis collez sa clé locale. La connexion est essayée avant la création.}}</p>'
  for (var i = 0; i < _devices.length; i++) {
    var d = _devices[i]
    html += '<div class="radio"><label>'
    html += '<input type="radio" name="airtonbeFound" value="' + i + '"' + (i === 0 ? ' checked' : '') + '> '
    html += '<b>' + airtonbeEscape(d.ip) + '</b> — ' + airtonbeEscape(d.gwId)
    html += ' <small>(' + airtonbeEscape(d.productKey) + ', v' + airtonbeEscape(d.version) + ')</small>'
    if (d.airton) { html += ' <span class="label label-success">Airton</span>' }
    if (d.known) { html += ' <span class="label label-default">{{déjà créé :}} ' + airtonbeEscape(d.known) + '</span>' }
    html += '</label></div>'
  }
  html += '<div class="form-group" style="margin-top:10px;"><label>{{Nom}}</label>'
  html += '<input class="form-control" id="in_airtonbeNewName" value="{{Climatisation}}"></div>'
  html += '<div class="form-group"><label>{{Clé locale (16 caractères)}}</label>'
  html += '<input class="form-control" id="in_airtonbeNewKey" maxlength="16" autocomplete="off" placeholder="{{laisser vide pour un appareil déjà créé}}"></div>'
  bootbox.confirm({
    title: '{{Appareils Tuya trouvés}}',
    message: html,
    callback: function (_ok) {
      if (!_ok) { return }
      var checked = document.querySelector('input[name="airtonbeFound"]:checked')
      if (checked === null) { return }
      var d = _devices[parseInt(checked.value, 10)]
      airtonbeAjax('create', {
        gwId: d.gwId,
        ip: d.ip,
        productKey: d.productKey,
        name: airtonbeEl('in_airtonbeNewName').value,
        key: airtonbeEl('in_airtonbeNewKey').value
      }, function (result) {
        jeedomUtils.showAlert({ message: '{{Climatiseur enregistré.}}', level: 'success' })
        jeedomUtils.loadPage('index.php?v=d&m=airtonbe&p=airtonbe&id=' + result.result.id)
      })
    }
  })
}

/* ============================================================== DIAGNOSTIC */

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
  var html = ''
  var dps = (isset(_data) && _data.dps) ? _data.dps : {}
  var keys = Object.keys(dps).sort(function (a, b) { return parseInt(a, 10) - parseInt(b, 10) })
  for (var i = 0; i < keys.length; i++) {
    var name = (typeof airtonbeDpNames !== 'undefined' && airtonbeDpNames[keys[i]]) ? airtonbeDpNames[keys[i]] : '{{inconnu}}'
    html += '<tr><td>' + airtonbeEscape(keys[i]) + '</td><td>' + airtonbeEscape(name) + '</td><td><code>'
      + airtonbeEscape(JSON.stringify(dps[keys[i]])) + '</code></td></tr>'
  }
  tbody.innerHTML = html
}

function airtonbeRefresh() {
  var id = airtonbeCurrentId()
  if (id === '') {
    jeedomUtils.showAlert({ message: '{{Enregistrez le climatiseur avant de le relever.}}', level: 'warning' })
    return
  }
  airtonbeStatus('{{Lecture en cours…}}', 'default')
  airtonbeAjax('refresh', { id: id }, function (result) {
    airtonbeStatus('{{Relevé effectué.}}', 'success')
    airtonbeRender(result.result)
  }, function (error) {
    airtonbeStatus((error && error.result) ? error.result : '{{Échec du relevé}}', 'danger')
  })
}

function airtonbeToggleKey() {
  var input = airtonbeEl('in_airtonbeKey')
  if (input !== null) { input.type = (input.type === 'password') ? 'text' : 'password' }
}

/* ==================================================== APPELÉES PAR LE COEUR */

function printEqLogic(_eqLogic) {
  airtonbeStatus('', '')
  airtonbeRender({ dps: {} })
  var input = airtonbeEl('in_airtonbeKey')
  if (input !== null) { input.type = 'password' }
  if (isset(_eqLogic.id) && _eqLogic.id !== '') {
    airtonbeAjax('data', { id: _eqLogic.id }, function (result) {
      airtonbeRender(result.result)
    })
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
  newRow.setAttribute('title', '{{Identifiant logique}} : ' + init(_cmd.logicalId))
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
  document.addEventListener('click', function (_event) {
    var target = _event.target
    if (target === null || typeof target.closest !== 'function') { return }
    var actions = {
      bt_airtonbeDiscover: function () { airtonbeDiscover() },
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
