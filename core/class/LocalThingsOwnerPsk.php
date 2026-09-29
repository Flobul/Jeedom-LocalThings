<?php

/** OwnerPSK primitives and private, per-host runtime credentials. No ownership writes. */
class LocalThingsOwnerPsk
{
    private $directory;
    private const PREFIX = "<?php http_response_code(404); exit; ?>\n";

    public function __construct($dataDirectory)
    {
        $this->directory = $dataDirectory . '/ownerpsk';
    }

    public static function uuidBytes($uuid)
    {
        if (!is_string($uuid) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $uuid)) {
            throw new InvalidArgumentException('UUID OCF canonique attendu');
        }
        $bytes = hex2bin(str_replace('-', '', $uuid));
        if ($bytes === str_repeat("\0", 16)) { throw new InvalidArgumentException('UUID OCF nul interdit'); }
        return $bytes;
    }

    public static function validate(array $credential)
    {
        $owner = self::uuidBytes($credential['owner_uuid'] ?? null);
        self::uuidBytes($credential['device_uuid'] ?? null);
        if (strpos($owner, "\0") !== false) {
            throw new InvalidArgumentException('UUID propriétaire incompatible avec le transport OpenSSL : octet nul');
        }
        if (!isset($credential['key']) || !is_string($credential['key'])
            || !preg_match('/^[a-fA-F0-9]{32}$/D', $credential['key'])) {
            throw new InvalidArgumentException('OwnerPSK attendue : 32 caractères hexadécimaux (16 octets)');
        }
        return array('owner_uuid' => $credential['owner_uuid'], 'device_uuid' => $credential['device_uuid'],
            'key' => strtolower($credential['key']));
    }

    /** Pure derivation: callers must supply an authorized manufacturer session. Never claims an appliance. */
    public static function derive($masterSecret, $clientRandom, $serverRandom, $ownerUuid, $deviceUuid, $oxm, $cipher)
    {
        foreach (array(array($masterSecret, 48), array($clientRandom, 32), array($serverRandom, 32)) as $part) {
            if (!is_string($part[0]) || strlen($part[0]) !== $part[1]) { throw new InvalidArgumentException('État DTLS de dérivation invalide'); }
        }
        if ($cipher !== 'ECDHE-ECDSA-AES128-GCM-SHA256' || !in_array($oxm, array(2, 65282), true)) {
            throw new InvalidArgumentException('Profil de dérivation OwnerPSK non pris en charge');
        }
        $label = $oxm === 2 ? 'oic.sec.doxm.mfgcert' : 'x.org.iotivity.conmfgcert';
        $keyBlock = self::pHash($masterSecret, 'key expansion' . $serverRandom . $clientRandom, 120);
        return self::pHash($keyBlock, $label . self::uuidBytes($ownerUuid) . self::uuidBytes($deviceUuid), 16);
    }

    private static function pHash($key, $seed, $length)
    {
        $a = $seed; $output = '';
        while (strlen($output) < $length) {
            $a = hash_hmac('sha256', $a, $key, true);
            $output .= hash_hmac('sha256', $a . $seed, $key, true);
        }
        return substr($output, 0, $length);
    }

    private function path($host)
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) { throw new InvalidArgumentException('Adresse IPv4 invalide'); }
        return $this->directory . '/' . hash('sha256', $host) . '.php';
    }

    public function load($host)
    {
        $path = $this->path($host);
        if (!is_file($path)) { return null; }
        $raw = file_get_contents($path);
        if (substr($raw, 0, strlen(self::PREFIX)) !== self::PREFIX) { throw new RuntimeException('Stockage OwnerPSK invalide'); }
        $data = json_decode(substr($raw, strlen(self::PREFIX)), true);
        if (!is_array($data)) { throw new RuntimeException('Stockage OwnerPSK invalide'); }
        return self::validate($data);
    }

    /** Publish only after a fresh PSK session proved the expected device and protected access. */
    public function saveVerified($host, array $credential)
    {
        $credential = self::validate($credential);
        $path = $this->path($host);
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Stockage OwnerPSK inaccessible');
        }
        if (!chmod($this->directory, 0700)) { throw new RuntimeException('Permissions OwnerPSK non garanties'); }
        $temporary = tempnam($this->directory, '.pending-');
        if ($temporary === false) { throw new RuntimeException('Stockage OwnerPSK inaccessible'); }
        $protectedTemporary = $temporary . '.php';
        if (!rename($temporary, $protectedTemporary)) {
            unlink($temporary);
            throw new RuntimeException('Préparation du stockage OwnerPSK impossible');
        }
        $temporary = $protectedTemporary;
        try {
            if (!chmod($temporary, 0600)) { throw new RuntimeException('Permissions OwnerPSK non garanties'); }
            $data = self::PREFIX . json_encode($credential, JSON_UNESCAPED_SLASHES);
            if (file_put_contents($temporary, $data, LOCK_EX) !== strlen($data) || !rename($temporary, $path)) {
                throw new RuntimeException('Enregistrement OwnerPSK impossible');
            }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }
}

/** DTLS PSK over a private pipe. Binary UUID, not its text spelling, is the PSK identity. */
class LocalThingsOwnerPskTransport extends LocalThingsDtlsClient
{
    private $host;
    private $port;
    private $credential;
    private $process;
    private $pipes = array();

    public function __construct($host, $port, array $credential)
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Endpoint OwnerPSK invalide');
        }
        $this->host = $host; $this->port = (int) $port;
        $this->credential = LocalThingsOwnerPsk::validate($credential);
    }

    public function connect($timeout = 12.0)
    {
        if (is_resource($this->process)) { return; }
        $this->process = proc_open(array('python3', __DIR__ . '/../../resources/owner_psk_transport.py'),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')),
            $this->pipes, null, null, array('bypass_shell' => true));
        if (!is_resource($this->process)) { throw new RuntimeException('Transport OwnerPSK indisponible (Python 3 et libssl requis)'); }
        stream_set_blocking($this->pipes[1], false);
        try {
            $this->rpc(array('action' => 'connect', 'host' => $this->host, 'port' => $this->port,
                'source_port' => 40000 + ((ip2long($this->host) & 0xFFFF) % 20000),
                'identity' => bin2hex(LocalThingsOwnerPsk::uuidBytes($this->credential['owner_uuid'])),
                'key' => $this->credential['key'], 'timeout' => $timeout), $timeout + 2);
            // Verify the device binding before exposing this session to normal reads/writes.
            $session = new LocalThingsSession($this);
            list($code, $payload) = $session->get('/oic/d', 8.0);
            $device = $code === 69 ? LocalThingsCbor::decode($payload) : array();
            if (!is_array($device) || ($device['di'] ?? '') !== $this->credential['device_uuid']) {
                throw new RuntimeException('Identité de l’appareil OwnerPSK non confirmée');
            }
        } catch (Throwable $error) { $this->close(); throw $error; }
    }

    private function rpc(array $request, $timeout)
    {
        if (!is_resource($this->process)) { throw new RuntimeException('Session OwnerPSK fermée'); }
        $data = json_encode($request) . "\n";
        $offset = 0;
        while ($offset < strlen($data)) {
            $written = fwrite($this->pipes[0], substr($data, $offset));
            if (!$written) { throw new RuntimeException('Transport OwnerPSK interrompu'); }
            $offset += $written;
        }
        fflush($this->pipes[0]);
        $deadline = microtime(true) + max(.1, $timeout); $response = '';
        while (microtime(true) < $deadline) {
            if (class_exists('LocalThingsDiscovery', false)) { LocalThingsDiscovery::checkpoint(); }
            $response .= stream_get_contents($this->pipes[1]);
            if (strpos($response, "\n") !== false) {
                $decoded = json_decode($response, true);
                if (!is_array($decoded) || empty($decoded['ok'])) { throw new RuntimeException('Session OwnerPSK impossible ou interrompue'); }
                return $decoded['result'] ?? null;
            }
            if (strlen($response) > 200000 || feof($this->pipes[1])) { break; }
            usleep(10000);
        }
        throw new RuntimeException('Délai OwnerPSK dépassé ou transport indisponible');
    }

    public function write($data) { $this->rpc(array('action' => 'write', 'data' => base64_encode($data)), 4.0); }
    public function readFrame($timeout)
    {
        $result = $this->rpc(array('action' => 'read', 'timeout' => $timeout), $timeout + 1);
        if ($result === null) { return null; }
        $frame = base64_decode($result, true);
        if ($frame === false) { throw new RuntimeException('Trame OwnerPSK invalide'); }
        return $frame;
    }
    public function errorSummary() { return 'Transport OwnerPSK'; }
    public function close()
    {
        foreach ($this->pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        $this->pipes = array();
        if (is_resource($this->process)) { proc_terminate($this->process); proc_close($this->process); }
        $this->process = null;
    }
    public function __destruct() { $this->close(); }
}
