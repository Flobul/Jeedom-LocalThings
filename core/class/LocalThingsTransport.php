<?php

class LocalThingsCertificateStore
{
    private const SAMSUNG_CLOUD_HOST = 'connect-v2.samsungiotcloud.com';
    private const DEFAULT_BUNDLE_URL = 'https://raw.githubusercontent.com/brayStorm/samsung-appliance-token/main/cert.pem';
    private const MAX_BUNDLE_SIZE = 524288;

    private $root;
    private $certificateDirectory;
    private $deviceDirectory;
    private $caCertificatePath;
    private $caKeyPath;
    private $uuidPath;

    public function __construct($dataDirectory)
    {
        $this->root = rtrim((string) $dataDirectory, '/');
        if ($this->root === '') {
            throw new InvalidArgumentException('Répertoire de certificats invalide');
        }
        $this->certificateDirectory = $this->root . '/certificates';
        $this->deviceDirectory = $this->root . '/devices';
        $this->caCertificatePath = $this->certificateDirectory . '/ca-chain.pem';
        $this->caKeyPath = $this->certificateDirectory . '/ca.key';
        $this->uuidPath = $this->certificateDirectory . '/samsung-cloud.uuid';
        $this->ensureDirectory($this->root);
        $this->ensureDirectory($this->certificateDirectory);
        $this->ensureDirectory($this->deviceDirectory);
    }

    public function isConfigured()
    {
        if (!is_file($this->caCertificatePath) || !is_file($this->caKeyPath)) {
            return false;
        }
        try {
            $certificate = $this->firstCertificate((string) file_get_contents($this->caCertificatePath));
            $key = openssl_pkey_get_private((string) file_get_contents($this->caKeyPath));
            return $certificate !== null
                && $key !== false
                && openssl_x509_check_private_key($certificate, $key);
        } catch (Exception $exception) {
            return false;
        }
    }

    public function status()
    {
        $result = array(
            'configured' => false,
            'fingerprint' => '',
            'subject' => '',
            'expires' => '',
            'uuid' => '',
        );
        if (!$this->isConfigured()) {
            return $result;
        }
        try {
            $certificate = $this->firstCertificate((string) file_get_contents($this->caCertificatePath));
            $parsed = openssl_x509_parse($certificate, false);
            if (!is_array($parsed)) {
                throw new RuntimeException('Lecture du certificat impossible');
            }
            $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
            $subjectParts = array();
            foreach (($parsed['subject'] ?? array()) as $key => $value) {
                $subjectParts[] = $key . '=' . $value;
            }
            $result['configured'] = true;
            $result['fingerprint'] = strtoupper(implode(':', str_split((string) $fingerprint, 2)));
            $result['subject'] = implode(', ', $subjectParts);
            $result['expires'] = isset($parsed['validTo_time_t'])
                ? gmdate('c', (int) $parsed['validTo_time_t'])
                : '';
            if (is_file($this->uuidPath)) {
                $uuid = trim((string) file_get_contents($this->uuidPath));
                if (self::isUuid($uuid)) {
                    $result['uuid'] = strtolower($uuid);
                }
            }
        } catch (Exception $exception) {
            $result['error'] = $exception->getMessage();
        }
        return $result;
    }

    public function install($certificatePem, $privateKeyPem)
    {
        $certificatePem = (string) $certificatePem;
        $privateKeyPem = (string) $privateKeyPem;
        if (strlen($certificatePem) > self::MAX_BUNDLE_SIZE || strlen($privateKeyPem) > self::MAX_BUNDLE_SIZE) {
            throw new InvalidArgumentException('Matériel PEM trop volumineux');
        }
        $certificates = $this->certificateBlocks($certificatePem);
        $keys = $this->privateKeyBlocks($privateKeyPem);
        if (count($certificates) === 0) {
            throw new InvalidArgumentException('Aucun certificat PEM trouvé');
        }
        if (count($keys) !== 1) {
            throw new InvalidArgumentException('La clé privée PEM est absente ou ambiguë');
        }

        $certificate = openssl_x509_read($certificates[0]);
        $key = openssl_pkey_get_private($keys[0]);
        if ($certificate === false || $key === false) {
            throw new InvalidArgumentException('Certificat ou clé privée invalide');
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new InvalidArgumentException('Le profil Samsung exige une clé privée RSA');
        }
        if (!openssl_x509_check_private_key($certificate, $key)) {
            throw new InvalidArgumentException('Le certificat et la clé privée ne correspondent pas');
        }

        $chain = implode("\n", array_map('trim', $certificates)) . "\n";
        $normalizedKey = trim($keys[0]) . "\n";
        $this->atomicWrite($this->caCertificatePath, $chain, 0600);
        $this->atomicWrite($this->caKeyPath, $normalizedKey, 0600);
        $this->removeGeneratedLeaves();
        return $this->status();
    }

    public function installBundle($bundle)
    {
        $bundle = (string) $bundle;
        if (strlen($bundle) > self::MAX_BUNDLE_SIZE) {
            throw new InvalidArgumentException('Le bundle est anormalement volumineux');
        }
        $certificates = $this->certificateBlocks($bundle);
        $keys = $this->privateKeyBlocks($bundle);
        if (count($certificates) === 0 || count($keys) !== 1) {
            throw new InvalidArgumentException('Le bundle doit contenir une clé privée et au moins un certificat');
        }
        return $this->install(implode("\n", $certificates), $keys[0]);
    }

    public function bootstrap($sourceUrl = self::DEFAULT_BUNDLE_URL)
    {
        $sourceUrl = trim((string) $sourceUrl);
        if (stripos($sourceUrl, 'https://') !== 0) {
            throw new InvalidArgumentException('La source du bundle doit utiliser HTTPS');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('L’extension PHP cURL est nécessaire');
        }
        $buffer = '';
        $curl = curl_init($sourceUrl);
        curl_setopt_array($curl, array(
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'Jeedom-LocalThings/0.2',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$buffer) {
                if (strlen($buffer) + strlen($chunk) > self::MAX_BUNDLE_SIZE) {
                    return 0;
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ));
        $success = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($success !== true || $status !== 200) {
            throw new RuntimeException(
                'Téléchargement du bundle impossible'
                . ($status > 0 ? ' (HTTP ' . $status . ')' : '')
                . ($error !== '' ? ' : ' . $error : '')
            );
        }
        return $this->installBundle($buffer);
    }

    public function samsungUuid()
    {
        if (is_file($this->uuidPath)) {
            $cached = trim((string) file_get_contents($this->uuidPath));
            if (self::isUuid($cached)) {
                return strtolower($cached);
            }
        }
        $context = stream_context_create(array(
            'ssl' => array(
                'capture_peer_cert' => true,
                'capture_peer_cert_chain' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => self::SAMSUNG_CLOUD_HOST,
            ),
        ));
        $errno = 0;
        $error = '';
        $stream = @stream_socket_client(
            'ssl://' . self::SAMSUNG_CLOUD_HOST . ':443',
            $errno,
            $error,
            15,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($stream)) {
            throw new RuntimeException('Lecture du certificat Samsung impossible : ' . $error);
        }
        $parameters = stream_context_get_params($stream);
        fclose($stream);
        $sslOptions = $parameters['options']['ssl'] ?? array();
        $certificates = array();
        if (isset($sslOptions['peer_certificate'])) {
            $certificates[] = $sslOptions['peer_certificate'];
        }
        foreach (($sslOptions['peer_certificate_chain'] ?? array()) as $certificate) {
            $certificates[] = $certificate;
        }
        if (count($certificates) === 0) {
            throw new RuntimeException('Certificat Samsung distant absent');
        }

        $subjects = array();
        foreach ($certificates as $certificate) {
            // short_names=false returns "organizationalUnitName", not "OU".
            $parsed = openssl_x509_parse($certificate, false);
            if (!is_array($parsed)) {
                continue;
            }
            $uuid = self::extractSamsungUuid($parsed);
            if ($uuid !== '') {
                $this->atomicWrite($this->uuidPath, $uuid . "\n", 0600);
                return $uuid;
            }
            $subject = self::subjectSummary($parsed);
            if ($subject !== '') {
                $subjects[] = $subject;
            }
        }
        throw new RuntimeException(
            'UUID Samsung absent du certificat de passerelle'
            . (count($subjects) > 0 ? ' (sujets reçus : ' . implode(' | ', array_unique($subjects)) . ')' : '')
        );
    }

    public static function extractSamsungUuid(array $parsedCertificate)
    {
        $values = array();
        $subject = $parsedCertificate['subject'] ?? array();
        foreach ((array) $subject as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (!in_array($normalizedKey, array('ou', 'organizationalunitname', 'cn', 'commonname'), true)) {
                continue;
            }
            foreach (self::scalarValues($value) as $item) {
                $values[] = $item;
            }
        }
        if (isset($parsedCertificate['name'])) {
            $values[] = (string) $parsedCertificate['name'];
        }

        foreach ($values as $value) {
            if (
                preg_match('/(?:urn:)?uuid\s*[:=]\s*([0-9a-f-]{36})/i', (string) $value, $matches)
                && self::isUuid($matches[1])
            ) {
                return strtolower($matches[1]);
            }
        }
        return '';
    }

    public function mintLeaf($deviceId)
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Les certificats LocalThings ne sont pas configurés');
        }
        $safeId = substr(hash('sha256', (string) $deviceId), 0, 24);
        $directory = $this->deviceDirectory . '/' . $safeId;
        $certificatePath = $directory . '/client-fullchain.pem';
        $keyPath = $directory . '/client.key';
        if ($this->validLeafPair($certificatePath, $keyPath)) {
            return array($certificatePath, $keyPath);
        }

        $uuid = $this->samsungUuid();
        $caPem = (string) file_get_contents($this->caCertificatePath);
        $caCertificate = $this->firstCertificate($caPem);
        $caKey = openssl_pkey_get_private((string) file_get_contents($this->caKeyPath));
        if ($caCertificate === null || $caKey === false) {
            throw new RuntimeException('Autorité de certification LocalThings invalide');
        }
        $leafKey = openssl_pkey_new(array(
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ));
        if ($leafKey === false) {
            throw new RuntimeException('Création de la clé cliente impossible');
        }
        $distinguishedName = array(
            'countryName' => 'KR',
            'organizationName' => 'Samsung Electronics',
            'organizationalUnitName' => 'uuid:' . $uuid,
            'commonName' => 'urn:uuid:' . $uuid,
        );
        $csr = openssl_csr_new($distinguishedName, $leafKey, array('digest_alg' => 'sha256'));
        if ($csr === false) {
            throw new RuntimeException('Création de la requête de certificat impossible');
        }
        $serial = random_int(1, 0x7FFFFFFF);
        $leafCertificate = openssl_csr_sign(
            $csr,
            $caCertificate,
            $caKey,
            3650,
            array('digest_alg' => 'sha256'),
            $serial
        );
        if ($leafCertificate === false) {
            throw new RuntimeException('Signature du certificat client impossible');
        }
        $leafPem = '';
        $leafKeyPem = '';
        if (!openssl_x509_export($leafCertificate, $leafPem) || !openssl_pkey_export($leafKey, $leafKeyPem)) {
            throw new RuntimeException('Export du certificat client impossible');
        }
        $this->ensureDirectory($directory);
        $this->atomicWrite($certificatePath, trim($leafPem) . "\n" . ltrim($caPem), 0600);
        $this->atomicWrite($keyPath, trim($leafKeyPem) . "\n", 0600);
        return array($certificatePath, $keyPath);
    }

    public function caCertificatePath()
    {
        return $this->caCertificatePath;
    }

    public function dataDirectory()
    {
        return $this->root;
    }

    private function validLeafPair($certificatePath, $keyPath)
    {
        if (!is_file($certificatePath) || !is_file($keyPath)) {
            return false;
        }
        $certificate = $this->firstCertificate((string) file_get_contents($certificatePath));
        $key = openssl_pkey_get_private((string) file_get_contents($keyPath));
        if ($certificate === null || $key === false || !openssl_x509_check_private_key($certificate, $key)) {
            return false;
        }
        $parsed = openssl_x509_parse($certificate);
        return !isset($parsed['validTo_time_t']) || (int) $parsed['validTo_time_t'] > time() + 86400;
    }

    private function removeGeneratedLeaves()
    {
        if (!is_dir($this->deviceDirectory)) {
            return;
        }
        foreach (glob($this->deviceDirectory . '/*') ?: array() as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            @unlink($directory . '/client-fullchain.pem');
            @unlink($directory . '/client.key');
            @rmdir($directory);
        }
    }

    private function certificateBlocks($value)
    {
        preg_match_all(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            (string) $value,
            $matches
        );
        return $matches[0] ?? array();
    }

    private function privateKeyBlocks($value)
    {
        preg_match_all(
            '/-----BEGIN (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----.*?-----END (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----/s',
            (string) $value,
            $matches
        );
        return $matches[0] ?? array();
    }

    private function firstCertificate($value)
    {
        $blocks = $this->certificateBlocks($value);
        if (count($blocks) === 0) {
            return null;
        }
        $certificate = openssl_x509_read($blocks[0]);
        return $certificate === false ? null : $certificate;
    }

    private function atomicWrite($path, $data, $mode)
    {
        $directory = dirname($path);
        $this->ensureDirectory($directory);
        $temporary = tempnam($directory, '.localthings-');
        if ($temporary === false) {
            throw new RuntimeException('Création du fichier temporaire impossible');
        }
        $handle = fopen($temporary, 'wb');
        if (!is_resource($handle)) {
            @unlink($temporary);
            throw new RuntimeException('Ouverture du fichier temporaire impossible');
        }
        $written = fwrite($handle, $data);
        fflush($handle);
        fclose($handle);
        if ($written !== strlen($data)) {
            @unlink($temporary);
            throw new RuntimeException('Écriture du fichier incomplète');
        }
        @chmod($temporary, $mode);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Installation atomique du fichier impossible');
        }
        @chmod($path, $mode);
    }

    private function ensureDirectory($path)
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Création du répertoire impossible : ' . $path);
        }
        @chmod($path, 0700);
    }

    private static function subjectSummary(array $parsedCertificate)
    {
        $parts = array();
        foreach (($parsedCertificate['subject'] ?? array()) as $key => $value) {
            foreach (self::scalarValues($value) as $item) {
                $parts[] = (string) $key . '=' . $item;
            }
        }
        return implode(', ', $parts);
    }

    private static function scalarValues($value)
    {
        if (!is_array($value)) {
            return array((string) $value);
        }
        $values = array();
        foreach ($value as $item) {
            foreach (self::scalarValues($item) as $scalar) {
                $values[] = $scalar;
            }
        }
        return $values;
    }

    private static function isUuid($value)
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (string) $value
        ) === 1;
    }
}

/**
 * DTLS transport backed by the system OpenSSL executable.
 *
 * PHP has no native dtls:// stream transport. proc_open keeps the encrypted
 * session inside OpenSSL while PHP sends and receives plaintext CoAP frames.
 */
class LocalThingsDtlsClient
{
    private const FRAME_IDLE_SECONDS = 0.05;

    private $openssl;
    private $host;
    private $port;
    private $localPort;
    private $certificatePath;
    private $certificateChainPath;
    private $keyPath;
    private $rootCaPath;
    private $process;
    private $pipes = array();
    private $stderr = '';
    private $receiveBuffer = '';
    private $lastReceiveAt = 0.0;
    private $closed = false;
    private $logger;

    public function __construct(
        $openssl,
        $host,
        $port,
        $localPort,
        $certificatePath,
        $certificateChainPath,
        $keyPath,
        $rootCaPath,
        $logger = null
    ) {
        $this->openssl = (string) $openssl;
        $this->host = (string) $host;
        $this->port = (int) $port;
        $this->localPort = (int) $localPort;
        $this->certificatePath = (string) $certificatePath;
        $this->certificateChainPath = (string) $certificateChainPath;
        $this->keyPath = (string) $keyPath;
        $this->rootCaPath = (string) $rootCaPath;
        $this->logger = is_callable($logger) ? $logger : null;
        $this->validateConfiguration();
    }

    public function connect($timeout = 12.0)
    {
        if (is_resource($this->process)) {
            return;
        }
        $started = microtime(true);
        $this->log(
            'info',
            '[DTLS] Handshake ' . $this->host . ':' . $this->port
            . ' depuis 0.0.0.0:' . $this->localPort
            . ' avec ' . $this->openssl
        );
        $command = array(
            $this->openssl,
            's_client',
            '-dtls1_2',
            '-connect',
            $this->host . ':' . $this->port,
            '-bind',
            '0.0.0.0:' . $this->localPort,
            '-cert',
            $this->certificatePath,
            '-cert_chain',
            $this->certificateChainPath,
            '-key',
            $this->keyPath,
            '-CAfile',
            $this->rootCaPath,
            '-verify_return_error',
            '-cipher',
            'ECDHE-ECDSA-AES128-GCM-SHA256:@SECLEVEL=0',
            '-mtu',
            '1200',
            '-brief',
            '-state',
            '-quiet',
            '-ign_eof',
        );
        $descriptor = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $options = array('suppress_errors' => true, 'bypass_shell' => true);
        $this->process = @proc_open($command, $descriptor, $this->pipes, null, null, $options);
        if (!is_resource($this->process)) {
            throw new RuntimeException('Démarrage du client OpenSSL DTLS impossible');
        }
        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $this->closed = false;

        $deadline = microtime(true) + max(1.0, (float) $timeout);
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);
            $this->drainStderr();
            if (!$status['running']) {
                $error = $this->errorSummary();
                $this->close();
                $this->log(
                    'warning',
                    '[DTLS] Processus OpenSSL arrêté pendant le handshake'
                    . ($error !== '' ? ' : ' . $error : '')
                );
                throw new RuntimeException('Connexion DTLS refusée' . ($error !== '' ? ' : ' . $error : ''));
            }
            if (
                stripos($this->stderr, 'CONNECTION ESTABLISHED') !== false
                || stripos($this->stderr, 'Protocol version: DTLS') !== false
                || stripos($this->stderr, 'Ciphersuite:') !== false
            ) {
                $this->log(
                    'info',
                    '[DTLS] Handshake réussi en '
                    . (int) round((microtime(true) - $started) * 1000)
                    . ' ms' . $this->handshakeSummary()
                );
                return;
            }
            usleep(50000);
        }
        $error = $this->errorSummary();
        $this->close();
        $this->log(
            'warning',
            '[DTLS] Timeout du handshake après '
            . (int) round((microtime(true) - $started) * 1000)
            . ' ms' . ($error !== '' ? ' : ' . $error : '')
        );
        throw new RuntimeException('Délai de négociation DTLS dépassé' . ($error !== '' ? ' : ' . $error : ''));
    }

    public function write($data)
    {
        if (!is_resource($this->process) || $this->closed) {
            throw new RuntimeException('Session DTLS fermée');
        }
        $data = (string) $data;
        $offset = 0;
        $length = strlen($data);
        $deadline = microtime(true) + 3.0;
        while ($offset < $length) {
            $written = @fwrite($this->pipes[0], substr($data, $offset));
            if ($written === false) {
                throw new RuntimeException('Écriture DTLS impossible : ' . $this->errorSummary());
            }
            if ($written === 0) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Délai d’écriture DTLS dépassé');
                }
                usleep(10000);
                continue;
            }
            $offset += $written;
        }
        fflush($this->pipes[0]);
        $this->log('debug', '[DTLS] ' . $length . ' octets applicatifs envoyés');
    }

    public function readFrame($timeout)
    {
        $deadline = microtime(true) + max(0.01, (float) $timeout);
        while (microtime(true) < $deadline) {
            $frame = $this->takeBufferedFrame(false);
            if ($frame !== null) {
                return $frame;
            }
            $remaining = max(0.0, $deadline - microtime(true));
            $frameLength = LocalThingsCoap::streamFrameLength($this->receiveBuffer);
            if ($this->receiveBuffer !== '' && $frameLength === null) {
                $idleRemaining = self::FRAME_IDLE_SECONDS - (microtime(true) - $this->lastReceiveAt);
                if ($idleRemaining <= 0) {
                    return $this->takeBufferedFrame(true);
                }
                $remaining = min($remaining, $idleRemaining);
            }
            $seconds = (int) floor($remaining);
            $microseconds = (int) (($remaining - $seconds) * 1000000);
            $read = array($this->pipes[1], $this->pipes[2]);
            $write = null;
            $except = null;
            $selected = @stream_select($read, $write, $except, $seconds, $microseconds);
            if ($selected === false) {
                throw new RuntimeException('Attente DTLS interrompue');
            }
            if ($selected === 0) {
                break;
            }
            foreach ($read as $stream) {
                if ($stream === $this->pipes[2]) {
                    $this->drainStderr();
                    continue;
                }
                $chunk = @fread($stream, 65535);
                if ($chunk === false) {
                    throw new RuntimeException('Lecture DTLS impossible');
                }
                if ($chunk !== '') {
                    $this->log('debug', '[DTLS] ' . strlen($chunk) . ' octets applicatifs reçus');
                    $this->receiveBuffer .= $chunk;
                    $this->lastReceiveAt = microtime(true);
                }
            }
            if (!$this->isRunning()) {
                throw new RuntimeException('Session DTLS interrompue : ' . $this->errorSummary());
            }
        }
        $frame = $this->takeBufferedFrame(true);
        if ($frame !== null) {
            return $frame;
        }
        if ($this->receiveBuffer !== '') {
            $expected = LocalThingsCoap::streamFrameLength($this->receiveBuffer);
            $this->log(
                'warning',
                '[DTLS] Trame CoAP incomplète écartée : reçue=' . strlen($this->receiveBuffer)
                . (is_int($expected) && $expected > 0 ? ', attendue=' . $expected : '')
            );
            $this->receiveBuffer = '';
        }
        return null;
    }

    public function errorSummary()
    {
        $this->drainStderr();
        $lines = preg_split('/[\r\n]+/', trim($this->stderr));
        $lines = array_values(array_filter($lines, function ($line) {
            return trim($line) !== ''
                && stripos($line, 'verify depth') === false
                && stripos($line, 'Verification: OK') === false;
        }));
        return implode(' | ', array_slice($lines, -4));
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        $this->pipes = array();
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                @proc_terminate($this->process);
                $deadline = microtime(true) + 1.0;
                do {
                    usleep(20000);
                    $status = proc_get_status($this->process);
                } while ($status['running'] && microtime(true) < $deadline);
                if ($status['running']) {
                    @proc_terminate($this->process, 9);
                }
            }
            @proc_close($this->process);
        }
        $this->process = null;
        $this->receiveBuffer = '';
        $this->lastReceiveAt = 0.0;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function takeBufferedFrame($allowVariableLength)
    {
        if ($this->receiveBuffer === '') {
            return null;
        }
        $expected = LocalThingsCoap::streamFrameLength($this->receiveBuffer);
        if (is_int($expected)) {
            if ($expected <= 0 || strlen($this->receiveBuffer) < $expected) {
                return null;
            }
            $frame = substr($this->receiveBuffer, 0, $expected);
            $this->receiveBuffer = (string) substr($this->receiveBuffer, $expected);
            return $frame;
        }
        if (!$allowVariableLength) {
            return null;
        }
        $frame = $this->receiveBuffer;
        $this->receiveBuffer = '';
        return $frame;
    }

    private function drainStderr()
    {
        if (!isset($this->pipes[2]) || !is_resource($this->pipes[2])) {
            return;
        }
        while (($chunk = @fread($this->pipes[2], 8192)) !== false && $chunk !== '') {
            $this->stderr .= $chunk;
            if (strlen($this->stderr) > 32768) {
                $this->stderr = substr($this->stderr, -32768);
            }
        }
    }

    private function isRunning()
    {
        if (!is_resource($this->process)) {
            return false;
        }
        $status = proc_get_status($this->process);
        return !empty($status['running']);
    }

    private function validateConfiguration()
    {
        if (!is_executable($this->openssl)) {
            throw new InvalidArgumentException('Exécutable OpenSSL introuvable');
        }
        if (filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('Adresse IPv4 DTLS invalide');
        }
        if ($this->port < 1 || $this->port > 65535 || $this->localPort < 1024 || $this->localPort > 65535) {
            throw new InvalidArgumentException('Port DTLS invalide');
        }
        foreach (array($this->certificatePath, $this->certificateChainPath, $this->keyPath, $this->rootCaPath) as $path) {
            if (!is_file($path) || !is_readable($path)) {
                throw new InvalidArgumentException('Fichier DTLS illisible : ' . $path);
            }
        }
        if (!function_exists('proc_open')) {
            throw new RuntimeException('La fonction PHP proc_open est désactivée');
        }
    }

    private function handshakeSummary()
    {
        $details = array();
        foreach (preg_split('/[\r\n]+/', $this->stderr) as $line) {
            $line = trim($line);
            if (
                stripos($line, 'Protocol version:') === 0
                || stripos($line, 'Ciphersuite:') === 0
            ) {
                $details[] = $line;
            }
        }
        return count($details) > 0 ? ' (' . implode(', ', array_unique($details)) . ')' : '';
    }

    private function log($level, $message)
    {
        if ($this->logger === null) {
            return;
        }
        $message = preg_replace('/[\r\n]+/', ' ', (string) $message);
        call_user_func($this->logger, (string) $level, substr($message, 0, 1800));
    }
}
