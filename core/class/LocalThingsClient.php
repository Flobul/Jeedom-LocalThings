<?php

require_once __DIR__ . '/LocalThingsOcfDiagnostic.php';
require_once __DIR__ . '/LocalThingsDtlsProbe.php';
require_once __DIR__ . '/LocalThingsOcfOnboardingDiagnostic.php';

/**
 * Signale une commande reçue par l'appareil mais refusée ou non appliquée.
 */
class LocalThingsCommandRejectedException extends RuntimeException
{
}

/**
 * Orchestre la découverte, la lecture et l'écriture d'un appareil LocalThings.
 */
class LocalThingsDeviceClient
{
    public const AUTH_READ_ONLY = 'server_certificate_readonly';

    public const PROBE_PORTS = array(49154, 49155, 5684, 49152, 49153, 49156, 49157, 49158, 49159, 49160);

    // Samsung appliances need a short quiet period after a write. Reading the
    // target resource too soon can make the firmware restore its former value.
    private const WRITE_SETTLE_DELAY_US = 4500000;

    private $openssl;
    private $certificateStore;
    private $rootCaPath;
    private $mapper;
    private $lockDirectory;
    private $logger;

    /**
     * Initialise le client avec ses dépendances réseau et de mapping.
     *
     * @param string $openssl Chemin du binaire OpenSSL.
     * @param LocalThingsCertificateStore $certificateStore Magasin de certificats.
     * @param string $rootCaPath Autorité racine utilisée pour DTLS.
     * @param LocalThingsMapper|null $mapper Mappeur de ressources.
     * @param callable|null $logger Journaliseur facultatif.
     */
    public function __construct(
        $openssl,
        LocalThingsCertificateStore $certificateStore,
        $rootCaPath,
        $mapper = null,
        $logger = null
    ) {
        $this->openssl = (string) $openssl;
        $this->certificateStore = $certificateStore;
        $this->rootCaPath = (string) $rootCaPath;
        $this->mapper = $mapper ?: new LocalThingsMapper();
        $this->logger = is_callable($logger) ? $logger : null;
        $this->lockDirectory = $certificateStore->dataDirectory() . '/locks';
        if (!is_dir($this->lockDirectory)) {
            mkdir($this->lockDirectory, 0700, true);
        }
        @chmod($this->lockDirectory, 0700);
    }

    /**
     * Recherche un service LocalThings sur une adresse IPv4.
     *
     * @param string $host Adresse IPv4 cible.
     * @param int|null $preferredPort Port à essayer en priorité.
     * @param bool $exhaustive Essaie tous les ports connus.
     * @return array<string,mixed> Instantané complet de l'appareil.
     */
    public function probe($host, $preferredPort = null, $exhaustive = false)
    {
        $host = $this->validateHost($host);
        $this->log(
            'info',
            sprintf(__('[Discovery] Analyse de %s', __FILE__), $host)
            . ($exhaustive ? __(' (ports UDP 5684 et 49152-49160)', __FILE__) : '')
        );
        return $this->withHostLock($host, function () use ($host, $preferredPort, $exhaustive) {
            return $this->probeUnlocked($host, $preferredPort, $exhaustive);
        });
    }

    /**
     * Exécute la détection après acquisition du verrou propre à l'hôte.
     *
     * @param string $host Adresse IPv4 validée.
     * @param int|null $preferredPort Port prioritaire.
     * @param bool $exhaustive Essaie tous les ports connus.
     * @return array<string,mixed>
     */
    private function probeUnlocked($host, $preferredPort, $exhaustive)
    {
        $logger = function ($level, $message) { $this->log($level, $message); };
        $public = new LocalThingsOcfDiagnostic($host);
        $advertised = $public->discoverPorts($logger);
        $ports = self::buildProbeOrder($advertised, $preferredPort, true);
        $results = LocalThingsDtlsProbe::scan($host, $ports, $this->openssl);
        foreach ($results as $port => $result) {
            $this->log('info', '[DTLS probe] port=' . $port . ' ; réponse=' . $result['kind']
                . (isset($result['alert']) ? ' ; alerte=' . $result['alert'] : '')
                . ' ; ClientHello initiaux=' . $result['attempts'] . ' ; cookie non renvoyé');
        }
        // Une préférence enregistrée ou un unique endpoint annoncé départage les
        // réponses ; l'ordre d'arrivée des datagrammes ne décide jamais du port.
        $selectionPreference = $preferredPort;
        if ($selectionPreference === null && count($advertised) === 1) {
            $selectionPreference = $advertised[0];
        }
        try {
            $port = LocalThingsDtlsProbe::select($results, $selectionPreference);
        } catch (RuntimeException $exception) {
            $public->inspect($logger);
            throw $exception;
        }
        $this->log('info', '[Discovery] Service DTLS détecté sur le port ' . $port
            . ' ; authentification et accès aux ressources à vérifier');
        // Un seul handshake authentifié, après le sondage sans état. Le même
        // certificat refusé ne doit pas être présenté à tous les autres ports.
        $started = microtime(true);
        try {
            LocalThingsDiscovery::checkpoint();
            $snapshot = $this->readSnapshot($host, $port, $exhaustive ? 5.0 : 2.0, true);
            $this->log('info', sprintf(
                __('[Discovery] Appareil trouvé sur %1$s:%2$d en %3$d ms', __FILE__),
                $host, $port, $this->durationMs($started)
            ));
            return $snapshot;
        } catch (LocalThingsDiscoveryCancelled $exception) {
            throw $exception;
        } catch (Exception $exception) {
            $public->inspect($logger);
            if ($exception instanceof LocalThingsClientCertificateRejected) {
                $public->inspectProvisioning($logger);
                LocalThingsDiscovery::checkpoint(true);
                $transport = new LocalThingsDtlsClient($this->openssl, $host, $port,
                    self::sourcePort($host), '', '', '', $this->rootCaPath, null, true);
                $session = new LocalThingsSession($transport);
                $diagnostic = LocalThingsOcfOnboardingDiagnostic::inspect($session, $host, $logger,
                    function ($connectedSession) use ($host, $port) {
                        return $this->readSnapshot($host, $port, 5.0, true,
                            array('auth_mode' => self::AUTH_READ_ONLY), $connectedSession);
                    });
                if (isset($diagnostic['snapshot'])) {
                    $this->log('info', '[Discovery] États métier reçus ; création en lecture seule sans réassociation');
                    return $diagnostic['snapshot'];
                }
                if (!empty($diagnostic['connected'])) {
                    throw new RuntimeException('Connexion DTLS sans certificat client réussie, mais aucun état exploitable obtenu ; '
                        . 'équipement non créé. Consulter les lignes [OCF lecture seule].');
                }
            }
            $this->log('warning', '[Discovery] Service détecté, ajout impossible : ' . $exception->getMessage());
            throw $exception;
        }
    }

    /**
     * Relit l'état complet d'un appareil connu.
     *
     * @param string $host Adresse IPv4 cible.
     * @param int $port Port DTLS.
     * @param array<string,mixed> $knownDevice Métadonnées déjà enregistrées dans Jeedom.
     * @return array<string,mixed> Instantané courant.
     */
    public function refresh($host, $port, $knownDevice = array())
    {
        $host = $this->validateHost($host);
        return $this->withHostLock($host, function () use ($host, $port, $knownDevice) {
            return $this->readSnapshot($host, (int) $port, 12.0, false, $knownDevice);
        });
    }

    /**
     * Exécute une recette d'écriture sous verrou réseau.
     *
     * @param string $host Adresse IPv4 cible.
     * @param int $port Port DTLS.
     * @param array<string,mixed> $recipe Recette produite par le mappeur.
     * @param mixed $value Valeur demandée par Jeedom.
     * @param bool $bypassRemoteControl Ignore le contrôle Smart Control.
     * @return array<string,mixed> Résultat et nouveaux états mappés.
     */
    public function execute($host, $port, $recipe, $value, $bypassRemoteControl = false)
    {
        $host = $this->validateHost($host);
        return $this->withHostLock(
            $host,
            function () use ($host, $port, $recipe, $value, $bypassRemoteControl) {
                return $this->executeUnlocked(
                    $host,
                    $port,
                    $recipe,
                    $value,
                    $bypassRemoteControl
                );
            }
        );
    }

    /**
     * Écrit une valeur, la vérifie puis remappe les ressources actualisées.
     *
     * @param string $host Adresse IPv4 validée.
     * @param int $port Port DTLS.
     * @param array<string,mixed> $recipe Recette d'écriture.
     * @param mixed $value Valeur demandée.
     * @param bool $bypassRemoteControl Ignore le verrou Smart Control.
     * @return array<string,mixed>
     * @throws LocalThingsCommandRejectedException Si l'appareil refuse l'action.
     */
    private function executeUnlocked($host, $port, $recipe, $value, $bypassRemoteControl)
    {
        $this->log(
            'info',
            sprintf(__('[Command] Connexion à %1$s:%2$d', __FILE__), $host, (int) $port)
        );
        $session = $this->createSession($host, (int) $port);
        try {
            $session->connect(12.0);
            $resources = $this->readResources($session);
            $remoteControlEnabled = $this->mapper->remoteControlEnabled($resources);
            $this->log(
                'debug',
                sprintf(
                    __('[Command] Smart Control=%1$s, contournement=%2$s', __FILE__),
                    $remoteControlEnabled ? __('activé', __FILE__) : __('désactivé', __FILE__),
                    $bypassRemoteControl ? __('activé', __FILE__) : __('désactivé', __FILE__)
                )
            );
            if (!$bypassRemoteControl && !$remoteControlEnabled) {
                throw new LocalThingsCommandRejectedException(__('Smart Control est désactivé sur l’appareil', __FILE__));
            }
            $write = $this->mapper->buildWrite($recipe, $value, $resources);
            $href = '/' . implode('/', $write['path']);
            $this->log(
                'info',
                '[Command] POST ' . $href . ' body=' . $this->jsonForLog($write['body'])
            );
            list($code, $response) = $session->post($write['path'], $write['body'], 15.0);
            if (($code >> 5) !== 2) {
                throw new LocalThingsCommandRejectedException(sprintf(
                    __('Écriture CoAP refusée (%s)', __FILE__),
                    LocalThingsCoap::formatCode($code)
                ));
            }
            $responseRepresentation = $this->decodeRepresentation($response);
            if ($responseRepresentation !== null) {
                $this->log(
                    'debug',
                    sprintf(
                        __('[Command] Réponse %1$s %2$s', __FILE__),
                        LocalThingsCoap::formatCode($code),
                        $this->jsonForLog($responseRepresentation)
                    )
                );
            }

            $verification = $this->verifyWrite(
                $session,
                $write['path'],
                $write['body']
            );
            if ($verification['matched'] === false) {
                throw new LocalThingsCommandRejectedException(sprintf(
                    __('Commande acquittée mais non appliquée par l’appareil sur %s', __FILE__),
                    $href
                ));
            }

            $representation = $verification['representation'];
            if (!is_array($representation)) {
                $representation = is_array($responseRepresentation)
                    ? $responseRepresentation
                    : array_merge((array) ($resources[$href] ?? array()), $write['body']);
            }
            $resources[$href] = array_merge((array) ($resources[$href] ?? array()), $representation);
            if ($readOnly) {
                $resources = array_filter($resources, function ($rep, $href) {
                    return self::isBusinessResource($href, $rep);
                }, ARRAY_FILTER_USE_BOTH);
            }
            $mapped = $this->mapper->map($resources);
            return array(
                'success' => true,
                'code' => LocalThingsCoap::formatCode($code),
                'response_size' => strlen($response),
                'resources' => $resources,
                'entities' => $mapped['entities'],
                'states' => $mapped['states'],
            );
        } finally {
            $session->close();
        }
    }

    /**
     * Relit une ressource après stabilisation pour confirmer l'écriture.
     *
     * @param LocalThingsSession $session Session CoAP active.
     * @param string[] $path Chemin écrit.
     * @param array<string,mixed> $expected Valeurs attendues.
     * @return array{matched:bool|null,representation:array<string,mixed>|null}
     */
    private function verifyWrite(LocalThingsSession $session, $path, $expected)
    {
        $this->log(
            'debug',
            sprintf(
                __('[Command] Stabilisation pendant %s s avant vérification', __FILE__),
                $this->formatDelaySeconds(self::WRITE_SETTLE_DELAY_US)
            )
        );
        usleep(self::WRITE_SETTLE_DELAY_US);

        try {
            list($code, $payload) = $session->get($path, 10.0);
            if (($code >> 5) !== 2) {
                $this->log(
                    'warning',
                    sprintf(
                        __('[Command] Vérification GET refusée : %s', __FILE__),
                        LocalThingsCoap::formatCode($code)
                    )
                );
                return array('matched' => null, 'representation' => null);
            }
            $representation = $this->decodeRepresentation($payload);
            if (!is_array($representation)) {
                $this->log(
                    'warning',
                    __('[Command] Réponse de vérification CBOR invalide', __FILE__)
                );
                return array('matched' => null, 'representation' => null);
            }
            $matched = $this->representationContains($representation, $expected);
            $this->log(
                $matched ? 'info' : 'warning',
                sprintf(
                    __('[Command] Vérification /%1$s expected=%2$s actual=%3$s applied=%4$s', __FILE__),
                    implode('/', $path),
                    $this->jsonForLog($expected),
                    $this->jsonForLog($representation),
                    $matched ? 'yes' : 'no'
                )
            );
            if ($matched) {
                return array('matched' => true, 'representation' => $representation);
            }
        } catch (Exception $exception) {
            $this->log(
                'warning',
                __('[Command] Vérification indisponible : ', __FILE__) . $exception->getMessage()
            );
            return array('matched' => null, 'representation' => null);
        }

        $this->log(
            'warning',
            sprintf(
                __('[Command] Écriture non appliquée après stabilisation; expected=%1$s actual=%2$s', __FILE__),
                $this->jsonForLog($expected),
                $this->jsonForLog($representation)
            )
        );
        return array('matched' => false, 'representation' => $representation);
    }

    /**
     * Formate une durée en microsecondes pour les journaux.
     *
     * @param int $microseconds Durée en microsecondes.
     * @return string Durée en secondes sans zéros superflus.
     */
    private function formatDelaySeconds($microseconds)
    {
        return rtrim(rtrim(number_format(((int) $microseconds) / 1000000, 1, '.', ''), '0'), '.');
    }

    /**
     * Décode une représentation CBOR seulement lorsqu'elle est associative.
     *
     * @param string $payload Charge utile CBOR.
     * @return array<string,mixed>|null
     */
    private function decodeRepresentation($payload)
    {
        if (!is_string($payload) || $payload === '') {
            return null;
        }
        try {
            $decoded = LocalThingsCbor::decode($payload);
            return is_array($decoded) ? $decoded : null;
        } catch (Exception $exception) {
            return null;
        }
    }

    /**
     * Vérifie qu'une représentation contient toutes les valeurs attendues.
     *
     * @param mixed $actual Représentation relue.
     * @param mixed $expected Sous-ensemble attendu.
     * @return bool
     */
    private function representationContains($actual, $expected)
    {
        if (!is_array($actual) || !is_array($expected)) {
            return false;
        }
        foreach ($expected as $field => $value) {
            if (!array_key_exists($field, $actual) || !$this->valuesEquivalent($actual[$field], $value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Compare récursivement deux valeurs en tolérant les formes du firmware.
     *
     * @param mixed $actual Valeur reçue.
     * @param mixed $expected Valeur attendue.
     * @return bool
     */
    private function valuesEquivalent($actual, $expected)
    {
        if (is_array($expected)) {
            if (!is_array($actual)) {
                return false;
            }
            if ($this->isList($expected)) {
                foreach ($expected as $item) {
                    $found = false;
                    foreach ($actual as $actualItem) {
                        if ($this->valuesEquivalent($actualItem, $item)) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        return false;
                    }
                }
                return true;
            }
            return $this->representationContains($actual, $expected);
        }
        if (is_bool($actual) || is_bool($expected)) {
            return filter_var($actual, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                === filter_var($expected, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }
        if (is_numeric($actual) && is_numeric($expected)) {
            return abs((float) $actual - (float) $expected) < 0.000001;
        }
        return (string) $actual === (string) $expected;
    }

    /**
     * Indique si une valeur est une liste PHP à clés consécutives.
     *
     * @param mixed $value Valeur à tester.
     * @return bool
     */
    private function isList($value)
    {
        if (!is_array($value)) {
            return false;
        }
        if (count($value) === 0) {
            return true;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Encode une valeur en JSON compact et sûr pour le journal.
     *
     * @param mixed $value Valeur à encoder.
     * @return string
     */
    private function jsonForLog($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '[unserializable]';
    }

    /**
     * Recherche un binaire OpenSSL compatible avec le transport requis.
     *
     * @return string Chemin exécutable, ou chaîne vide.
     */
    public static function findOpenSsl()
    {
        $configured = '';
        if (class_exists('config')) {
            $configured = trim((string) config::byKey('openssl_path', 'localthings', ''));
        }
        foreach (array($configured, '/usr/bin/openssl', '/usr/local/bin/openssl', '/opt/homebrew/bin/openssl') as $candidate) {
            if (
                $candidate !== ''
                && is_executable($candidate)
                && self::supportsDtls($candidate)
            ) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Vérifie les options DTLS nécessaires sur un binaire OpenSSL.
     *
     * @param string $openssl Chemin de l'exécutable.
     * @return bool
     */
    public static function supportsDtls($openssl)
    {
        if (!is_executable($openssl) || !function_exists('proc_open')) {
            return false;
        }
        $descriptor = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $pipes = array();
        $process = @proc_open(
            array($openssl, 's_client', '-help'),
            $descriptor,
            $pipes,
            null,
            null,
            array('bypass_shell' => true, 'suppress_errors' => true)
        );
        if (!is_resource($process)) {
            return false;
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return strpos($output, '-dtls1_2') !== false
            && strpos($output, '-cert_chain') !== false
            && strpos($output, '-bind') !== false;
    }

    /**
     * Construit l'instantané métier d'un appareil à partir de ses ressources.
     *
     * @param string $host Adresse IPv4 validée.
     * @param int $port Port DTLS.
     * @param float $handshakeTimeout Délai du handshake.
     * @param bool $includeIdentity Lit les ressources OCF d'identité pendant une découverte.
     * @param array<string,mixed> $knownDevice Métadonnées conservées lors d'un rafraîchissement léger.
     * @return array<string,mixed>
     */
    private function readSnapshot($host, $port, $handshakeTimeout, $includeIdentity = true, $knownDevice = array(), $connectedSession = null)
    {
        if ((int) $port < 1 || (int) $port > 65535) {
            throw new InvalidArgumentException(__('Port LocalThings invalide', __FILE__));
        }
        $readOnly = ($knownDevice['auth_mode'] ?? '') === self::AUTH_READ_ONLY;
        $session = $connectedSession ?: $this->createSession($host, (int) $port, $readOnly);
        $stage = 'négociation DTLS';
        try {
            $this->log(
                'debug',
                sprintf(
                    __('[DTLS] Négociation avec %1$s:%2$d, timeout=%3$s s', __FILE__),
                    $host,
                    (int) $port,
                    (string) (float) $handshakeTimeout
                )
            );
            $session->connect($handshakeTimeout);
            $stage = 'lecture CoAP /device/0';
            $resources = $readOnly ? $this->readOnlyResources($session) : $this->readResources($session);
            $stage = 'identité et commandes';
            $identity = array();
            if ($includeIdentity) {
                usleep(200000);
                $identity = $this->readIdentity($session);
            }
            $information = $resources['/information/vs/0'] ?? array();
            $serial = self::normalizeIdentifier(
                $information['x.com.samsung.da.serialNum'] ?? ''
            );
            if ($serial === '') {
                $serial = self::normalizeIdentifier($knownDevice['serial'] ?? '');
            }
            $ocfDeviceId = self::normalizeIdentifier($identity['device_id'] ?? '');
            $knownDeviceId = self::normalizeIdentifier($knownDevice['device_id'] ?? '');
            $deviceId = $serial !== ''
                ? $serial
                : ($ocfDeviceId !== ''
                    ? $ocfDeviceId
                    : ($knownDeviceId !== '' ? $knownDeviceId : $host . ':' . $port));
            $deviceType = $this->mapper->deviceType($resources, $identity);
            if ($deviceType === 'unknown' && !empty($knownDevice['device_type'])) {
                $deviceType = (string) $knownDevice['device_type'];
            }
            $model = trim(explode('|', (string) ($identity['model'] ?? ''), 2)[0]);
            if ($model === '') {
                $model = explode('|', (string) ($information['x.com.samsung.da.modelNum'] ?? ''), 2)[0];
            }
            if ($model === '') {
                $model = trim((string) ($knownDevice['model'] ?? ''));
            }
            $name = trim((string) ($identity['name'] ?? ''));
            if ($name === '') {
                $name = explode('/', (string) ($information['x.com.samsung.da.description'] ?? ''), 2)[0];
            }
            if ($name === '') {
                $name = trim((string) ($knownDevice['name'] ?? ''));
            }
            if ($name === '') {
                $name = 'Samsung ' . str_replace('_', ' ', $deviceType);
            }
            if ($readOnly) {
                $resources = array_filter($resources, function ($rep, $href) {
                    return self::isBusinessResource($href, $rep);
                }, ARRAY_FILTER_USE_BOTH);
            }
            $mapped = $this->mapper->map($resources);
            if ($readOnly) {
                foreach ($mapped['entities'] as &$entity) { $entity['actions'] = array(); }
                unset($entity);
                $businessResources = array_filter($resources, function ($href) {
                    return strpos($href, '/information/') !== 0;
                }, ARRAY_FILTER_USE_KEY);
                $business = $this->mapper->map($businessResources);
                if (!$business['entities']) {
                    throw new RuntimeException('Session DTLS établie mais aucun état de fonctionnement reconnu ; les informations réseau et de maintenance ne suffisent pas');
                }
                $this->log('info', '[OCF lecture seule] ressources=' . count($resources)
                    . ' ; états reconnus=' . count($mapped['states']) . ' ; actions désactivées');
            }
            $snapshotLog = $includeIdentity
                ? __('[Discovery] Identité reçue : modèle=%1$s, type=%2$s, série=%3$s, identifiant=%4$s, ressources=%5$d, commandes=%6$d', __FILE__)
                : __('[Refresh] État reçu : modèle=%1$s, type=%2$s, série=%3$s, identifiant=%4$s, ressources=%5$d, commandes=%6$d', __FILE__);
            $this->log(
                'info',
                sprintf(
                    $snapshotLog,
                    $model !== '' ? $model : __('inconnu', __FILE__),
                    $deviceType,
                    $serial !== '' ? $this->redactIdentifier($serial) : __('non communiquée', __FILE__),
                    $this->redactIdentifier($deviceId),
                    count($resources),
                    count($mapped['entities'])
                )
            );
            return array(
                'device' => array(
                    'device_id' => $deviceId,
                    'host' => $host,
                    'port' => (int) $port,
                    'auth_mode' => $readOnly ? self::AUTH_READ_ONLY : 'certificate',
                    'serial' => $serial,
                    'name' => $name,
                    'manufacturer' => trim((string) (
                        $identity['manufacturer']
                        ?? ($knownDevice['manufacturer'] ?? 'Samsung')
                    )) ?: 'Samsung',
                    'model' => $model,
                    'device_type' => $deviceType,
                    'connected' => true,
                    'last_update' => time(),
                    'last_error' => '',
                ),
                'entities' => $mapped['entities'],
                'states' => $mapped['states'],
                'resources' => $resources,
            );
        } catch (Exception $exception) {
            $this->log('info', '[Diagnostic] ' . $host . ':' . $port
                . ' ; étape en échec=' . $stage . ' ; ' . $exception->getMessage());
            throw $exception;
        } finally {
            $session->close();
        }
    }

    /**
     * Lit et indexe les représentations annoncées par `/device/0`.
     *
     * @param LocalThingsSession $session Session CoAP active.
     * @return array<string,array<string,mixed>> Ressources indexées par URI.
     */
    private function readResources(LocalThingsSession $session)
    {
        list($code, $payload) = $session->get(array('device', '0'), 35.0);
        $this->log(
            'info',
            '[CoAP] GET /device/0 -> ' . LocalThingsCoap::formatCode($code)
            . ', ' . strlen($payload) . ' octets'
        );
        if (($code >> 5) !== 2 || $payload === '') {
            throw new RuntimeException(sprintf(
                __('GET /device/0 a répondu %s', __FILE__),
                LocalThingsCoap::formatCode($code)
            ));
        }
        $decoded = LocalThingsCbor::decode($payload);
        if (!is_array($decoded)) {
            throw new RuntimeException(__('La réponse /device/0 est invalide', __FILE__));
        }
        $resources = array();
        foreach (array_slice($decoded, 1) as $entry) {
            if (!is_array($entry) || empty($entry['href']) || !isset($entry['rep']) || !is_array($entry['rep'])) {
                continue;
            }
            $resources[(string) $entry['href']] = $entry['rep'];
        }
        if (count($resources) === 0) {
            throw new RuntimeException(__('La réponse /device/0 ne contient aucune ressource', __FILE__));
        }
        $this->log(
            'debug',
            sprintf(__('[CBOR] /device/0 décodé : %d ressources', __FILE__), count($resources))
        );
        return $resources;
    }

    /** Lit les représentations annoncées, avec repli borné si /device/0 ne les agrège pas. */
    private function readOnlyResources(LocalThingsSession $session)
    {
        $resources = array();
        $stubs = array();
        $denied = 0;
        $timeouts = 0;
        $skipped = 0;
        $deadline = microtime(true) + 25.0;
        foreach (array('/device/0', '/oic/res') as $directoryPath) {
            LocalThingsDiscovery::checkpoint();
            try {
                list($code, $payload) = $session->get($directoryPath, 4.0);
                $this->log('info', '[OCF lecture seule] GET ' . $directoryPath . ' : CoAP '
                    . LocalThingsCoap::formatCode($code) . ' ; octets=' . strlen($payload));
                if ($code === 129 || $code === 131) { $denied++; }
                if (($code >> 5) !== 2 || strlen($payload) > 262144) { continue; }
                $decoded = LocalThingsCbor::decode($payload);
                if (!is_array($decoded)) { continue; }
                $entries = isset($decoded['links']) ? $decoded['links'] : $decoded;
                foreach (array_slice((array) $entries, 0, 128) as $entry) {
                    if (!is_array($entry)) { continue; }
                    $links = isset($entry['links']) ? $entry['links'] : array($entry);
                    foreach ((array) $links as $link) {
                        if (!is_array($link)) { continue; }
                        $href = $link['href'] ?? '';
                        if (!is_string($href) || strlen($href) > 160
                            || !preg_match('~^/(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-]+$~D', $href)
                            || strpos($href, '/oic/') === 0 || $href === '/device/0') { continue; }
                        $types = $link['rt'] ?? array();
                        if (in_array('x.com.samsung.provisioninginfo', (array) $types, true)
                            || self::isTechnicalResource($href, array('rt' => $types))) {
                            $skipped++;
                            continue;
                        }
                        if (isset($link['rep']) && is_array($link['rep']) && $link['rep']) {
                            $resources[$href] = $link['rep'];
                            if (!isset($resources[$href]['rt'])) { $resources[$href]['rt'] = $types; }
                        } else { $stubs[$href] = array('rt' => $types); }
                    }
                }
                if ($resources && !$stubs) { break; }
            } catch (LocalThingsDiscoveryCancelled $error) {
                throw $error;
            } catch (Throwable $error) {
                $this->log('info', '[OCF lecture seule] répertoire non exploitable : '
                    . LocalThingsOcfOnboardingDiagnostic::failureKind($error));
            }
        }
        // Read likely appliance states first, rather than the first 40 directory entries.
        uksort($stubs, function ($left, $right) use ($stubs) {
            return (int) self::isBusinessResource($right, $stubs[$right])
                <=> (int) self::isBusinessResource($left, $stubs[$left]);
        });
        $index = 0;
        $attempted = array();
        foreach ($stubs as $href => $unused) {
            if (isset($resources[$href])) { continue; }
            if ($index >= 96 || microtime(true) >= $deadline) { break; }
            LocalThingsDiscovery::checkpoint();
            $index++;
            $attempted[$href] = true;
            try {
                list($code, $payload) = $session->get($href, min(1.5, max(1, $deadline - microtime(true))));
                $this->log('debug', '[OCF lecture seule] ressource #' . $index
                    . ' ; référence=' . substr(hash('sha256', $href), 0, 12)
                    . ' ; types=' . self::resourceTypeSummary($unused['rt'] ?? array()) . ' : CoAP '
                    . LocalThingsCoap::formatCode($code) . ' ; octets=' . strlen($payload));
                if ($code === 129 || $code === 131) { $denied++; }
                if (($code >> 5) === 2 && strlen($payload) <= 65536) {
                    $rep = LocalThingsCbor::decode($payload);
                    if (is_array($rep) && $rep) {
                        if (!isset($rep['rt'])) { $rep['rt'] = $unused['rt'] ?? array(); }
                        $resources[$href] = $rep;
                    }
                }
            } catch (LocalThingsDiscoveryCancelled $error) {
                throw $error;
            } catch (Throwable $error) {
                $timeouts++;
                $this->log('debug', '[OCF lecture seule] ressource #' . $index . ' non lisible ; '
                    . LocalThingsOcfOnboardingDiagnostic::failureKind($error));
            }
        }
        $this->log('info', '[OCF lecture seule] représentations reçues=' . count($resources)
            . ' ; ressources individuelles testées=' . $index
            . ' ; refus d’accès=' . $denied . ' ; lectures en échec=' . $timeouts
            . ' ; ressources techniques ignorées=' . $skipped
            . ' ; individuelles non testées=' . count(array_diff_key($stubs, $resources, $attempted))
            );
        if (!$resources) {
            throw new RuntimeException($denied > 0
                ? 'Appareil joignable mais lecture non autorisée (CoAP 4.01/4.03) ; aucune donnée de fonctionnement accessible'
                : 'Aucune représentation métier accessible en lecture seule');
        }
        return $resources;
    }

    /** Les informations réseau ne prouvent pas l’accès aux fonctions de l’appareil. */
    private static function isTechnicalResource($href, $rep)
    {
        $metadata = $href . ' ' . implode(' ', (array) ($rep['rt'] ?? array())) . ' ' . implode(' ', array_keys($rep));
        return (bool) preg_match('/(?:accesspoint|selfhealing|provisioning|credential|wifi|ssid|information|network|firmware|certificate)/i', $metadata);
    }

    private static function isBusinessResource($href, $rep)
    {
        if (!is_array($rep) || self::isTechnicalResource($href, $rep)) { return false; }
        $metadata = $href . ' ' . implode(' ', (array) ($rep['rt'] ?? array())) . ' ' . implode(' ', array_keys($rep));
        return (bool) preg_match('/(?:power|temperature|thermostat|setpoint|heating|cooling|operation|fanspeed|airflow|humidity|consumption|energy|mode(?![a-z])|switch|contact|door|lock|alarm|remainingtime|progress)/i', $metadata);
    }

    private static function resourceTypeSummary($types)
    {
        $safe = array();
        foreach (array_slice((array) $types, 0, 8) as $type) {
            if (is_string($type) && strlen($type) <= 90
                && preg_match('/^(?:oic\.r\.|x\.com\.samsung\.)[a-zA-Z0-9_.-]+$/D', $type)
                && !preg_match('/[a-f0-9]{12,}/i', $type)) { $safe[] = $type; }
        }
        return $safe ? implode(',', $safe) : 'non standard ou absent';
    }

    /**
     * Lit les ressources OCF facultatives décrivant l'appareil.
     *
     * @param LocalThingsSession $session Session CoAP active.
     * @return array<string,mixed>
     */
    private function readIdentity(LocalThingsSession $session)
    {
        $profile = $this->getOptional($session, array('oic', 'p'));
        usleep(200000);
        $device = $this->getOptional($session, array('oic', 'd'));
        usleep(200000);
        $links = $this->getOptional($session, array('oic', 'res'));
        $types = $device['rt'] ?? array();
        if (is_string($types)) {
            $types = array($types);
        }
        return array(
            'manufacturer' => (string) ($profile['mnmn'] ?? 'Samsung'),
            'model' => (string) ($profile['mnmo'] ?? ''),
            'name' => (string) ($device['n'] ?? ''),
            'device_id' => (string) ($device['di'] ?? ($device['piid'] ?? '')),
            'device_types' => is_array($types) ? array_values($types) : array(),
            'raw' => array('/oic/p' => $profile, '/oic/d' => $device, '/oic/res' => $links),
        );
    }

    /**
     * Lit une ressource OCF facultative sans faire échouer la découverte.
     *
     * @param LocalThingsSession $session Session CoAP active.
     * @param string[] $path Chemin de la ressource.
     * @return array<string,mixed>
     */
    private function getOptional(LocalThingsSession $session, $path)
    {
        try {
            list($code, $payload) = $session->get($path, 10.0);
            if (($code >> 5) === 2 && $payload !== '') {
                $decoded = LocalThingsCbor::decode($payload);
                return is_array($decoded) ? $decoded : array();
            }
        } catch (LocalThingsDiscoveryCancelled $exception) {
            throw $exception;
        } catch (Exception $exception) {
            // Identity endpoints vary by firmware and are optional.
        }
        return array();
    }

    /**
     * Écarte les numéros de série absents ou factices envoyés par Samsung.
     *
     * @param mixed $value Identifiant brut.
     * @return string Identifiant exploitable, ou chaîne vide.
     */
    private static function normalizeIdentifier($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $compact = strtolower(preg_replace('/[^a-z0-9]/i', '', $value));
        if (
            $compact === ''
            || in_array($compact, array('nothing', 'none', 'null', 'unknown', 'undefined'), true)
            || preg_match('/^f+$/', $compact)
            || preg_match('/^0+$/', $compact)
        ) {
            return '';
        }
        return $value;
    }

    /**
     * Crée une session CoAP/DTLS munie du certificat propre à l'hôte.
     *
     * @param string $host Adresse IPv4 cible.
     * @param int $port Port DTLS.
     * @return LocalThingsSession
     */
    private function createSession($host, $port, $readOnly = false)
    {
        list($certificatePath, $keyPath) = $readOnly ? array('', '') : $this->certificateStore->mintLeaf('host:' . $host);
        $transport = new LocalThingsDtlsClient(
            $this->openssl,
            $host,
            $port,
            self::sourcePort($host),
            $certificatePath,
            $readOnly ? '' : $this->certificateStore->caCertificatePath(),
            $keyPath,
            $this->rootCaPath,
            $this->logger,
            $readOnly
        );
        return new LocalThingsSession($transport, $readOnly ? null : $this->logger);
    }

    /**
     * Valide et normalise une adresse IPv4.
     *
     * @param string $host Adresse à valider.
     * @return string
     */
    private function validateHost($host)
    {
        $host = trim((string) $host);
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException(__('Adresse IPv4 invalide', __FILE__));
        }
        return $host;
    }

    /**
     * Calcule un port UDP source stable à partir de l'adresse cible.
     *
     * @param string $host Adresse IPv4 cible.
     * @return int Port compris entre 40000 et 59999.
     */
    private static function sourcePort($host)
    {
        $long = ip2long($host);
        if ($long === false) {
            $offset = hexdec(substr(hash('sha256', $host), 0, 4));
        } else {
            $offset = $long & 0xFFFF;
        }
        return 40000 + ($offset % 20000);
    }

    /**
     * Construit l'ordre unique des ports à sonder.
     *
     * @param int[] $detectedPorts Ports détectés par UDP.
     * @param int|null $preferredPort Port mémorisé par l'équipement.
     * @param bool $exhaustive Ajoute tous les ports connus.
     * @return int[]
     */
    public static function buildProbeOrder($detectedPorts, $preferredPort = null, $exhaustive = false)
    {
        $ports = array();
        if ($preferredPort !== null && (int) $preferredPort > 0 && (int) $preferredPort <= 65535) {
            $ports[] = (int) $preferredPort;
        }
        foreach ((array) $detectedPorts as $port) {
            if ((int) $port > 0 && (int) $port <= 65535) {
                $ports[] = (int) $port;
            }
        }
        if ($exhaustive) {
            $ports = array_merge($ports, self::PROBE_PORTS);
        }
        return array_values(array_unique($ports));
    }

    /**
     * Sérialise les échanges utilisant le même port source local.
     *
     * @param string $host Adresse IPv4 cible.
     * @param callable $callback Traitement à exécuter sous verrou.
     * @return mixed Valeur retournée par le traitement.
     */
    private function withHostLock($host, callable $callback)
    {
        $path = $this->lockDirectory . '/port-' . self::sourcePort($host) . '.lock';
        $handle = fopen($path, 'c');
        if (!is_resource($handle)) {
            throw new RuntimeException(__('Création du verrou LocalThings impossible', __FILE__));
        }
        @chmod($path, 0600);
        $deadline = microtime(true) + 60.0;
        $locked = false;
        try {
            do {
                LocalThingsDiscovery::checkpoint();
                $locked = flock($handle, LOCK_EX | LOCK_NB);
                if (!$locked) {
                    usleep(100000);
                }
            } while (!$locked && microtime(true) < $deadline);
            if (!$locked) {
                throw new RuntimeException(__('Un autre échange LocalThings est déjà en cours', __FILE__));
            }
            return call_user_func($callback);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Transmet un message nettoyé au journaliseur injecté.
     *
     * @param string $level Niveau de journalisation.
     * @param string $message Message à écrire.
     * @return void
     */
    private function log($level, $message)
    {
        if ($this->logger === null) {
            return;
        }
        $message = preg_replace('/[\r\n]+/', ' ', (string) $message);
        call_user_func($this->logger, (string) $level, substr($message, 0, 1800));
    }

    /**
     * Calcule la durée écoulée depuis un instant haute résolution.
     *
     * @param float $started Valeur initiale de `microtime(true)`.
     * @return int Durée arrondie en millisecondes.
     */
    private function durationMs($started)
    {
        return (int) round((microtime(true) - (float) $started) * 1000);
    }

    /**
     * Masque un identifiant sensible avant journalisation.
     *
     * @param mixed $value Identifiant brut.
     * @return string
     */
    private function redactIdentifier($value)
    {
        $value = (string) $value;
        if ($value === '' || strpos($value, ':') !== false) {
            return $value !== '' ? $value : 'inconnu';
        }
        return strlen($value) <= 6
            ? str_repeat('*', strlen($value))
            : substr($value, 0, 3) . '…' . substr($value, -3);
    }
}

/** Arrêt coopératif demandé depuis l'interface Jeedom. */
class LocalThingsDiscoveryCancelled extends RuntimeException {}

/**
 * Valide les cibles et pilote la découverte réseau asynchrone.
 */
class LocalThingsDiscovery
{
    private const MAX_NETWORKS = 8;
    private const MAX_HOSTS = 1024;
    private const PING_WORKERS = 48;
    private const STATUS_KEY = 'localthings::discovery::status';
    private const JOB_PREFIX = 'localthings::discovery::job::';
    private const CACHE_TTL = 86400;
    private static $activeJob = null;
    private static $lastCheckpoint = 0.0;

    /**
     * Valide et canonicalise les réseaux IPv4 autorisés pour la découverte.
     *
     * @param string[] $values Réseaux au format CIDR.
     * @return string[] Réseaux uniques et normalisés.
     */
    public static function validateNetworks($values)
    {
        $networks = array();
        $hostCount = 0;
        foreach (array_slice((array) $values, 0, self::MAX_NETWORKS) as $value) {
            $value = trim((string) $value);
            if (!preg_match('#^([0-9.]+)/([0-9]{1,2})$#', $value, $matches)) {
                throw new InvalidArgumentException(__('Réseau CIDR invalide : ', __FILE__) . $value);
            }
            if (filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new InvalidArgumentException(__('Adresse de réseau invalide : ', __FILE__) . $value);
            }
            $prefix = (int) $matches[2];
            if ($prefix < 22 || $prefix > 30) {
                throw new InvalidArgumentException(__('Utilisez un réseau IPv4 compris entre /22 et /30 : ', __FILE__) . $value);
            }
            $network = self::canonicalNetwork($matches[1], $prefix);
            $networks[] = $network . '/' . $prefix;
            $hostCount += (1 << (32 - $prefix)) - 2;
        }
        $networks = array_values(array_unique($networks));
        if (count($networks) === 0) {
            throw new InvalidArgumentException(__('Aucun réseau de découverte configuré', __FILE__));
        }
        if ($hostCount > self::MAX_HOSTS) {
            throw new InvalidArgumentException(__('La découverte est limitée à ', __FILE__) . self::MAX_HOSTS . ' adresses');
        }
        return $networks;
    }

    /**
     * Valide une liste d'adresses IPv4 locales ciblées directement.
     *
     * @param string[] $values Adresses à valider.
     * @return string[] Adresses uniques.
     */
    public static function validateHosts($values)
    {
        $hosts = array();
        foreach ((array) $values as $value) {
            $value = trim((string) $value);
            if (
                filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                || strpos($value, '127.') === 0
                || $value === '0.0.0.0'
            ) {
                throw new InvalidArgumentException(__('Adresse IPv4 locale invalide : ', __FILE__) . $value);
            }
            $hosts[] = $value;
        }
        $hosts = array_values(array_unique($hosts));
        if (count($hosts) > self::MAX_HOSTS) {
            throw new InvalidArgumentException(__('La découverte est limitée à ', __FILE__) . self::MAX_HOSTS . ' adresses');
        }
        return $hosts;
    }

    /** Prépare les paramètres en cache et lance un worker identifié par un jeton. */
    public static function start($workerPath, $networks = array(), $hosts = array(), $logPath = null)
    {
        if (!function_exists('exec') || !function_exists('proc_open')) {
            throw new RuntimeException('exec et proc_open sont nécessaires à la découverte');
        }
        $hosts = count($hosts) > 0 ? self::validateHosts($hosts) : array();
        $networks = count($hosts) === 0 ? self::validateNetworks($networks) : array();
        $php = self::phpCli();
        return self::withStateLock(function () use ($workerPath, $networks, $hosts, $logPath, $php) {
            if (!empty(self::recoverStatus()['running'])) {
                throw new RuntimeException(__('Une découverte LocalThings est déjà en cours', __FILE__));
            }
            $jobId = bin2hex(random_bytes(16));
            cache::set(self::JOB_PREFIX . $jobId, array('hosts' => $hosts, 'networks' => $networks), 900);
            $status = self::newStatus(true);
            $status['job_id'] = $jobId;
            self::storeStatus($status);
            $command = escapeshellarg($php) . ' ' . escapeshellarg((string) $workerPath)
                . ' --job ' . escapeshellarg($jobId)
                . ($logPath ? ' >> ' . escapeshellarg((string) $logPath) : ' > /dev/null')
                . ' 2>&1 & echo $!';
            $output = array();
            $exitCode = 0;
            try {
                exec($command, $output, $exitCode);
                $pid = count($output) > 0 ? (int) trim((string) end($output)) : 0;
                if ($exitCode !== 0 || $pid <= 0) {
                    throw new RuntimeException(__('Le processus PHP de découverte n’a pas démarré', __FILE__));
                }
                $status['worker_pid'] = $pid;
                self::storeStatus($status);
                return $status;
            } catch (Throwable $error) {
                cache::delete(self::JOB_PREFIX . $jobId);
                $status['running'] = false;
                $status['finished'] = time();
                $status['errors'][] = $error->getMessage();
                self::storeStatus($status);
                throw $error;
            }
        });
    }

    /**
     * Exécute une tâche de découverte et actualise le cache Jeedom.
     *
     * @param string $jobId Jeton de la tâche conservée en cache.
     * @param callable $probe Sonde appelée pour chaque adresse candidate.
     * @param callable|null $logger Journaliseur facultatif.
     * @return void
     */
    public static function run($jobId, callable $probe, $logger = null)
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', (string) $jobId)) {
            throw new InvalidArgumentException(__('Tâche de découverte invalide', __FILE__));
        }
        $job = self::withStateLock(function () use ($jobId) {
            $job = cache::byKey(self::JOB_PREFIX . $jobId)->getValue(null);
            $status = self::rawStatus();
            if (!is_array($job) || ($status['job_id'] ?? '') !== $jobId || empty($status['running'])
                || !empty($status['claimed'])) {
                throw new RuntimeException('Tâche de découverte absente, expirée ou déjà démarrée');
            }
            $status['claimed'] = true;
            $status['worker_pid'] = getmypid();
            $status['heartbeat'] = time();
            self::storeStatus($status);
            cache::delete(self::JOB_PREFIX . $jobId);
            return $job;
        });
        self::$activeJob = $jobId;
        self::$lastCheckpoint = 0.0;
        try {
            $directHosts = self::validateHosts($job['hosts'] ?? array());
            self::log(
                $logger,
                'info',
                sprintf(
                    __('[Discovery] Tâche PHP démarrée, mode=%s', __FILE__),
                    count($directHosts) > 0 ? __('adresse directe', __FILE__) : __('réseau', __FILE__)
                )
            );
            self::checkpoint();
            if (count($directHosts) > 0) {
                $candidates = $directHosts;
            } else {
                $networks = self::validateNetworks($job['networks'] ?? array());
                self::log(
                    $logger,
                    'info',
                    sprintf(__('[Discovery] Réseaux analysés : %s', __FILE__), implode(', ', $networks))
                );
                $allHosts = self::expandNetworks($networks);
                $neighbours = array_values(array_intersect(self::neighbourHosts(), $allHosts));
                $reachable = self::reachableHosts(array_values(array_diff($allHosts, $neighbours)));
                // The ping sweep populates ARP/NDP even when an IoT device
                // deliberately ignores ICMP echo requests.
                $neighboursAfterSweep = array_values(array_intersect(
                    self::neighbourHosts(),
                    $allHosts
                ));
                $candidates = array_values(array_unique(array_merge(
                    $neighbours,
                    $reachable,
                    $neighboursAfterSweep
                )));
                self::log(
                    $logger,
                    'debug',
                    sprintf(
                        __('[Discovery] Détection réseau : voisins avant=%1$d, ping=%2$d, voisins après=%3$d', __FILE__),
                        count($neighbours),
                        count($reachable),
                        count($neighboursAfterSweep)
                    )
                );
            }
            sort($candidates, SORT_NATURAL);
            self::log(
                $logger,
                'info',
                sprintf(
                    __('[Discovery] %d adresse(s) candidate(s) après détection réseau', __FILE__),
                    count($candidates)
                )
            );
            $status = self::rawStatus();
            $status['candidates'] = count($candidates);
            $status['progress'] = 25;
            self::publish($status);

            $total = max(1, count($candidates));
            foreach ($candidates as $index => $host) {
                self::checkpoint(true);
                $status['current_host'] = $host;
                self::publish($status);
                self::log(
                    $logger,
                    'info',
                    sprintf(
                        __('[Discovery] Hôte %1$d/%2$d : %3$s', __FILE__),
                        $index + 1,
                        count($candidates),
                        $host
                    )
                );
                try {
                    $snapshot = call_user_func($probe, $host, count($directHosts) > 0);
                    $status['found'][] = $snapshot['device'] ?? array('host' => $host);
                    self::log(
                        $logger,
                        'info',
                        sprintf(__('[Discovery] %s enregistré dans Jeedom', __FILE__), $host)
                    );
                } catch (LocalThingsDiscoveryCancelled $exception) {
                    throw $exception;
                } catch (Exception $exception) {
                    self::log(
                        $logger,
                        count($directHosts) > 0 ? 'warning' : 'debug',
                        sprintf(
                            __('[Discovery] %1$s ignoré : %2$s', __FILE__),
                            $host,
                            $exception->getMessage()
                        )
                    );
                    if (count($directHosts) > 0) {
                        $status['errors'][] = $host . ' : ' . $exception->getMessage();
                    }
                }
                $status['tested'] = $index + 1;
                $status['progress'] = 25 + (int) floor((($index + 1) * 74) / $total);
                self::publish($status);
            }
            $status['running'] = false;
            $status['current_host'] = '';
            $status['finished'] = time();
            $status['progress'] = 100;
            self::publish($status);
            self::log(
                $logger,
                'info',
                sprintf(
                    __('[Discovery] Tâche terminée : %1$d appareil(s) trouvé(s), %2$d erreur(s)', __FILE__),
                    count($status['found']),
                    count($status['errors'])
                )
            );
        } catch (Throwable $exception) {
            $status = self::rawStatus();
            $status['running'] = false;
            $status['finished'] = time();
            $status['progress'] = 100;
            $status['cancelled'] = $exception instanceof LocalThingsDiscoveryCancelled;
            $status['current_host'] = '';
            if (!$status['cancelled']) {
                $status['errors'][] = $exception->getMessage();
            }
            self::publish($status);
            self::log(
                $logger,
                $status['cancelled'] ? 'info' : 'error',
                __('[Discovery] Tâche interrompue : ', __FILE__) . $exception->getMessage()
                . ' ; ' . basename($exception->getFile()) . ':' . $exception->getLine()
            );
        } finally {
            self::$activeJob = null;
            cache::delete(self::JOB_PREFIX . $jobId);
        }
    }

    /** État partagé, avec récupération d'une tâche morte ou sans heartbeat. */
    public static function readStatus()
    {
        return self::withStateLock(function () { return self::recoverStatus(); });
    }

    /** Demande l'arrêt du job affiché ; un ancien onglet ne peut arrêter le suivant. */
    public static function stop($jobId)
    {
        return self::withStateLock(function () use ($jobId) {
            $status = self::recoverStatus();
            if (!empty($status['running']) && ($status['job_id'] ?? '') === (string) $jobId) {
                $status['stop_requested'] = true;
                self::storeStatus($status);
            }
            return $status;
        });
    }

    /** Contrôle d'annulation borné, appelé dans les attentes réseau du worker. */
    public static function checkpoint($force = false)
    {
        if (self::$activeJob === null || (!$force && microtime(true) - self::$lastCheckpoint < 0.25)) {
            return;
        }
        self::$lastCheckpoint = microtime(true);
        self::withStateLock(function () {
            $status = self::rawStatus();
            if (($status['job_id'] ?? '') !== self::$activeJob || empty($status['running'])
                || !empty($status['stop_requested'])) {
                throw new LocalThingsDiscoveryCancelled('Découverte arrêtée à la demande de l’utilisateur ou tâche expirée');
            }
            if (time() - (int) ($status['heartbeat'] ?? 0) >= 2) {
                $status['heartbeat'] = time();
                self::storeStatus($status);
            }
        });
    }

    /** Écriture protégée contre les retours tardifs et les demandes d'arrêt concurrentes. */
    private static function publish($status)
    {
        self::withStateLock(function () use ($status) {
            $current = self::rawStatus();
            if (($current['job_id'] ?? '') !== self::$activeJob || empty($current['running'])) {
                return;
            }
            $status['stop_requested'] = !empty($current['stop_requested']);
            $status['worker_pid'] = $current['worker_pid'];
            $status['claimed'] = true;
            $status['heartbeat'] = time();
            if (!$status['running'] && $status['stop_requested']) {
                $status['cancelled'] = true;
            }
            self::storeStatus($status);
        });
    }

    private static function rawStatus()
    {
        $status = cache::byKey(self::STATUS_KEY)->getValue(null);
        return is_array($status) ? $status : self::newStatus(false);
    }

    private static function storeStatus($status)
    {
        cache::set(self::STATUS_KEY, $status, self::CACHE_TTL);
    }

    /** N'envoie aucun signal à un PID susceptible d'avoir été réutilisé. */
    private static function recoverStatus()
    {
        $status = self::rawStatus();
        if (!empty($status['running'])) {
            $age = time() - (int) ($status['heartbeat'] ?? $status['started']);
            $dead = false;
            $pid = (int) ($status['worker_pid'] ?? 0);
            if ($age > 10 && $pid > 0 && is_dir('/proc')) {
                $command = @file_get_contents('/proc/' . $pid . '/cmdline');
                $dead = $command === false || strpos($command, 'discover.php') === false
                    || strpos($command, (string) $status['job_id']) === false;
            }
            if ($dead || $age > 180) {
                $status['running'] = false;
                $status['finished'] = time();
                $status['current_host'] = '';
                $status['cancelled'] = !empty($status['stop_requested']);
                if (!$status['cancelled']) {
                    $status['errors'][] = 'Le processus de découverte est arrêté ou ne répond plus.';
                }
                cache::delete(self::JOB_PREFIX . ($status['job_id'] ?? ''));
                self::storeStatus($status);
            }
        }
        return $status;
    }

    /** Seul un verrou vide reste sur disque : cache::set n'est pas un verrou atomique. */
    private static function withStateLock(callable $callback)
    {
        $directory = __DIR__ . '/../../data';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Répertoire privé de découverte inaccessible');
        }
        $handle = fopen($directory . '/discovery.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('Verrou de découverte inaccessible');
        }
        @chmod($directory . '/discovery.lock', 0600);
        try {
            $deadline = microtime(true) + 3;
            while (!flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('La découverte est occupée, réessayez dans quelques secondes');
                }
                usleep(10000);
            }
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Teste en parallèle les hôtes répondant à ICMP.
     *
     * @param string[] $hosts Adresses à tester.
     * @return string[] Adresses joignables.
     */
    private static function reachableHosts($hosts)
    {
        $queue = array_values($hosts);
        $running = array();
        $reachable = array();
        try {
            while (count($queue) > 0 || count($running) > 0) {
                self::checkpoint();
                while (count($queue) > 0 && count($running) < self::PING_WORKERS) {
                    $host = array_shift($queue);
                    $descriptor = array(
                        0 => array('file', '/dev/null', 'r'),
                        1 => array('file', '/dev/null', 'w'),
                        2 => array('file', '/dev/null', 'w'),
                    );
                    $pipes = array();
                    $process = @proc_open(
                        array('ping', '-n', '-c', '1', '-W', '1', $host),
                        $descriptor,
                        $pipes,
                        null,
                        null,
                        array('bypass_shell' => true, 'suppress_errors' => true)
                    );
                    if (is_resource($process)) {
                        $running[] = array('host' => $host, 'process' => $process);
                    }
                }
                foreach ($running as $index => $item) {
                    $status = proc_get_status($item['process']);
                    if ($status['running']) {
                        continue;
                    }
                    if ((int) $status['exitcode'] === 0) {
                        $reachable[] = $item['host'];
                    }
                    proc_close($item['process']);
                    unset($running[$index]);
                }
                $running = array_values($running);
                if (count($running) > 0) {
                    usleep(20000);
                }
            }
        } finally {
            foreach ($running as $item) {
                if (is_resource($item['process'])) {
                    proc_terminate($item['process']);
                    proc_close($item['process']);
                }
            }
        }
        return array_values(array_unique($reachable));
    }

    /**
     * Extrait les voisins IPv4 connus des tables ARP ou `ip neigh`.
     *
     * @return string[]
     */
    private static function neighbourHosts()
    {
        $hosts = array();
        foreach (array(array('ip', 'neigh', 'show'), array('arp', '-an')) as $command) {
            $descriptor = array(
                0 => array('file', '/dev/null', 'r'),
                1 => array('pipe', 'w'),
                2 => array('file', '/dev/null', 'w'),
            );
            $pipes = array();
            $process = @proc_open(
                $command,
                $descriptor,
                $pipes,
                null,
                null,
                array('bypass_shell' => true, 'suppress_errors' => true)
            );
            if (!is_resource($process)) {
                continue;
            }
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            proc_close($process);
            foreach (preg_split('/[\r\n]+/', (string) $output) as $line) {
                if (preg_match('/\b(?:FAILED|INCOMPLETE)\b|\(incomplete\)/i', $line)) {
                    continue;
                }
                if (
                    preg_match('/\b((?:\d{1,3}\.){3}\d{1,3})\b/', $line, $matches)
                    && filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                ) {
                    $hosts[] = $matches[1];
                }
            }
            if (count($hosts) > 0) {
                break;
            }
        }
        return array_values(array_unique($hosts));
    }

    /**
     * Développe des réseaux CIDR en adresses hôtes utilisables.
     *
     * @param string[] $networks Réseaux validés.
     * @return string[]
     */
    private static function expandNetworks($networks)
    {
        $hosts = array();
        foreach ($networks as $network) {
            list($address, $prefix) = explode('/', $network, 2);
            $prefix = (int) $prefix;
            $start = self::unsignedIp($address);
            $count = 1 << (32 - $prefix);
            for ($offset = 1; $offset < $count - 1; $offset++) {
                $hosts[] = long2ip($start + $offset);
            }
        }
        return array_values(array_unique($hosts));
    }

    /**
     * Calcule l'adresse réseau canonique d'un préfixe IPv4.
     *
     * @param string $address Adresse IPv4 quelconque du réseau.
     * @param int $prefix Longueur du préfixe.
     * @return string
     */
    private static function canonicalNetwork($address, $prefix)
    {
        $value = self::unsignedIp($address);
        $mask = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        return long2ip($value & $mask);
    }

    /**
     * Convertit une IPv4 en entier non signé portable.
     *
     * @param string $address Adresse IPv4.
     * @return int
     */
    private static function unsignedIp($address)
    {
        $value = ip2long($address);
        if ($value === false) {
            throw new InvalidArgumentException(__('Adresse IPv4 invalide', __FILE__));
        }
        return (int) sprintf('%u', $value);
    }

    /**
     * Crée la structure stable d'un état de découverte.
     *
     * @param bool $running Indique si la tâche est active.
     * @return array<string,mixed>
     */
    private static function newStatus($running)
    {
        return array(
            'running' => (bool) $running,
            'started' => $running ? time() : 0,
            'finished' => 0,
            'progress' => 0,
            'candidates' => 0,
            'tested' => 0,
            'found' => array(),
            'errors' => array(),
            'worker_pid' => 0,
            'job_id' => '',
            'current_host' => '',
            'stop_requested' => false,
            'cancelled' => false,
            'heartbeat' => time(),
        );
    }

    /**
     * Recherche un interpréteur PHP CLI exécutable.
     *
     * @return string
     */
    private static function phpCli()
    {
        $phpCli = self::findPhpCli();
        if ($phpCli !== '') {
            return $phpCli;
        }
        throw new RuntimeException(__('Interpréteur PHP CLI introuvable', __FILE__));
    }

    /**
     * Recherche un interpréteur PHP CLI sans lever d'exception.
     *
     * @return string Chemin exécutable, ou chaîne vide.
     */
    public static function findPhpCli()
    {
        $candidates = array('/usr/bin/php', PHP_BINDIR . '/php', PHP_BINARY);
        foreach ($candidates as $candidate) {
            if (
                is_executable($candidate)
                && stripos(basename($candidate), 'php-fpm') === false
            ) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Transmet un message nettoyé au journaliseur de la tâche.
     *
     * @param callable|null $logger Journaliseur facultatif.
     * @param string $level Niveau de journalisation.
     * @param string $message Message à écrire.
     * @return void
     */
    private static function log($logger, $level, $message)
    {
        if (!is_callable($logger)) {
            return;
        }
        $message = preg_replace('/[\r\n]+/', ' ', (string) $message);
        call_user_func($logger, (string) $level, substr($message, 0, 1800));
    }
}
