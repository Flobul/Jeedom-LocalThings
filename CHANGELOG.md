# Changelog

## 0.3.0

- Ajout d’une commande action `Rafraîchir` sur chaque équipement.
- Ajout des sélecteurs locaux de température de lavage, vitesse d’essorage et
  nombre de rinçages selon les valeurs réellement annoncées par l’appareil.
- Ajout du choix de cycle depuis `editCourseList`, avec traduction des tables
  Samsung connues sans exposer de cycles absents de la machine.
- Correction des états binaires textuels, des unités d’énergie et de la vitesse
  d’essorage.
- Suppression ciblée des doublons Samsung lorsque la ressource OCF standard est
  disponible.

## 0.2.1

- Exploration exhaustive des ports UDP `49152-49160` lors d’un ajout manuel.
- Prise en compte des appareils présents dans la table ARP même lorsqu’ils
  ignorent les requêtes ping.
- Journalisation détaillée de la tâche PHP, des ports essayés, du handshake
  DTLS, des échanges CoAP et du décodage CBOR.
- Ajout de diagnostics OpenSSL exploitables sans exposer les certificats ni
  les clés privées.

## 0.2.0

- Remplacement intégral du service externe par des classes PHP.
- Ajout des codecs CBOR et CoAP et du client DTLS piloté par OpenSSL.
- Découverte réseau asynchrone au moyen d'une tâche PHP CLI.
- Gestion des certificats, lectures et commandes directement depuis Jeedom.
- Suppression de l'environnement virtuel et de toutes les dépendances
  applicatives externes.

## 0.1.0

- Première version du plugin LocalThings.
- Découverte IPv4 asynchrone et ajout manuel par adresse.
- Sessions locales CoAP sur DTLS et génération du profil de certificat
  Samsung.
- Création automatique des informations et actions Jeedom depuis le registre
  de capacités LocalThings.
- Gestion des sous-appareils, du verrou Smart Control et des diagnostics.
