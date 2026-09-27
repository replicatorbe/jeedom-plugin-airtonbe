# Plugin Jeedom — Airton

Pilote en réseau local les **climatiseurs Airton** à module Wi-Fi (protocole
Tuya 3.3), sans cloud ni compte.

- **Connexion directe** : un petit démon PHP garde la connexion avec la clim et
  reçoit aussitôt chaque changement — télécommande, appli, Jeedom.
- **Découverte** : les climatiseurs Tuya du réseau s'annoncent d'eux-mêmes ; le
  plugin les liste, il ne reste qu'à coller la clé locale.
- **Commandes** : marche/arrêt, consigne (16–31 °C), température, mode (auto,
  froid, déshumidification, chauffage, ventilation), ventilation de silence à
  turbo, balayages vertical, horizontal et 3D, ECO, nuit, affichage, santé,
  auto-nettoyage, hors-gel 8 °C, chauffage d'appoint, minuterie, consommation,
  codes défaut décodés, et un envoi de DP bruts pour les scénarios.
- **Particularités Airton respectées** : valeurs de mode propres à Airton
  (`heat`, `fan`), et marche + mode envoyés dans une seule trame quand on
  choisit un mode clim éteinte — envoyés séparément, les Airton ignorent le mode.
- **Seulement les commandes utiles** : une commande n'est créée que pour les
  fonctions que la clim publie réellement.
- **Aucune dépendance** : PHP natif (openssl, sockets).

Testé sur le monosplit Airton 409730 (clé produit `keyquxnsj75xc8se`).

Documentation : [docs/fr_FR/index.md](docs/fr_FR/index.md).

Ce plugin n'est affilié ni à Airton ni à Tuya.
