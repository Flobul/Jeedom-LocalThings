<?php

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
    public const PROBE_PORTS = array(49154, 49155, 49152, 49153, 49156, 49157, 49158, 49159, 49160);

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
            . ($exhaustive ? __(' (tous les ports UDP 49152-49160)', __FILE__) : '')
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
        $identityStarted = microtime(true);
        try {
            $this->certificateStore->mintLeaf('host:' . $host);
            $this->log(
                'info',
                sprintf(
                    __('[Certificate] Identité cliente prête pour %1$s en %2$d ms', __FILE__),
                    $host,
                    $this->durationMs($identityStarted)
                )
            );
        } catch (Exception $exception) {
            $message = __('Préparation du certificat client impossible : ', __FILE__)
                . $exception->getMessage();
            $this->log('warning', '[Certificate] ' . $message);
            throw new RuntimeException($message, 0, $exception);
        }

        $detectedPorts = self::candidatePorts($host);
        $ports = self::buildProbeOrder($detectedPorts, $preferredPort, $exhaustive);
        $this->log(
            'info',
            sprintf(
                __('[Discovery] %1$s ports candidats : %2$s; source UDP locale : %3$d', __FILE__),
                $host,
                implode(', ', $ports),
                self::sourcePort($host)
            )
        );
        $lastError = '';
        foreach ($ports as $port) {
            $started = microtime(true);
            $this->log(
                $exhaustive ? 'info' : 'debug',
                sprintf(__('[Discovery] Tentative DTLS %1$s:%2$d', __FILE__), $host, $port)
            );
            try {
                $snapshot = $this->readSnapshot($host, $port, 5.0, true);
                $this->log(
                    'info',
                    sprintf(
                        __('[Discovery] Appareil trouvé sur %1$s:%2$d en %3$d ms', __FILE__),
                        $host,
                        $port,
                        $this->durationMs($started)
                    )
                );
                return $snapshot;
            } catch (Exception $exception) {
                $lastError = $exception->getMessage();
                $this->log(
                    $exhaustive ? 'info' : 'debug',
                    sprintf(
                        __('[Discovery] Échec %1$s:%2$d après %3$d ms : %4$s', __FILE__),
                        $host,
                        $port,
                        $this->durationMs($started),
                        $lastError
                    )
                );
            }
        }
        $message = sprintf(
            __('Aucun service LocalThings utilisable sur %1$s (ports essayés : %2$s)', __FILE__),
            $host,
            implode(', ', $ports)
        )
            . ($lastError !== '' ? ' : ' . $lastError : '');
        $this->log('warning', '[Discovery] ' . $message);
        throw new RuntimeException($message);
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
    private function readSnapshot($host, $port, $handshakeTimeout, $includeIdentity = true, $knownDevice = array())
    {
        if (!in_array((int) $port, self::PROBE_PORTS, true)) {
            throw new InvalidArgumentException(__('Port LocalThings invalide', __FILE__));
        }
        $session = $this->createSession($host, (int) $port);
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
            $resources = $this->readResources($session);
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
            $model = trim((string) ($identity['model'] ?? ''));
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
            $mapped = $this->mapper->map($resources);
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
    private function createSession($host, $port)
    {
        list($certificatePath, $keyPath) = $this->certificateStore->mintLeaf('host:' . $host);
        $transport = new LocalThingsDtlsClient(
            $this->openssl,
            $host,
            $port,
            self::sourcePort($host),
            $certificatePath,
            $this->certificateStore->caCertificatePath(),
            $keyPath,
            $this->rootCaPath,
            $this->logger
        );
        return new LocalThingsSession($transport, $this->logger);
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
     * Classe les ports DTLS susceptibles d'être ouverts sur un hôte.
     *
     * @param string $host Adresse IPv4 cible.
     * @return int[]
     */
    private static function candidatePorts($host)
    {
        $streams = array();
        $ports = array();
        foreach (self::PROBE_PORTS as $port) {
            $errno = 0;
            $error = '';
            $stream = @stream_socket_client(
                'udp://' . $host . ':' . $port,
                $errno,
                $error,
                0.2,
                STREAM_CLIENT_CONNECT
            );
            if (!is_resource($stream)) {
                continue;
            }
            stream_set_blocking($stream, false);
            @fwrite($stream, "\0");
            $streams[(int) $stream] = array('stream' => $stream, 'port' => $port);
        }
        $deadline = microtime(true) + 0.35;
        while (count($streams) > 0 && microtime(true) < $deadline) {
            $read = array();
            foreach ($streams as $item) {
                $read[] = $item['stream'];
            }
            $write = null;
            $except = null;
            $remaining = max(0, $deadline - microtime(true));
            $selected = @stream_select(
                $read,
                $write,
                $except,
                (int) floor($remaining),
                (int) (($remaining - floor($remaining)) * 1000000)
            );
            if ($selected === false || $selected === 0) {
                break;
            }
            foreach ($read as $stream) {
                $streamId = (int) $stream;
                @fread($stream, 1);
                fclose($stream);
                unset($streams[$streamId]);
            }
        }
        foreach ($streams as $item) {
            $ports[] = $item['port'];
            fclose($item['stream']);
        }
        $preferred = array(49154, 49155);
        return array_values(array_unique(array_merge(
            $preferred,
            array_values(array_intersect(self::PROBE_PORTS, $ports))
        )));
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
        if ($preferredPort !== null && in_array((int) $preferredPort, self::PROBE_PORTS, true)) {
            $ports[] = (int) $preferredPort;
        }
        foreach ((array) $detectedPorts as $port) {
            if (in_array((int) $port, self::PROBE_PORTS, true)) {
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
        do {
            $locked = flock($handle, LOCK_EX | LOCK_NB);
            if (!$locked) {
                usleep(100000);
            }
        } while (!$locked && microtime(true) < $deadline);
        if (!$locked) {
            fclose($handle);
            throw new RuntimeException(__('Un autre échange LocalThings est déjà en cours', __FILE__));
        }
        try {
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

/**
 * Valide les cibles et pilote la découverte réseau asynchrone.
 */
class LocalThingsDiscovery
{
    private const MAX_NETWORKS = 8;
    private const MAX_HOSTS = 1024;
    private const PING_WORKERS = 48;

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

    /**
     * Prépare une tâche de découverte puis démarre son processus PHP détaché.
     *
     * @param string $statusPath Fichier d'état partagé.
     * @param string $workerPath Script PHP exécuté en arrière-plan.
     * @param string[] $networks Réseaux CIDR à parcourir.
     * @param string[] $hosts Adresses directes prioritaires.
     * @param string|null $logPath Fichier de journal facultatif.
     * @return array<string,mixed> État initial de la tâche.
     */
    public static function start($statusPath, $workerPath, $networks = array(), $hosts = array(), $logPath = null)
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException(__('La fonction PHP proc_open est désactivée', __FILE__));
        }
        if (!function_exists('exec')) {
            throw new RuntimeException(__('La fonction PHP exec est désactivée', __FILE__));
        }
        $statusPath = (string) $statusPath;
        if (self::readStatus($statusPath)['running'] ?? false) {
            throw new RuntimeException(__('Une découverte LocalThings est déjà en cours', __FILE__));
        }
        $hosts = count($hosts) > 0 ? self::validateHosts($hosts) : array();
        $networks = count($hosts) === 0 ? self::validateNetworks($networks) : array();
        $jobPath = dirname($statusPath) . '/discovery-job-' . bin2hex(random_bytes(6)) . '.json';
        self::writeJson($jobPath, array(
            'status_path' => $statusPath,
            'networks' => $networks,
            'hosts' => $hosts,
        ));
        self::writeJson($statusPath, self::newStatus(true));

        $command = escapeshellarg(self::phpCli())
            . ' ' . escapeshellarg((string) $workerPath)
            . ' --job ' . escapeshellarg($jobPath);
        if ($logPath !== null && $logPath !== '') {
            $command .= ' >> ' . escapeshellarg((string) $logPath) . ' 2>&1';
        } else {
            $command .= ' > /dev/null 2>&1';
        }
        $command .= ' & echo $!';
        $output = array();
        $exitCode = 0;
        exec($command, $output, $exitCode);
        $workerPid = count($output) > 0 ? (int) trim((string) end($output)) : 0;
        if ($exitCode !== 0 || $workerPid <= 0) {
            @unlink($jobPath);
            $status = self::newStatus(false);
            $status['finished'] = time();
            $status['errors'][] = __('Le processus PHP de découverte n’a pas démarré', __FILE__);
            self::writeJson($statusPath, $status);
            throw new RuntimeException($status['errors'][0]);
        }
        $status = self::readStatus($statusPath);
        $status['worker_pid'] = $workerPid;
        self::writeJson($statusPath, $status);
        return $status;
    }

    /**
     * Exécute une tâche de découverte et actualise son fichier d'état.
     *
     * @param string $jobPath Fichier décrivant la tâche.
     * @param callable $probe Sonde appelée pour chaque adresse candidate.
     * @param callable|null $logger Journaliseur facultatif.
     * @return void
     */
    public static function run($jobPath, callable $probe, $logger = null)
    {
        $job = json_decode((string) file_get_contents($jobPath), true);
        if (!is_array($job) || empty($job['status_path'])) {
            throw new InvalidArgumentException(__('Tâche de découverte invalide', __FILE__));
        }
        $statusPath = (string) $job['status_path'];
        $directHosts = self::validateHosts($job['hosts'] ?? array());
        self::log(
            $logger,
            'info',
            sprintf(
                __('[Discovery] Tâche PHP démarrée, mode=%s', __FILE__),
                count($directHosts) > 0 ? __('adresse directe', __FILE__) : __('réseau', __FILE__)
            )
        );
        try {
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
            $status = self::newStatus(true);
            $status['candidates'] = count($candidates);
            $status['progress'] = 25;
            self::writeJson($statusPath, $status);

            $total = max(1, count($candidates));
            foreach ($candidates as $index => $host) {
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
                self::writeJson($statusPath, $status);
            }
            $status['running'] = false;
            $status['finished'] = time();
            $status['progress'] = 100;
            self::writeJson($statusPath, $status);
            self::log(
                $logger,
                'info',
                sprintf(
                    __('[Discovery] Tâche terminée : %1$d appareil(s) trouvé(s), %2$d erreur(s)', __FILE__),
                    count($status['found']),
                    count($status['errors'])
                )
            );
        } catch (Exception $exception) {
            $status = self::readStatus($statusPath);
            $status['running'] = false;
            $status['finished'] = time();
            $status['progress'] = 100;
            $status['errors'][] = $exception->getMessage();
            self::writeJson($statusPath, $status);
            self::log(
                $logger,
                'error',
                __('[Discovery] Tâche interrompue : ', __FILE__) . $exception->getMessage()
            );
        } finally {
            @unlink($jobPath);
        }
    }

    /**
     * Lit l'état persistant d'une découverte.
     *
     * @param string $path Chemin du fichier JSON.
     * @return array<string,mixed>
     */
    public static function readStatus($path)
    {
        if (!is_file($path)) {
            return self::newStatus(false);
        }
        $status = json_decode((string) file_get_contents($path), true);
        return is_array($status) ? $status : self::newStatus(false);
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
        while (count($queue) > 0 || count($running) > 0) {
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
        );
    }

    /**
     * Écrit atomiquement une valeur JSON dans un fichier privé.
     *
     * @param string $path Chemin de destination.
     * @param mixed $value Valeur sérialisable.
     * @return void
     */
    private static function writeJson($path, $value)
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(__('Création du répertoire de découverte impossible', __FILE__));
        }
        $temporary = tempnam($directory, '.discovery-');
        if ($temporary === false) {
            throw new RuntimeException(__('Création du fichier temporaire de découverte impossible', __FILE__));
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($temporary, $json, LOCK_EX) === false) {
            @unlink($temporary);
            throw new RuntimeException(__('Écriture de l’état de découverte impossible', __FILE__));
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException(__('Installation de l’état de découverte impossible', __FILE__));
        }
        @chmod($path, 0600);
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
