# LocalThings pour Jeedom

[![License](https://img.shields.io/github/license/Flobul/Jeedom-LocalThings?style=flat-square)](LICENSE)
[![Language](https://img.shields.io/github/languages/top/Flobul/Jeedom-LocalThings?style=flat-square)](https://github.com/Flobul/Jeedom-LocalThings)
[![Last commit](https://img.shields.io/github/last-commit/Flobul/Jeedom-LocalThings?style=flat-square)](https://github.com/Flobul/Jeedom-LocalThings/commits)
[![Open issues](https://img.shields.io/github/issues/Flobul/Jeedom-LocalThings?style=flat-square)](https://github.com/Flobul/Jeedom-LocalThings/issues)
[![Open pull requests](https://img.shields.io/github/issues-pr/Flobul/Jeedom-LocalThings?style=flat-square)](https://github.com/Flobul/Jeedom-LocalThings/pulls)

Plugin Jeedom de contrôle local des appareils Samsung compatibles avec
LocalThings. Les échanges se font directement sur le réseau local en
CoAP sur DTLS, sans appel à l'API SmartThings pour lire les états ou envoyer
les commandes.

> Le plugin est une première implémentation. Le mappeur est exercé sur les
> captures amont, mais chaque famille d'appareils devra encore être validée
> sur du matériel réel.

## Architecture

- Les codecs CBOR et CoAP, la découverte, les certificats, le mapping des
  ressources et les requêtes sont implémentés en PHP.
- Les crons Jeedom lisent périodiquement les appareils, sur le même principe
  que le plugin SmartThings. Une action ouvre une session locale, écrit sa
  valeur puis relit l'état.
- Les ressources OCF d'identité sont interrogées pendant la découverte. Les
  rafraîchissements courants relisent uniquement l'état `/device/0` et
  réutilisent les métadonnées stables déjà enregistrées dans Jeedom.
- PHP ne proposant pas de transport `dtls://`, le plugin pilote
  `openssl s_client` par `proc_open`. OpenSSL assure uniquement le chiffrement
  DTLS ; aucun service local intermédiaire n'est démarré.
- La découverte réseau s'exécute dans une tâche PHP CLI asynchrone afin de ne
  pas bloquer l'interface Jeedom.

## Installation

Prérequis : Jeedom 4.4 ou plus récent, Debian 12, PHP CLI et OpenSSL avec
DTLS 1.2.

1. Installez les dépendances depuis la page du plugin.
2. Ouvrez la configuration de LocalThings.
3. Installez le bundle communautaire ou fournissez manuellement la chaîne PEM
   et sa clé privée.
4. Renseignez les réseaux CIDR à analyser si le sous-réseau `/24` de l'adresse
   interne Jeedom ne convient pas.
5. Cliquez sur **Découvrir**.

La découverte ajoute un équipement Jeedom par appareil. Le client PHP lit les
ressources réellement exposées et crée les commandes `info` et `action`
correspondantes. Une adresse peut aussi être analysée manuellement.

Pendant une recherche, le bouton affiche **Découverte en cours** avec une icône
animée, y compris après rechargement de la page. Cliquez dessus pour demander
l'arrêt : une confirmation s'affiche, puis le bouton indique **Arrêt en cours…**
jusqu'à la fermeture de la tâche. Les appareils déjà ajoutés sont conservés.
L'ajout par IP est indisponible tant qu'une recherche est active.

L'état et les paramètres de découverte sont conservés dans le cache Jeedom,
sans fichiers `discovery-status.json` ou `discovery-job-*.json` gérés dans `/tmp`.
Un verrou vide dans le répertoire privé `data/` empêche les démarrages simultanés.

## Si un appareil n'est pas découvert

Renseignez son adresse dans **Adresse IPv4 de l’appareil**, puis cliquez sur
**Ajouter par IP** et attendez la fin de la recherche. Le plugin teste les ports
pris en charge et adapte automatiquement la connexion si l'appareil répond
depuis un autre port. Aucune commande à saisir et aucun outil supplémentaire
à installer.

Le relais est réservé au port `5684`. Les ports `49152-49160` et les ports
dynamiques annoncés utilisent le transport OpenSSL direct. La découverte commence
par lire les ports annoncés par l'appareil sur UDP `5683`, puis sonde les ports candidats en parallèle. Ce
sondage s'arrête au premier message DTLS, sans renvoyer de cookie ni présenter
un certificat. Une seule connexion authentifiée est ensuite tentée, avec un
délai de 2 secondes en découverte réseau ou 5 secondes pour **Ajouter par IP**.
Le plugin ouvre la session sur le port source réel de la réponse : certains
firmwares répondent depuis leur endpoint éphémère même lorsque la requête vise
`5684`. Plusieurs chemins menant au même port source sont dédupliqués ; seuls
plusieurs ports sources réellement distincts restent ambigus. L'adresse en cours
d'analyse apparaît dans la progression.

Si l'ajout échoue, transmettez le journal **localthings** depuis la configuration
du plugin. Avec le niveau de log **Info** ou **Debug**, il contient les ports
testés, la première réponse reçue, un éventuel changement de port, le résultat
de la négociation sécurisée et l'étape en échec. Les cookies DTLS et les clés
privées ne sont pas inclus dans ces nouveaux diagnostics.

Une réponse réseau ne suffit pas à garantir la compatibilité : l'appareil doit
aussi accepter les certificats du plugin et exposer les ressources attendues.

Si le diagnostic indique **certificat client refusé par l’appareil (unknown_ca)**,
la communication atteint l'appareil, mais celui-ci refuse le profil
d'authentification présenté par Jeedom. Le bilan conserve cette cause ; le plugin ne
poursuit pas les tentatives d'authentification sur tous les autres ports. Relancer la recherche ou désactiver la
vérification du certificat serveur ne résout pas ce refus. La prise en charge
d'un autre profil d'authentification doit être étudiée pour le modèle concerné.

En cas d’échec, le plugin lit automatiquement trois ressources OCF publiques
sur le port `5683`. Les lignes **[OCF public]** du journal indiquent les méthodes
annoncées, l'état OCF et, si accessibles, le modèle et ses versions. Les lectures
sont limitées à deux secondes par ressource ; un accès refusé ou une absence de
réponse sont également signalés. Aucune commande manuelle n'est nécessaire.
Ce diagnostic ne modifie pas l'association SmartThings et n'extrait aucune clé.
Les réponses venant d'un autre port UDP sont acceptées après corrélation CoAP ;
les blocs suivants restent liés au même pair. Le journal indique si l'échec
survient à la réception, à la corrélation, à l'assemblage ou au décodage.

Depuis la version **0.4.15**, un refus `unknown_ca` déclenche aussi une tentative
expérimentale **sans certificat client**, tout en vérifiant le certificat serveur.
Les lignes **[OCF sans certificat]** indiquent si cette connexion réussit, quelles
lectures sont autorisées et l'état de sécurité obtenu. Les lignes
**[OCF provisioning]** recherchent les méthodes de confirmation annoncées par
l'appareil ; le nonce n'est jamais affiché.

Pour tester : mettez le plugin à jour, utilisez **Ajouter par IP** et transmettez
le journal **localthings** complet, depuis le début jusqu'au bilan de découverte.
Le niveau **Info** suffit pour ces nouvelles étapes. Aucune commande ni dépendance
supplémentaire n'est nécessaire. La tentative s'arrête avec la découverte.

Ce mode ne fait que des lectures : aucun reset ni changement de propriétaire.
Depuis **0.4.16**, si cette connexion réussit, le plugin tente aussi de lire les
états de l’appareil. Lorsqu’il obtient des états exploitables, il crée l’équipement
**en lecture seule** et conserve ce mode pour les rafraîchissements. La fiche
équipement indique cet accès ; les commandes de pilotage restent désactivées.

Les lignes **[OCF lecture seule]** indiquent le nombre de ressources lues et
les réponses obtenues, sans leur contenu. Si seul le répertoire ou les informations
générales sont accessibles, aucun équipement n’est créé. La lecture réelle de
la PAC AE080BXYDGG reste à confirmer sur appareil.

Le [document OCF-PKI de SmartThings-Local](https://github.com/QuiteYellow/SmartThings-Local/blob/main/docs/ocf-pki-laundry.md)
décrit aussi une authentification OwnerPSK validée sur certains lave-linge.
L'autorisation préalable propre au modèle n'est pas un parcours public pris en
charge. LocalThings n'implémente pas ce transfert de propriété ni OwnerPSK :
la détection de la PAC AE080BXYDGG ne signifie donc pas encore qu'elle peut
être ajoutée et pilotée. La découverte LocalThings reste limitée à IPv4.

## Widgets

Chaque équipement peut utiliser soit le widget standard du core Jeedom, soit
le widget LocalThings depuis l'onglet **Équipement**. Le widget LocalThings
conserve le bandeau et l'apparence du core Jeedom. Sa page principale affiche
au maximum trois états utiles, les réglages actionnables et les commandes
principales de l'appareil.

Sur les appareils de lavage, les sélecteurs annoncés par la machine (cycle,
température, essorage, rinçages et options comme Bubble Soak ou Add Wash) sont
présentés ensemble avant les commandes de démarrage, pause et arrêt. Les autres
familles disposent de la même organisation adaptée à leurs réglages.

La commande **Options disponibles** correspond au catalogue technique
`supportedOptions` fourni par le firmware. Le mappeur l'utilise comme source
de secours pour construire le sélecteur de programme lorsque `editCourseList`
est absent ou vide. Elle reste disponible dans la configuration avancée pour
le diagnostic, mais son contenu hexadécimal n'est pas affiché dans le widget.

Les informations secondaires sont réparties dans des pages **Entretien**,
**Consommation** et **Informations**. Une page n'est proposée que si l'appareil
remonte des commandes correspondantes. La navigation utilise les onglets déjà
fournis par Jeedom et chaque commande conserve son widget natif.

Les valeurs techniques sont interprétées avant affichage lorsque leur sens est
connu. Pour un lave-linge, l'entretien indique notamment si un nettoyage du
tambour est recommandé, le seuil d'alerte et le nombre de lavages depuis le
dernier nettoyage. La consommation n'affiche que la puissance actuelle, la
consommation totale et, lorsqu'elle existe, celle du cycle. Les réglages de
lavage reprennent les pictogrammes du widget SmartThings pour faciliter leur
lecture. Les informations numériques historisées ouvrent directement
l'historique Jeedom au clic. La page Informations ne conserve que les états
secondaires utiles qui ne sont pas déjà affichés ailleurs.

La capacité Samsung commune `/alarms/vs/0` est également interprétée pour
toutes les familles d'appareils. Les entrées supprimées ou désactivées sont
ignorées ; les alarmes actives apparaissent dans **Entretien** sous une forme
lisible, par exemple « Température élevée — Début : 31/07/2026 23:05 », tout
en conservant le code brut en complément lorsqu'il n'est pas encore connu du
plugin. Les alarmes sont décodées de la même façon lorsque l'appareil les
renvoie directement comme tableau ou comme chaîne JSON.

Les options binaires sont regroupées en interrupteurs On/Off dont l'état suit
la commande d'information associée. La page Santé reprend les contrôles du
transport, des certificats et du rafraîchissement, puis détaille chaque
équipement. Un test de communication DTLS est également disponible après la
dernière erreur dans la configuration de l'équipement. Les unités explicites
des appareils sont normalisées pour Jeedom ; les mesures courantes reçoivent
une unité cohérente même lorsque le firmware ne l'annonce pas.
Les états **Lessive restante**, **Alerte de lessive** et leurs équivalents
pour l'adoucissant sont affichés séparément lorsqu'ils existent. Ils restent
en lecture seule si l'appareil ne publie aucune ressource modifiable : le
plugin ne déduit jamais une action d'écriture à partir d'un simple état.
Les informations numériques exprimées en pourcentage disposent également
d'une barre de progression qui suit les mises à jour natives Jeedom.

Le rafraîchissement automatique utilise deux cadences indépendantes. Chaque
équipement emploie l'intervalle **en ligne** tant que sa dernière communication
est réussie, puis l'intervalle **hors ligne** après un échec. Dès que
l'appareil répond à nouveau, la cadence en ligne est rétablie automatiquement.
Les deux intervalles peuvent descendre à 10 secondes grâce à une tâche cron
Jeedom en mode démon. Pour une nouvelle installation, les valeurs par défaut
sont de 1 minute en ligne et de 5 minutes hors ligne.

## Compatibilité

Les appareils doivent exposer un service CoAP-DTLS sur un port IPv4 annoncé
par OCF, ou sur le port UDP `5684` ou
dans la plage `49152-49160`, et accepter l’authentification utilisée par le
plugin. La présence d’un port ouvert ne garantit pas à elle seule la compatibilité.
Les générations plus anciennes qui n'exposent que le port
HTTPS `8888` ne sont pas prises en charge.

Familles reconnues par le mappeur embarqué : climatiseurs, analyseurs et
purificateurs d'air, déshumidificateurs, pompes à chaleur Samsung EHS,
sèche-linge, fours, micro-ondes, plaques de cuisson gaz ou induction, hottes,
cuisinières, lave-vaisselle, réfrigérateurs, lave-linge, purificateurs d'eau,
stations d'aspirateur et armoires AirDresser. Le typage et le mapping sont
contrôlés localement avec les fixtures publiques du projet `mbillow/localthings`.

Certaines écritures exigent que **Smart Control** soit activé sur l'appareil.
Le contournement proposé dans la configuration d'un équipement ne doit être
utilisé que pour les modèles qui acceptent explicitement ces commandes.

## Sécurité

Le plugin utilise un port UDP temporaire pour chaque session avec un appareil
et, pour le port `5684`, un relais limité à la boucle locale pour OpenSSL. Seules les réponses de
l'adresse interrogée sont relayées ; un changement de port initial exige une
réponse DTLS `HelloVerifyRequest` valide, puis le pair est fixé pour la session.
OpenSSL continue de vérifier le certificat distant.
Les clés privées sont stockées dans
`data/` avec des permissions restrictives et ne sont jamais retournées par
les diagnostics.

Le mécanisme d'authentification local repose sur les certificats décrits par
le projet LocalThings. L'installation simplifiée télécharge le bundle public
AC14K_M utilisé par l'outil amont ; l'installation manuelle permet de garder
la maîtrise de sa provenance. La feuille client RSA est générée localement et
signée en SHA-256, conformément au flux de configuration de LocalThings. La
chaîne AC14K_M comporte elle-même des éléments historiques acceptés uniquement
sur ce canal DTLS local.

Le bundle communautaire est téléchargé depuis une révision Git déterminée et
son empreinte SHA-256 est contrôlée avant installation. La lecture du
certificat public de la passerelle Samsung tente d'abord une validation TLS
complète. Si la chaîne système ne permet pas cette validation, le plugin
effectue une seconde tentative TLS sans validation et inscrit clairement ce
repli dans le journal afin de préserver la compatibilité.

N'utilisez le plugin que pour des appareils et un réseau dont vous êtes
propriétaire ou administrateur.

## Limites connues

- Un appareil Samsung n'accepte généralement qu'un seul client DTLS actif.
  Home Assistant LocalThings, les scripts de test et ce plugin ne doivent pas
  piloter simultanément le même appareil.
- Les appareils absents, éteints ou non compatibles peuvent ralentir une
  découverte réseau ; son exécution reste asynchrone.
- Le fonctionnement quotidien est local, mais la première installation
  simplifiée des certificats accède à GitHub et au certificat public de la
  passerelle Samsung.
- Les ressources inconnues sont conservées en informations de diagnostic,
  sans commande d'écriture hasardeuse.

## Licences

Le plugin est distribué sous AGPL-3.0-or-later. Le comportement protocolaire
est adapté des travaux de [mbillow/localthings](https://github.com/mbillow/localthings)
et [QuiteYellow/SmartThings-Local](https://github.com/QuiteYellow/SmartThings-Local), sous
licence MIT ; les textes correspondants sont conservés dans
`resources/attributions/`.

### PAC Samsung : état de validation

Les essais de la version 0.4.16 ont confirmé une connexion chiffrée à la PAC
AE080BXYDGG, mais les commandes « Accesspoint », « Items » et « Selfhealing »
ne représentent pas ses états de chauffage. La version 0.4.17 ne les utilise
plus pour conclure que l’appareil est pris en charge. Les équipements déjà
créés sont conservés ; un échec d’accès reste signalé.

Un retour CoAP **4.01** indique un accès non autorisé à la ressource demandée.
Il reste à établir une autorisation permettant la lecture des états, puis le
pilotage. Pour poursuivre le diagnostic depuis le plugin, activer **Debug**,
lancer **Ajouter par IP** et transmettre le journal de cette découverte.
Les types de ressources et les refus sont journalisés, sans leurs valeurs privées.
