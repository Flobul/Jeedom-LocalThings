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

Les appareils doivent exposer un port CoAP-DTLS dans la plage UDP
`49152-49160`. Les générations plus anciennes qui n'exposent que le port
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

Le plugin n'ouvre aucun port réseau. Les clés privées sont stockées dans
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
