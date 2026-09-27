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
   signalés par une étiquette.
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
arrêté, le plugin relève la clim une fois par minute par le cron, et chaque
ordre ouvre une connexion courte.

## Commandes

Une commande n'est créée que pour les fonctions que la clim publie réellement.
Les principales :

| Commande | Rôle |
|---|---|
| Etat, Allumer, Eteindre | Marche / arrêt. |
| Consigne, Régler la consigne | 16 à 31 °C, par degré. |
| Température | Température ambiante mesurée par la clim. |
| Mode, Choisir le mode | Auto, Froid, Déshumidification, Chauffage, Ventilation. |
| Ventilation, Choisir la ventilation | Auto, Silence, Basse… Haute, Turbo. |
| Balayage vertical / horizontal / 3D | Positions fixes ou balayage. |
| ECO, Nuit, Affichage, Santé, Auto-nettoyage, Hors-gel 8 °C, Chauffage d'appoint, Séchage | Interrupteurs. |
| Minuterie, Minuterie restante | Arrêt programmé, de 1 à 24 heures. |
| Consommation, Durée d'utilisation | Compteurs de la clim. |
| Défaut, Codes défaut | Codes de panne décodés (E1, P0…), à rapprocher de la notice. |
| En ligne | La clim répond. |
| Rafraîchir | Relit tout l'état. |
| Envoyer des DP | Envoi brut pour les scénarios, voir plus bas. |

### Règles propres aux Airton

- **Choisir un mode clim éteinte l'allume dans ce mode.** La marche et le mode
  partent dans une seule trame : envoyés séparément, les Airton ignorent le
  mode. Clim allumée, seul le mode est envoyé.
- **ECO n'existe qu'en mode Froid** : la commande est refusée dans les autres
  modes.
- La commande **Type** indique ce que la clim déclare (froid seul ou
  réversible). Le mode Chauffage reste proposé dans tous les cas.

### Envoyer des DP

La commande **Envoyer des DP** envoie un objet JSON de fonctions Tuya, tel
quel, dans une seule trame. Pratique dans un scénario pour tout régler d'un
coup :

```json
{"1":true,"4":"cold","2":220,"5":"auto"}
```

Allume la clim en froid, consigne 22 °C (la valeur est multipliée par 10),
ventilation auto. Les numéros et valeurs sont ceux de l'onglet **Diagnostic**.

## Diagnostic

L'onglet **Diagnostic** de l'équipement montre l'état de la connexion et les
derniers DP reçus, en valeurs brutes. En cas de souci :

- **« connexion refusée » ou connexion fermée aussitôt** : un autre client tient
  la clim (voir *Une seule connexion à la fois*), ou l'adresse IP a changé.
- **« clé locale probablement fausse »** : la clim a été réappairée ; récupérez
  la nouvelle clé.
- Journal du démon : `airtonbed`. Journal du plugin : `airtonbe`. Passez-les
  en niveau *debug* pour voir chaque trame reçue et chaque ordre envoyé.

## Configuration du plugin

- **Port local du démon** (55133 par défaut) : le démon y reçoit les ordres de
  Jeedom, en boucle locale seulement. À changer uniquement s'il est déjà pris.
