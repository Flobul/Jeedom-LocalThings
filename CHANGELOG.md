# Changelog

## 0.4.4

- Correction des actions Samsung : suppression de la relecture prématurée qui
  pouvait provoquer le retour immédiat à l'ancienne valeur, puis vérification
  unique après la fenêtre de stabilisation de l'appareil.
- Remplacement de l'intervalle libre par une liste d'intervalles de
  rafraîchissement prédéfinis, de 1 minute à 1 jour.
- Détection des numéros de série Samsung factices composés uniquement de
  `F` ou de `0`, avec utilisation de l'identifiant OCF standard et migration
  de l'équipement existant sans doublon.
- Présentation des paires d'actions On/Off sous forme d'un interrupteur unique,
  synchronisé avec la commande d'information associée.
- Refonte de la page Santé avec les contrôles globaux et l'état détaillé de
  chaque équipement, dans une présentation commune aux plugins Jeedom.
- Ajout d'un test de communication DTLS depuis la configuration de
  l'équipement et depuis la page Santé.
- Normalisation complète des unités annoncées par les appareils et inférence
  des unités Jeedom usuelles lorsqu'elles sont absentes.
- Traduction des noms et des valeurs techniques Samsung lors de la génération
  des commandes, avec compléments anglais, allemand et espagnol.
- Conservation des tests dans le dépôt de développement, avec exclusion des
  archives installées dans Jeedom.

## 0.4.3

- Ouverture de l'historique Jeedom au clic sur les informations numériques
  historisées.
- Remplacement du contenu brut de la page Informations par une sélection de
  données secondaires pertinentes, sans doublon avec les autres pages.

## 0.4.2

- Alignement des icônes de santé et de rafraîchissement du bandeau
  `.widget-name` sur les widgets SmartThings.
- Remplacement des liens d'onglets par des boutons ciblés afin d'éviter tout
  déplacement de la page du dashboard.
- Ajout des pictogrammes de programme, température, rinçages, essorage et
  Bubble Soak issus du widget SmartThings.
- Interprétation des données d'entretien du lave-linge : alerte de nettoyage
  tambour, seuil et nombre de lavages depuis le dernier nettoyage.
- Simplification de la consommation aux valeurs utiles, conversion en kWh et
  neutralisation des puissances négatives non exploitables.

## 0.4.1

- Remplacement du bandeau LocalThings par le bandeau natif Jeedom
  `.widget-name`.
- Allègement de la page principale à trois états utiles au maximum, aux
  réglages actionnables et aux commandes principales de l'appareil.
- Répartition des données secondaires dans des pages Entretien, Consommation
  et Informations basées sur les onglets déjà fournis par Jeedom.
- Simplification des libellés d'état et de consommation générés par le
  mappeur.

## 0.4.0

- Ajout d’un widget LocalThings adaptatif pour le dashboard et l’affichage
  mobile, avec une présentation et une organisation propres à chaque famille
  d’appareils.
- Ajout du choix entre le widget du core Jeedom et le widget LocalThings dans
  la configuration de chaque équipement.
- Organisation des commandes natives Jeedom en état, programme et options,
  actions, mesures et informations complémentaires.
- Ajout du contrôle local de l’option Add Wash lorsqu’elle est annoncée comme
  modifiable par l’appareil.

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
