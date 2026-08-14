<?php

class LocalThingsCommandRejectedException extends RuntimeException
{
}

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

    public function probe($host, $preferredPort = null, $exhaustive = false)
    {
        $host = $this->validateHost($host);
        $this->log(
            'info',
            '[Discovery] Analyse de ' . $host
            . ($exhaustive ? ' (tous les ports UDP 49152-49160)' : '')
        );
        return $this->withHostLock($host, function () use ($host, $preferredPort, $exhaustive) {
            return $this->probeUnlocked($host, $preferredPort, $exhaustive);
        });
    }

    private function probeUnlocked($host, $preferredPort, $exhaustive)
    {
        $identityStarted = microtime(true);
        try {
            $this->certificateStore->mintLeaf('host:' . $host);
            $this->log(
                'info',
                '[Certificate] Identité cliente prête pour ' . $host
                . ' en ' . $this->durationMs($identityStarted) . ' ms'
            );
        } catch (Exception $exception) {
            $message = 'Préparation du certificat client impossible : ' . $exception->getMessage();
            $this->log('warning', '[Certificate] ' . $message);
            throw new RuntimeException($message, 0, $exception);
        }

        $detectedPorts = self::candidatePorts($host);
        $ports = self::buildProbeOrder($detectedPorts, $preferredPort, $exhaustive);
        $this->log(
            'info',
            '[Discovery] ' . $host . ' ports candidats : ' . implode(', ', $ports)
            . '; source UDP locale : ' . self::sourcePort($host)
        );
        $lastError = '';
        foreach ($ports as $port) {
            $started = microtime(true);
            $this->log(
                $exhaustive ? 'info' : 'debug',
                '[Discovery] Tentative DTLS ' . $host . ':' . $port
            );
            try {
                $snapshot = $this->readSnapshot($host, $port, 5.0);
                $this->log(
                    'info',
                    '[Discovery] Appareil trouvé sur ' . $host . ':' . $port
                    . ' en ' . $this->durationMs($started) . ' ms'
                );
                return $snapshot;
            } catch (Exception $exception) {
                $lastError = $exception->getMessage();
                $this->log(
                    $exhaustive ? 'info' : 'debug',
                    '[Discovery] Échec ' . $host . ':' . $port
                    . ' après ' . $this->durationMs($started) . ' ms : ' . $lastError
                );
            }
        }
        $message =
            'Aucun service LocalThings utilisable sur ' . $host
            . ' (ports essayés : ' . implode(', ', $ports) . ')'
            . ($lastError !== '' ? ' : ' . $lastError : '');
        $this->log('warning', '[Discovery] ' . $message);
        throw new RuntimeException($message);
    }

    public function refresh($host, $port)
    {
        $host = $this->validateHost($host);
        return $this->withHostLock($host, function () use ($host, $port) {
            return $this->readSnapshot($host, (int) $port, 12.0);
        });
    }

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

    private function executeUnlocked($host, $port, $recipe, $value, $bypassRemoteControl)
    {
        $this->log('info', '[Command] Connexion à ' . $host . ':' . (int) $port);
        $session = $this->createSession($host, (int) $port);
        try {
            $session->connect(12.0);
            $resources = $this->readResources($session);
            $remoteControlEnabled = $this->mapper->remoteControlEnabled($resources);
            $this->log(
                'debug',
                '[Command] Smart Control=' . ($remoteControlEnabled ? 'activé' : 'désactivé')
                . ', contournement=' . ($bypassRemoteControl ? 'activé' : 'désactivé')
            );
            if (!$bypassRemoteControl && !$remoteControlEnabled) {
                throw new LocalThingsCommandRejectedException('Smart Control est désactivé sur l’appareil');
            }
            $write = $this->mapper->buildWrite($recipe, $value, $resources);
            $href = '/' . implode('/', $write['path']);
            $this->log(
                'info',
                '[Command] POST ' . $href . ' body=' . $this->jsonForLog($write['body'])
            );
            list($code, $response) = $session->post($write['path'], $write['body'], 15.0);
            if (($code >> 5) !== 2) {
                throw new LocalThingsCommandRejectedException(
                    'Écriture CoAP refusée (' . LocalThingsCoap::formatCode($code) . ')'
                );
            }
            $responseRepresentation = $this->decodeRepresentation($response);
            if ($responseRepresentation !== null) {
                $this->log(
                    'debug',
                    '[Command] Réponse ' . LocalThingsCoap::formatCode($code)
                    . ' ' . $this->jsonForLog($responseRepresentation)
                );
            }

            $verification = $this->verifyWrite(
                $session,
                $write['path'],
                $write['body']
            );
            if ($verification['matched'] === false) {
                throw new LocalThingsCommandRejectedException(
                    'Commande acquittée mais non appliquée par l’appareil sur ' . $href
                );
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

    private function verifyWrite(LocalThingsSession $session, $path, $expected)
    {
        $this->log(
            'debug',
            '[Command] Stabilisation pendant '
            . $this->formatDelaySeconds(self::WRITE_SETTLE_DELAY_US)
            . ' s avant vérification'
        );
        usleep(self::WRITE_SETTLE_DELAY_US);

        try {
            list($code, $payload) = $session->get($path, 10.0);
            if (($code >> 5) !== 2) {
                $this->log(
                    'warning',
                    '[Command] Vérification GET refusée : ' . LocalThingsCoap::formatCode($code)
                );
                return array('matched' => null, 'representation' => null);
            }
            $representation = $this->decodeRepresentation($payload);
            if (!is_array($representation)) {
                $this->log('warning', '[Command] Réponse de vérification CBOR invalide');
                return array('matched' => null, 'representation' => null);
            }
            $matched = $this->representationContains($representation, $expected);
            $this->log(
                $matched ? 'info' : 'warning',
                '[Command] Vérification /' . implode('/', $path)
                . ' expected=' . $this->jsonForLog($expected)
                . ' actual=' . $this->jsonForLog($representation)
                . ' applied=' . ($matched ? 'yes' : 'no')
            );
            if ($matched) {
                return array('matched' => true, 'representation' => $representation);
            }
        } catch (Exception $exception) {
            $this->log(
                'warning',
                '[Command] Vérification indisponible : ' . $exception->getMessage()
            );
            return array('matched' => null, 'representation' => null);
        }

        $this->log(
            'warning',
            '[Command] Écriture non appliquée après stabilisation; expected='
            . $this->jsonForLog($expected)
            . ' actual=' . $this->jsonForLog($representation)
        );
        return array('matched' => false, 'representation' => $representation);
    }

    private function formatDelaySeconds($microseconds)
    {
        return rtrim(rtrim(number_format(((int) $microseconds) / 1000000, 1, '.', ''), '0'), '.');
    }

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

    private function jsonForLog($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '[unserializable]';
    }

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

    private function readSnapshot($host, $port, $handshakeTimeout)
    {
        if (!in_array((int) $port, self::PROBE_PORTS, true)) {
            throw new InvalidArgumentException('Port LocalThings invalide');
        }
        $session = $this->createSession($host, (int) $port);
        try {
            $this->log(
                'debug',
                '[DTLS] Négociation avec ' . $host . ':' . (int) $port
                . ', timeout=' . (float) $handshakeTimeout . ' s'
            );
            $session->connect($handshakeTimeout);
            $resources = $this->readResources($session);
            usleep(200000);
            $identity = $this->readIdentity($session);
            $information = $resources['/information/vs/0'] ?? array();
            $serial = self::normalizeIdentifier(
                $information['x.com.samsung.da.serialNum'] ?? ''
            );
            $ocfDeviceId = self::normalizeIdentifier($identity['device_id'] ?? '');
            $deviceId = $serial !== ''
                ? $serial
                : ($ocfDeviceId !== '' ? $ocfDeviceId : $host . ':' . $port);
            $deviceType = $this->mapper->deviceType($resources, $identity);
            $model = trim((string) ($identity['model'] ?? ''));
            if ($model === '') {
                $model = explode('|', (string) ($information['x.com.samsung.da.modelNum'] ?? ''), 2)[0];
            }
            $name = trim((string) ($identity['name'] ?? ''));
            if ($name === '') {
                $name = explode('/', (string) ($information['x.com.samsung.da.description'] ?? ''), 2)[0];
            }
            if ($name === '') {
                $name = 'Samsung ' . str_replace('_', ' ', $deviceType);
            }
            $mapped = $this->mapper->map($resources);
            $this->log(
                'info',
                '[Discovery] Identité reçue : modèle=' . ($model !== '' ? $model : 'inconnu')
                . ', type=' . $deviceType
                . ', série=' . ($serial !== '' ? $this->redactIdentifier($serial) : 'non communiquée')
                . ', identifiant=' . $this->redactIdentifier($deviceId)
                . ', ressources=' . count($resources)
                . ', commandes=' . count($mapped['entities'])
            );
            return array(
                'device' => array(
                    'device_id' => $deviceId,
                    'host' => $host,
                    'port' => (int) $port,
                    'serial' => $serial,
                    'name' => $name,
                    'manufacturer' => trim((string) ($identity['manufacturer'] ?? 'Samsung')) ?: 'Samsung',
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

    private function readResources(LocalThingsSession $session)
    {
        list($code, $payload) = $session->get(array('device', '0'), 35.0);
        $this->log(
            'info',
            '[CoAP] GET /device/0 -> ' . LocalThingsCoap::formatCode($code)
            . ', ' . strlen($payload) . ' octets'
        );
        if (($code >> 5) !== 2 || $payload === '') {
            throw new RuntimeException('GET /device/0 a répondu ' . LocalThingsCoap::formatCode($code));
        }
        $decoded = LocalThingsCbor::decode($payload);
        if (!is_array($decoded)) {
            throw new RuntimeException('La réponse /device/0 est invalide');
        }
        $resources = array();
        foreach (array_slice($decoded, 1) as $entry) {
            if (!is_array($entry) || empty($entry['href']) || !isset($entry['rep']) || !is_array($entry['rep'])) {
                continue;
            }
            $resources[(string) $entry['href']] = $entry['rep'];
        }
        if (count($resources) === 0) {
            throw new RuntimeException('La réponse /device/0 ne contient aucune ressource');
        }
        $this->log('debug', '[CBOR] /device/0 décodé : ' . count($resources) . ' ressources');
        return $resources;
    }

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

    private function validateHost($host)
    {
        $host = trim((string) $host);
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('Adresse IPv4 invalide');
        }
        return $host;
    }

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

    private function withHostLock($host, callable $callback)
    {
        $path = $this->lockDirectory . '/port-' . self::sourcePort($host) . '.lock';
        $handle = fopen($path, 'c');
        if (!is_resource($handle)) {
            throw new RuntimeException('Création du verrou LocalThings impossible');
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
            throw new RuntimeException('Un autre échange LocalThings est déjà en cours');
        }
        try {
            return call_user_func($callback);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function log($level, $message)
    {
        if ($this->logger === null) {
            return;
        }
        $message = preg_replace('/[\r\n]+/', ' ', (string) $message);
        call_user_func($this->logger, (string) $level, substr($message, 0, 1800));
    }

    private function durationMs($started)
    {
        return (int) round((microtime(true) - (float) $started) * 1000);
    }

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

class LocalThingsDiscovery
{
    private const MAX_NETWORKS = 8;
    private const MAX_HOSTS = 1024;
    private const PING_WORKERS = 48;

    public static function validateNetworks($values)
    {
        $networks = array();
        $hostCount = 0;
        foreach (array_slice((array) $values, 0, self::MAX_NETWORKS) as $value) {
            $value = trim((string) $value);
            if (!preg_match('#^([0-9.]+)/([0-9]{1,2})$#', $value, $matches)) {
                throw new InvalidArgumentException('Réseau CIDR invalide : ' . $value);
            }
            if (filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new InvalidArgumentException('Adresse de réseau invalide : ' . $value);
            }
            $prefix = (int) $matches[2];
            if ($prefix < 22 || $prefix > 30) {
                throw new InvalidArgumentException('Utilisez un réseau IPv4 compris entre /22 et /30 : ' . $value);
            }
            $network = self::canonicalNetwork($matches[1], $prefix);
            $networks[] = $network . '/' . $prefix;
            $hostCount += (1 << (32 - $prefix)) - 2;
        }
        $networks = array_values(array_unique($networks));
        if (count($networks) === 0) {
            throw new InvalidArgumentException('Aucun réseau de découverte configuré');
        }
        if ($hostCount > self::MAX_HOSTS) {
            throw new InvalidArgumentException('La découverte est limitée à ' . self::MAX_HOSTS . ' adresses');
        }
        return $networks;
    }

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
                throw new InvalidArgumentException('Adresse IPv4 locale invalide : ' . $value);
            }
            $hosts[] = $value;
        }
        $hosts = array_values(array_unique($hosts));
        if (count($hosts) > self::MAX_HOSTS) {
            throw new InvalidArgumentException('La découverte est limitée à ' . self::MAX_HOSTS . ' adresses');
        }
        return $hosts;
    }

    public static function start($statusPath, $workerPath, $networks = array(), $hosts = array(), $logPath = null)
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('La fonction PHP proc_open est désactivée');
        }
        if (!function_exists('exec')) {
            throw new RuntimeException('La fonction PHP exec est désactivée');
        }
        $statusPath = (string) $statusPath;
        if (self::readStatus($statusPath)['running'] ?? false) {
            throw new RuntimeException('Une découverte LocalThings est déjà en cours');
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
            $status['errors'][] = 'Le processus PHP de découverte n’a pas démarré';
            self::writeJson($statusPath, $status);
            throw new RuntimeException($status['errors'][0]);
        }
        $status = self::readStatus($statusPath);
        $status['worker_pid'] = $workerPid;
        self::writeJson($statusPath, $status);
        return $status;
    }

    public static function run($jobPath, callable $probe, $logger = null)
    {
        $job = json_decode((string) file_get_contents($jobPath), true);
        if (!is_array($job) || empty($job['status_path'])) {
            throw new InvalidArgumentException('Tâche de découverte invalide');
        }
        $statusPath = (string) $job['status_path'];
        $directHosts = self::validateHosts($job['hosts'] ?? array());
        self::log(
            $logger,
            'info',
            '[Discovery] Tâche PHP démarrée, mode=' . (count($directHosts) > 0 ? 'adresse directe' : 'réseau')
        );
        try {
            if (count($directHosts) > 0) {
                $candidates = $directHosts;
            } else {
                $networks = self::validateNetworks($job['networks'] ?? array());
                self::log($logger, 'info', '[Discovery] Réseaux analysés : ' . implode(', ', $networks));
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
                    '[Discovery] Détection réseau : voisins avant=' . count($neighbours)
                    . ', ping=' . count($reachable)
                    . ', voisins après=' . count($neighboursAfterSweep)
                );
            }
            sort($candidates, SORT_NATURAL);
            self::log(
                $logger,
                'info',
                '[Discovery] ' . count($candidates) . ' adresse(s) candidate(s) après détection réseau'
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
                    '[Discovery] Hôte ' . ($index + 1) . '/' . count($candidates) . ' : ' . $host
                );
                try {
                    $snapshot = call_user_func($probe, $host, count($directHosts) > 0);
                    $status['found'][] = $snapshot['device'] ?? array('host' => $host);
                    self::log($logger, 'info', '[Discovery] ' . $host . ' enregistré dans Jeedom');
                } catch (Exception $exception) {
                    self::log(
                        $logger,
                        count($directHosts) > 0 ? 'warning' : 'debug',
                        '[Discovery] ' . $host . ' ignoré : ' . $exception->getMessage()
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
                '[Discovery] Tâche terminée : ' . count($status['found']) . ' appareil(s) trouvé(s), '
                . count($status['errors']) . ' erreur(s)'
            );
        } catch (Exception $exception) {
            $status = self::readStatus($statusPath);
            $status['running'] = false;
            $status['finished'] = time();
            $status['progress'] = 100;
            $status['errors'][] = $exception->getMessage();
            self::writeJson($statusPath, $status);
            self::log($logger, 'error', '[Discovery] Tâche interrompue : ' . $exception->getMessage());
        } finally {
            @unlink($jobPath);
        }
    }

    public static function readStatus($path)
    {
        if (!is_file($path)) {
            return self::newStatus(false);
        }
        $status = json_decode((string) file_get_contents($path), true);
        return is_array($status) ? $status : self::newStatus(false);
    }

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

    private static function canonicalNetwork($address, $prefix)
    {
        $value = self::unsignedIp($address);
        $mask = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        return long2ip($value & $mask);
    }

    private static function unsignedIp($address)
    {
        $value = ip2long($address);
        if ($value === false) {
            throw new InvalidArgumentException('Adresse IPv4 invalide');
        }
        return (int) sprintf('%u', $value);
    }

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

    private static function writeJson($path, $value)
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $temporary = $path . '.tmp';
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException('Écriture de l’état de découverte impossible');
        }
        @chmod($temporary, 0600);
        rename($temporary, $path);
        @chmod($path, 0600);
    }

    private static function phpCli()
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
        throw new RuntimeException('Interpréteur PHP CLI introuvable');
    }

    private static function log($logger, $level, $message)
    {
        if (!is_callable($logger)) {
            return;
        }
        $message = preg_replace('/[\r\n]+/', ' ', (string) $message);
        call_user_func($logger, (string) $level, substr($message, 0, 1800));
    }
}
