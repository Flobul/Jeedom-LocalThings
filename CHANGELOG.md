# Changelog

## 0.4.16

- Après une connexion DTLS sans certificat client réussie, la découverte poursuit
  la lecture des états : `/device/0`, puis les ressources annoncées individuellement
  si nécessaire, avec délais et nombre de requêtes limités.
- Création en **lecture seule** lorsqu’au moins un état exploitable est reçu,
  avec mémorisation du mode de connexion pour les rafraîchissements. La seule
  lecture du répertoire ou des métadonnées ne suffit pas à créer un équipement.
- Accès indiqué dans la fiche équipement ; aucune action de pilotage générée
  dans ce mode. Les anciennes actions sont masquées et leur exécution refusée,
  sans supprimer les références des scénarios.
- Logs du nombre de représentations et d’états reçus, des codes CoAP et des
  échecs de lecture, sans afficher le contenu des réponses métier.
- Vérification du certificat serveur conservée ; aucun reset, changement
  d’association ou transfert de propriété. Lecture réelle de la PAC AE080BXYDGG
  encore à valider avec les prochains logs utilisateur.

## 0.4.15

- Après un refus du certificat client (`unknown_ca`), la découverte tente
  automatiquement une session DTLS sans identité cliente, avec vérification de
  la chaîne du certificat serveur et paramètres cryptographiques Samsung.
- Ce mode expérimental autorise uniquement les lectures GET et les ACK. Le
  transport refuse les écritures ; il ne modifie pas l'association SmartThings.
- Recherche des ressources `x.com.samsung.provisioninginfo` annoncées par OCF,
  sur le canal public puis, si la connexion réussit, sur le canal sécurisé.
  Aucun chemin de provisioning propre à un autre modèle n'est imposé.
- Journalisation des capacités `0x4000` et `0x8000`, de la présence et du format
  du nonce, de l'autorisation supplémentaire et de l'état OCF. Les valeurs du
  nonce, les identifiants et les clés ne sont pas journalisés.
- Étapes, échecs et bilan regroupés dans les logs `[OCF sans certificat]` et
  `[OCF provisioning]`, avec délais bornés et arrêt par le bouton de découverte.
- Renforcement de la corrélation des réponses CoAP sécurisées : une requête
  renvoyée ou une réponse ne correspondant pas au token/MID attendu est ignorée.
- Le transfert de propriété et l'installation d'OwnerPSK ne sont pas activés.
  Une session de diagnostic réussie ne crée pas à elle seule un équipement
  pilotable. La compatibilité de la PAC AE080BXYDGG reste à valider sur appareil.

## 0.4.14

- Correction du diagnostic OCF public : un code de requête comme `0.01` (GET)
  n'est plus accepté comme une réponse, même lorsque le token correspond.
  Les messages concernés ne sont ni acquittés ni utilisés pour fixer le port
  distant ; l'attente d'une réponse valide continue dans le délai prévu.
- Ajout de compteurs de messages sans code de réponse et d'échos strictement
  identiques à la requête envoyée, sans journalisation du contenu des paquets.
- Validation de la structure des acquittements vides avant leur prise en compte.
- Cette correction ne résout pas le refus du certificat client `unknown_ca`
  observé sur la PAC Samsung AE080BXYDGG.

## 0.4.13

- Découverte des ports DTLS annoncés par `/oic/res` sur UDP `5683`, y compris
  hors de la plage habituelle. Seules les adresses IPv4 correspondant à l'appareil
  interrogé sont retenues ; les endpoints IPv6 non pris en charge sont signalés.
- Correction des lectures OCF publiques lorsque la réponse arrive d'un autre
  port UDP : corrélation CoAP et maintien du même pair pour les blocs suivants.
- Sondage parallèle des ports par un ClientHello initial, retransmis à
  l'identique si nécessaire. Le sondage s'arrête à la première réponse DTLS valide
  sans renvoyer le cookie ni présenter de certificat client.
- Authentification limitée au port sélectionné après le sondage. Plusieurs
  réponses sans préférence connue ni endpoint annoncé unique donnent un résultat
  ambigu, au lieu de choisir arbitrairement le premier port.
- Diagnostic distinguant l'absence de réponse, la détection DTLS, le refus du
  certificat et l'accès aux ressources. Les lectures publiques indiquent leur
  étape et leurs compteurs, sans exposer les identifiants OCF ou les clés.
- Le refus `unknown_ca` reste un blocage d'authentification : cette version
  n'ajoute pas le transfert de propriété OCF ni OwnerPSK, et ne garantit pas
  encore la prise en charge de la PAC Samsung AE080BXYDGG.

## 0.4.12

- Après un refus du certificat client (`unknown_ca`), lecture automatique des
  ressources OCF publiques `/oic/sec/doxm`, `/oic/sec/pstat` et `/oic/p` sur UDP
  `5683`, avec délai limité et prise en charge des réponses fragmentées.
- Journalisation limitée aux méthodes de transfert de propriété annoncées,
  types de credentials, états OCF, modèle et versions. Aucun UUID, propriétaire,
  nonce, numéro de série ou contenu de clé n'est repris dans ce diagnostic.
- Ce diagnostic ne modifie ni l'association SmartThings ni la configuration de
  l'appareil. Il ne résout pas à lui seul l'authentification de la PAC Samsung.

## 0.4.11

- État et paramètres de découverte transférés vers le cache Jeedom, avec
  expiration, protection contre les démarrages concurrents et récupération des
  tâches interrompues. Suppression de l'utilisation des fichiers de découverte
  dans `/tmp`.
- Bouton **Découverte en cours** animé, reprise du suivi au chargement de la
  page et arrêt après confirmation. L'annulation ferme les échanges du worker
  et conserve les appareils déjà ajoutés.
- Diagnostic explicite du certificat client refusé par l'appareil (`unknown_ca`,
  alerte 48), conservé dans le bilan final au lieu du dernier timeout rencontré.
- Rétablissement du transport OpenSSL direct pour les ports `49152-49160`.
  Le relais ajouté en 0.4.10 est réservé au port `5684`, concerné par le
  changement de port de réponse observé sur la PAC Samsung.
- Réduction du délai de négociation par port à 2 secondes pendant la découverte
  réseau ; **Ajouter par IP** conserve 5 secondes par port. L'adresse en cours
  d'analyse apparaît dans la progression.
- Isolation des erreurs PHP de rendu des widgets LocalThings : affichage d'un
  message de remplacement et journalisation de l'équipement, du fichier et de
  la ligne. Une erreur PHP de la tâche de découverte est également journalisée
  et termine son état de progression.

## 0.4.10

- Gestion automatique des appareils qui répondent à la négociation DTLS depuis
  un autre port UDP, notamment après un contact sur `5684`. Le port source local
  et la vérification des certificats sont conservés. Le port de réponse est
  détecté à chaque connexion, sans être enregistré comme port de découverte.
- Diagnostic intégré à **Découvrir** et **Ajouter par IP** : première réponse,
  changement de port, bilan des datagrammes et étape en échec dans le journal
  `localthings`, sans commande à exécuter sur Jeedom ni nouvelle dépendance.
- La compatibilité complète de la PAC Samsung concernée reste à confirmer sur
  l'appareil : la correction du transport ne garantit pas l'authentification
  ni la présence des ressources LocalThings.

## 0.4.9

- Ajout du port UDP `5684` à la découverte automatique et à l’ajout manuel,
  en complément des ports `49152-49160`.
- Mise à jour des messages de découverte et de la documentation des ports
  pris en charge.

## 0.4.8

- Prise en compte du niveau de log Jeedom par le démon persistant sans
  redémarrage manuel, grâce au rechargement de la configuration à chaque
  passage de la boucle de rafraîchissement.
- Protection du répertoire privé `data/` contre les accès HTTP tout en
  maintenant les certificats et clés DTLS hors du dépôt Git.
- Correction de l’unité des timestamps de relevé énergétique : les commandes
  sont conservées comme valeurs Unix, sans unité de consommation.
- Suppression des doublons d’état, de temps restant et de progression lorsque
  l’appareil publie simultanément les ressources opérationnelles propriétaire
  et OCF standard.

## 0.4.7

- Ajout d’une PHPDoc complète sur les classes, méthodes et fonctions PHP afin
  d’expliciter les contrats internes, les formats échangés et les effets de bord.
- Allègement du rafraîchissement courant : les ressources OCF d’identité ne
  sont plus relues à chaque passage et restent réservées à la découverte.
- Validation prioritaire du certificat TLS de la passerelle Samsung, avec
  repli journalisé sans validation pour les installations incompatibles.
- Verrouillage du bundle communautaire sur une révision et une empreinte
  SHA-256 connues, et validation des feuilles clientes contre l’autorité active.
- Validation du mappeur sur les 81 fixtures publiques du projet LocalThings,
  avec prise en charge des familles Air Monitor, EHS et table de cuisson gaz.
- Extraction lisible des mesures PM10, PM2,5, PM1, CO₂ et qualité de l’air
  contenues dans les tableaux de capteurs Samsung.
- Ajout des commandes connues des pompes à chaleur EHS : consignes de
  température, eau chaude sanitaire, modes, absence et mise en sourdine.
- Amélioration des libellés des commandes EHS et du mode Ne pas déranger des
  analyseurs d’air afin de conserver une présentation compréhensible.
- Contrôles Santé étendus à PHP CLI, cURL, OpenSSL, `proc_open` et `exec`.
- Ajout d’une validation GitHub PHP, JSON, Bash, cohérence de version et
  absence de fichiers système indésirables dans les livraisons.

## 0.4.6

- Séparation du rafraîchissement automatique en deux intervalles : un pour
  les appareils en ligne et un pour les appareils hors ligne.
- Sélection dynamique de la cadence pour chaque équipement selon le résultat
  de sa dernière communication, avec retour automatique à la cadence en ligne
  dès que l'appareil répond à nouveau.
- Migration de l'ancien `poll_interval` vers le nouvel intervalle en ligne ;
  l'intervalle hors ligne est initialisé à 5 minutes afin de limiter les
  tentatives inutiles.
- Affichage des deux cadences effectives dans la page Santé.
- Normalisation des textes PHP et JavaScript selon les mécanismes de
  traduction du core Jeedom.
- Correction du nom des équipements dans la page Santé et présentation des
  alarmes Samsung sous forme d’un résumé lisible dans l’onglet Entretien.
- État hors ligne fiable sur les widgets : valeurs de fonctionnement masquées,
  commandes grisées et bloquées côté serveur jusqu’au prochain rafraîchissement.
- Renommage de « Départ différé » en « Fin différée » sans modifier son
  identifiant Jeedom, et amélioration visuelle du curseur natif Jeedom.
- Suppression des doublons autour des curseurs, de l’historique et des mises à
  jour de commandes au profit des mécanismes fournis par le core Jeedom.

## 0.4.5

- Harmonisation de la page de configuration avec les autres plugins Jeedom :
  branche, source, version installée et accès directs à la documentation, au
  changelog et au Community.
- Remplacement du champ de découverte réseau par la saisie CIDR multiligne
  utilisée dans le plugin Tasmota, sans changer la clé `discovery_networks`.
- Mise en évidence des paramètres modifiés tant que la configuration n'a pas
  été enregistrée, au moyen de `printPluginConfiguration()`.
- Conservation locale de la suite de tests pour le développement, avec
  exclusion complète du dossier `tests/` du dépôt Git.
- Décodage des alarmes Samsung reçues sous forme de JSON : les entrées
  inactives sont ignorées et chaque alarme active affiche son type traduit
  ainsi que son heure de déclenchement dans l'onglet Entretien.
- Animation et coloration du temps restant lorsqu'un appareil fonctionne,
  avec mise en évidence de sa progression.
- Affichage distinct des alertes de lessive et d'adoucissant, sans générer de
  commande d'écriture lorsque le firmware ne fournit qu'un état en lecture.
- Ajout d'une barre de progression aux commandes d'information numériques
  exprimées en pourcentage, synchronisée avec le widget natif de la commande.
- Ajout de l'intervalle de rafraîchissement de 10 secondes au moyen d'une
  tâche cron Jeedom en mode démon, tout en conservant les intervalles en
  minutes existants.

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
- Exclusion des tests des archives installées dans Jeedom.

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
