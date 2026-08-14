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

## Compatibilité

Les appareils doivent exposer un port CoAP-DTLS dans la plage UDP
`49152-49160`. Les générations plus anciennes qui n'exposent que le port
HTTPS `8888` ne sont pas prises en charge.

Familles reconnues par le mappeur embarqué : climatiseurs, purificateurs
d'air, déshumidificateurs, sèche-linge, fours, micro-ondes, plaques de cuisson,
hottes, cuisinières, lave-vaisselle, réfrigérateurs, lave-linge,
purificateurs d'eau, stations d'aspirateur et armoires AirDresser.

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
est adapté des travaux de `mbillow/localthings` et `smartthings-local`, sous
licence MIT ; les textes correspondants sont conservés dans
`resources/attributions/`.
