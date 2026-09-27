# Changelog

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
