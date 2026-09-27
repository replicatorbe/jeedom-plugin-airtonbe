# Changelog

## 0.3.0

- Préréglages : jusqu'à quatre commandes « un clic » (mode, consigne,
  ventilation), envoyées en une seule trame et allumant la clim.
- Infos en clair (« Froid », « Turbo »…) à côté des valeurs brutes, pour les
  notifications et l'historique.
- Minuterie et mode effectif créés d'office sur les Airton, qui ne les
  signalent qu'à leur changement.
- Adresse IP corrigée toute seule quand la clim a changé d'adresse.

## 0.2.0

Corrections après relecture complète.

- Démon : un ordre que Jeedom a déjà annoncé en échec n'est plus envoyé en
  retard ; les appels à Jeedom ne bloquent plus la boucle plus de cinq
  secondes.
- Démon : une commande refusée par la clim n'est plus prise pour une clé
  fausse, et ne coupe plus la connexion.
- Démon : l'appareil repasse en ligne dès la première trame reçue ; la relecture
  complète est redemandée si elle s'est perdue ; une erreur imprévue ne l'arrête
  plus.
- Démon actif, « Rafraîchir » et la création passent toujours par lui : plus de
  seconde connexion à la clim.
- Point d'entrée du démon réservé à la clé API du plugin.
- Tuile allégée : interrupteurs sur un seul bouton, états cachés derrière leurs
  réglages, ordre fixe. Commandes renommées (Marche, Arrêt, Consigne, Mode,
  Ventilation…).
- « Codes défaut » ne relance plus un événement à chaque relevé.
- Unité °C/°F en lecture seule.
- Page : recherche compatible Jeedom 4.4+ sans jQuery, clé locale vérifiée à la
  saisie, saisie gardée si la création échoue, erreurs du serveur toujours
  signalées.
- Cron de secours limité à quinze secondes.
- Catégorie « Confort ».

## 0.1.0

Première version.

- Protocole local Tuya 3.3 en PHP natif, sans dépendance.
- Démon qui garde la connexion avec chaque climatiseur, reçoit les changements
  en direct et transmet les ordres ; relevé par le cron, une fois par minute,
  quand il est arrêté.
- Découverte des appareils Tuya du réseau et création de l'équipement après un
  essai de connexion.
- Profil Airton : marche, consigne, température, mode, ventilation, balayages,
  ECO, nuit, affichage, santé, nettoyage, hors-gel, appoint, minuterie,
  consommation, codes défaut.
- Choisir un mode clim éteinte l'allume dans ce mode en une seule trame.
