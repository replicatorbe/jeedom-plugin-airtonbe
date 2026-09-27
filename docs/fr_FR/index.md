# Plugin Airton

Ce plugin pilote les climatiseurs **Airton** équipés du module Wi-Fi (celui de
l'appli Tuya / Smart Life), directement sur le réseau local. Il ne passe ni par
le cloud Tuya ni par un compte : il parle à la clim avec le protocole local
Tuya 3.3, comme le fait l'intégration LocalTuya de Home Assistant.

Il a été écrit et testé sur le monosplit **Airton 409730** (clé produit
`keyquxnsj75xc8se`), et devrait convenir aux autres Airton qui partagent ce
module (409731, 409733, gamme A+++…).

## Prérequis

Il faut trois informations sur la clim :

| Information | Où la trouver |
|---|---|
| Adresse IP | Trouvée par la recherche du plugin. Réservez-la dans votre routeur. |
| Identifiant (« Device ID ») | Trouvé par la recherche du plugin. |
| Clé locale (« local_key », 16 caractères) | Voir ci-dessous. |

### Obtenir la clé locale

La clé locale n'est jamais diffusée sur le réseau : elle vient du cloud Tuya,
une seule fois. Au choix :

- **Depuis Home Assistant**, si la clim y est déjà intégrée avec LocalTuya :
  options de l'appareil dans l'intégration, ou fichier
  `/config/.storage/core.config_entries`, dans l'entrée `localtuya`, champ
  `devices.<identifiant>.local_key`.
- **Avec tinytuya** (`python -m tinytuya wizard`), qui l'écrit dans
  `devices.json`, champ `key`.
- **Sur la plateforme développeur Tuya** (iot.tuya.com), après y avoir lié
  votre compte Smart Life : fiche de l'appareil.

La clé **change chaque fois que la clim est réappairée** dans l'appli. Si la clim
ne répond plus après un réappairage, c'est elle qu'il faut mettre à jour.

### Une seule connexion à la fois

Un appareil Tuya n'accepte qu'un client local à la fois. Si la clim est aussi
pilotée en local par un autre système (Home Assistant avec LocalTuya ou
tuya-local, par exemple), **désactivez-la dans cet autre système** : sinon les
deux se volent la connexion en boucle. L'appli mobile, elle, passe par le cloud
et continue de fonctionner.

## Installation

1. Installez et activez le plugin. Aucune dépendance n'est à installer.
2. Dans la page du plugin, cliquez sur **Rechercher sur le réseau**. Les
   appareils Tuya s'annoncent toutes les quelques secondes ; les Airton sont
   signalés par une étiquette. Certains appareils cessent de s'annoncer tant
   qu'un autre système leur est connecté : utilisez alors **Ajouter**.
3. Choisissez la clim, donnez-lui un nom, collez sa clé locale. Le plugin
   essaie la connexion avant de créer l'équipement : une clé fausse est
   signalée tout de suite.
4. Démarrez le démon (il est géré automatiquement par défaut).

On peut aussi créer l'équipement avec **Ajouter** et saisir soi-même l'adresse
IP, l'identifiant et la clé.

## Fonctionnement

Le démon garde une connexion ouverte avec chaque climatiseur. La clim y pousse
d'elle-même chaque changement : un réglage fait à la télécommande apparaît dans
Jeedom en moins d'une seconde. Le démon relit aussi l'état complet toutes les
cinq minutes, par sécurité.

Les ordres de Jeedom passent par le démon, qui tient la connexion. Démon
arrêté, le plugin relève la clim une fois par minute par le cron (une minute
sur cinq après trois échecs d'affilée), et chaque ordre ouvre une connexion
courte.

## Commandes

Une commande n'est créée que pour les fonctions que la clim publie réellement.
Sur la tuile, chaque réglage n'apparaît qu'une fois : les interrupteurs
(Marche/Arrêt, ECO, Nuit, Affichage) ne montrent que le bouton utile, le
curseur et les listes affichent l'état courant. Les infos correspondantes
(« État », « État consigne », « État mode »…) restent disponibles, cachées,
pour les scénarios et l'historique.

| Commande | Rôle |
|---|---|
| Marche, Arrêt, État | Marche / arrêt. |
| Température | Température ambiante mesurée par la clim. |
| Consigne, État consigne | 16 à 31 °C, par degré. |
| Mode, État mode, Mode effectif | Voir les valeurs ci-dessous. « Mode effectif » dit ce que fait la clim en mode Auto. |
| Ventilation, État ventilation | Voir les valeurs ci-dessous. |
| Balayage vertical, Balayage horizontal | Positions fixes ou balayage. |
| ECO, Nuit, Affichage, Balayage 3D, Santé (ioniseur), Auto-nettoyage, Hors-gel 8 °C, Chauffage d’appoint, Séchage anti-moisissure | Interrupteurs (« … On » / « … Off » et l'info d'état). |
| Balayage, État balayage | Ancien réglage de balayage (DP 15), en doublon des deux précédents. |
| Minuterie, État minuterie, Minuterie restante | Arrêt programmé, de 1 à 24 heures. Créées d'office sur un Airton : la clim ne signale la minuterie qu'à son changement. |
| Consommation, Durée d’utilisation | Compteurs de la clim. |
| Défaut, Codes défaut | Codes de panne décodés (E1, P0…), à rapprocher de la notice. |
| Unité, Type | °C ou °F ; froid seul ou réversible, tel que la clim le déclare. En lecture seule. |
| Mode (libellé), Ventilation (libellé)… | Le même état en clair (« Froid », « Turbo »), pour les notifications et l'historique. Cachées par défaut. |
| Préréglages | Une commande par préréglage nommé, voir plus bas. |
| En ligne | La clim répond. |
| Rafraîchir | Relit tout l'état. |
| Envoyer des DP | Envoi brut pour les scénarios, voir plus bas. |

### Valeurs à utiliser dans les scénarios

Les infos de mode, de ventilation et de balayage portent la valeur brute de la
clim, stable, et non le libellé affiché. Dans un scénario, on teste donc
`#[Salon][Climatisation][État mode]# == "cold"`, pas `"Froid"`.

| Réglage | Valeurs |
|---|---|
| Mode | `auto` Auto, `cold` Froid, `wet` Déshumidification, `heat` Chauffage, `fan` Ventilation |
| Ventilation | `auto`, `mute` Silence, `low`, `low_mid`, `mid`, `mid_high`, `high`, `turbo` |
| Balayage vertical | `off`, `15` Balayage, `1` (haut) à `5` (bas) |
| Balayage horizontal | `off`, `same` Même sens, `opposite` Sens opposés |
| Balayage (DP 15) | `off`, `un_down`, `left_right`, `all` |
| Minuterie | `0` (aucune) à `24` heures |

### Règles propres aux Airton

- **Choisir un mode clim éteinte l'allume dans ce mode.** La marche et le mode
  partent dans une seule trame : envoyés séparément, les Airton ignorent le
  mode. Clim allumée, seul le mode est envoyé.
- **ECO n'existe qu'en mode Froid** : la commande est refusée dans les autres
  modes.
- La commande **Type** indique ce que la clim déclare (froid seul ou
  réversible). Le mode Chauffage reste proposé dans tous les cas.
- **L'unité (°C / °F) n'est pas réglable depuis Jeedom** : en °F, la consigne
  changerait d'échelle. Laissez la clim en °C.

### Préréglages

Dans l'onglet **Équipement**, jusqu'à quatre préréglages : un nom, un mode,
et au choix une consigne et une ventilation. Chacun devient une commande qui
allume la clim dans ce réglage, en un seul ordre, par exemple « Froid 22 » ou
« Chauffage nuit ». La consigne est ignorée en mode Ventilation. Effacer le nom
retire la commande.

### Envoyer des DP

La commande **Envoyer des DP** envoie un objet JSON de fonctions Tuya, tel
quel, dans une seule trame. Pratique dans un scénario pour tout régler d'un
coup :

```json
{"1":true,"4":"cold","2":220,"5":"auto"}
```

Allume la clim en froid, consigne 22 °C (la valeur est multipliée par 10),
ventilation auto. Les numéros et valeurs sont ceux de l'onglet **Diagnostic**.

## Adresse IP changée

Si la clim ne répond plus, le plugin écoute toutes les dix minutes les annonces
du réseau. S'il la retrouve (par son identifiant) à une autre adresse, il
corrige l'équipement tout seul et le note dans le journal. Réserver l'adresse
dans le routeur reste le plus sûr.

## Diagnostic

L'onglet **Diagnostic** de l'équipement montre l'état de la connexion et les
derniers DP reçus, en valeurs brutes. En cas de souci :

- **« Connexion fermée par l'appareil », « fermée par la clim » ou « connexion
  refusée »** : un autre client tient la clim (voir *Une seule connexion à la
  fois*), ou l'adresse IP a changé.
- **« Connexion impossible » ou « délai de connexion dépassé »** : la clim est
  hors tension, hors Wi-Fi, ou son adresse IP a changé.
- **« clé locale probablement fausse »** : la clim a été réappairée ; récupérez
  la nouvelle clé.
- **« L'appareil a refusé la commande »** : la clim a rejeté la valeur envoyée
  (souvent un DP ou une valeur inconnus, avec « Envoyer des DP »).
- Journal du démon : `airtonbed`. Journal du plugin : `airtonbe`. Passez-les
  en niveau *debug* pour voir chaque trame reçue et chaque ordre envoyé.

## Configuration du plugin

- **Port local du démon** (55133 par défaut) : le démon y reçoit les ordres de
  Jeedom, en boucle locale seulement. À changer uniquement s'il est déjà pris.
