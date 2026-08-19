<?php

/**
 * Stocke l'autorité LocalThings et génère les certificats clients par appareil.
 */
class LocalThingsCertificateStore
{
    private const SAMSUNG_CLOUD_HOST = 'connect-v2.samsungiotcloud.com';
    private const DEFAULT_BUNDLE_URL = 'https://raw.githubusercontent.com/brayStorm/samsung-appliance-token/72c2a947363c6b50dee3e071a7b87a5784b7dc0c/cert.pem';
    private const DEFAULT_BUNDLE_SHA256 = 'eaf6f4cd10e79d8dae437ad9db31a839e5ecacd84f5fb3d220f73954d06aa67d';
    private const MAX_BUNDLE_SIZE = 524288;

    private $root;
    private $certificateDirectory;
    private $deviceDirectory;
    private $caCertificatePath;
    private $caKeyPath;
    private $uuidPath;
    private $logger;

    /**
     * Initialise l'arborescence privée des certificats.
     *
     * @param string $dataDirectory Répertoire persistant du plugin.
     * @param callable|null $logger Journaliseur facultatif.
     * @throws InvalidArgumentException Lorsque le chemin est vide.
     */
    public function __construct($dataDirectory, $logger = null)
    {
        $this->root = rtrim((string) $dataDirectory, '/');
        if ($this->root === '') {
            throw new InvalidArgumentException(__('Répertoire de certificats invalide', __FILE__));
        }
        $this->certificateDirectory = $this->root . '/certificates';
        $this->deviceDirectory = $this->root . '/devices';
        $this->caCertificatePath = $this->certificateDirectory . '/ca-chain.pem';
        $this->caKeyPath = $this->certificateDirectory . '/ca.key';
        $this->uuidPath = $this->certificateDirectory . '/samsung-cloud.uuid';
        $this->logger = is_callable($logger) ? $logger : null;
        $this->ensureDirectory($this->root);
        $this->ensureDirectory($this->certificateDirectory);
        $this->ensureDirectory($this->deviceDirectory);
    }

    /**
     * Vérifie la présence et la cohérence de l'autorité de certification.
     *
     * @return bool
     */
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

    /**
     * Retourne les informations publiques de l'autorité installée.
     *
     * @return array<string,mixed>
     */
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
                throw new RuntimeException(__('Lecture du certificat impossible', __FILE__));
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

    /**
     * Valide puis installe atomiquement une chaîne et sa clé privée RSA.
     *
     * @param string $certificatePem Chaîne de certificats PEM.
     * @param string $privateKeyPem Clé privée PEM.
     * @return array<string,mixed> Nouvel état du magasin.
     */
    public function install($certificatePem, $privateKeyPem)
    {
        $certificatePem = (string) $certificatePem;
        $privateKeyPem = (string) $privateKeyPem;
        if (strlen($certificatePem) > self::MAX_BUNDLE_SIZE || strlen($privateKeyPem) > self::MAX_BUNDLE_SIZE) {
            throw new InvalidArgumentException(__('Matériel PEM trop volumineux', __FILE__));
        }
        $certificates = $this->certificateBlocks($certificatePem);
        $keys = $this->privateKeyBlocks($privateKeyPem);
        if (count($certificates) === 0) {
            throw new InvalidArgumentException(__('Aucun certificat PEM trouvé', __FILE__));
        }
        if (count($keys) !== 1) {
            throw new InvalidArgumentException(__('La clé privée PEM est absente ou ambiguë', __FILE__));
        }

        $certificate = openssl_x509_read($certificates[0]);
        $key = openssl_pkey_get_private($keys[0]);
        if ($certificate === false || $key === false) {
            throw new InvalidArgumentException(__('Certificat ou clé privée invalide', __FILE__));
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new InvalidArgumentException(__('Le profil Samsung exige une clé privée RSA', __FILE__));
        }
        if (!openssl_x509_check_private_key($certificate, $key)) {
            throw new InvalidArgumentException(__('Le certificat et la clé privée ne correspondent pas', __FILE__));
        }

        $chain = implode("\n", array_map('trim', $certificates)) . "\n";
        $normalizedKey = trim($keys[0]) . "\n";
        $this->atomicWrite($this->caCertificatePath, $chain, 0600);
        $this->atomicWrite($this->caKeyPath, $normalizedKey, 0600);
        $this->removeGeneratedLeaves();
        return $this->status();
    }

    /**
     * Installe un bundle PEM contenant certificats et clé privée.
     *
     * @param string $bundle Bundle PEM complet.
     * @return array<string,mixed> Nouvel état du magasin.
     */
    public function installBundle($bundle)
    {
        $bundle = (string) $bundle;
        if (strlen($bundle) > self::MAX_BUNDLE_SIZE) {
            throw new InvalidArgumentException(__('Le bundle est anormalement volumineux', __FILE__));
        }
        $certificates = $this->certificateBlocks($bundle);
        $keys = $this->privateKeyBlocks($bundle);
        if (count($certificates) === 0 || count($keys) !== 1) {
            throw new InvalidArgumentException(__('Le bundle doit contenir une clé privée et au moins un certificat', __FILE__));
        }
        return $this->install(implode("\n", $certificates), $keys[0]);
    }

    /**
     * Télécharge puis installe le bundle communautaire de certificats.
     *
     * @param string $sourceUrl URL HTTPS du bundle.
     * @return array<string,mixed> Nouvel état du magasin.
     */
    public function bootstrap($sourceUrl = self::DEFAULT_BUNDLE_URL)
    {
        $sourceUrl = trim((string) $sourceUrl);
        if (stripos($sourceUrl, 'https://') !== 0) {
            throw new InvalidArgumentException(__('La source du bundle doit utiliser HTTPS', __FILE__));
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException(__('L’extension PHP cURL est nécessaire', __FILE__));
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
        unset($curl);
        if ($success !== true || $status !== 200) {
            throw new RuntimeException(
                __('Téléchargement du bundle impossible', __FILE__)
                . ($status > 0 ? ' (HTTP ' . $status . ')' : '')
                . ($error !== '' ? ' : ' . $error : '')
            );
        }
        if (
            $sourceUrl === self::DEFAULT_BUNDLE_URL
            && !hash_equals(self::DEFAULT_BUNDLE_SHA256, hash('sha256', $buffer))
        ) {
            throw new RuntimeException(__('L’empreinte du bundle communautaire est invalide', __FILE__));
        }
        return $this->installBundle($buffer);
    }

    /**
     * Obtient l'UUID attendu dans les certificats clients Samsung.
     *
     * @return string UUID Samsung normalisé.
     */
    public function samsungUuid()
    {
        if (is_file($this->uuidPath)) {
            $cached = trim((string) file_get_contents($this->uuidPath));
            if (self::isUuid($cached)) {
                return strtolower($cached);
            }
        }
        try {
            $certificates = $this->samsungPeerCertificates(true);
        } catch (Exception $exception) {
            $this->log(
                'warning',
                __('La validation TLS du certificat Samsung a échoué ; nouvelle tentative en mode compatible sans validation : ', __FILE__)
                . $exception->getMessage()
            );
            $certificates = $this->samsungPeerCertificates(false);
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
        $message = __('UUID Samsung absent du certificat de passerelle', __FILE__);
        if (count($subjects) > 0) {
            $message .= ' ' . sprintf(
                __('(sujets reçus : %s)', __FILE__),
                implode(' | ', array_unique($subjects))
            );
        }
        throw new RuntimeException($message);
    }

    /**
     * Lit la chaîne de certificats présentée par la passerelle Samsung.
     *
     * @param bool $verifyPeer Active la validation de la chaîne et du nom TLS.
     * @return array<int,mixed> Ressources de certificats OpenSSL.
     */
    private function samsungPeerCertificates($verifyPeer)
    {
        $context = stream_context_create(array(
            'ssl' => array(
                'capture_peer_cert' => true,
                'capture_peer_cert_chain' => true,
                'verify_peer' => (bool) $verifyPeer,
                'verify_peer_name' => (bool) $verifyPeer,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'peer_name' => self::SAMSUNG_CLOUD_HOST,
            ),
        ));
        $errno = 0;
        $error = '';
        if (function_exists('error_clear_last')) {
            error_clear_last();
        }
        $stream = @stream_socket_client(
            'ssl://' . self::SAMSUNG_CLOUD_HOST . ':443',
            $errno,
            $error,
            15,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($stream)) {
            $lastError = error_get_last();
            if ($error === '' && is_array($lastError) && !empty($lastError['message'])) {
                $error = (string) $lastError['message'];
            }
            throw new RuntimeException(
                __('Lecture du certificat Samsung impossible', __FILE__)
                . ($error !== '' ? ' : ' . $error : '')
            );
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
            throw new RuntimeException(__('Certificat Samsung distant absent', __FILE__));
        }
        return $certificates;
    }

    /**
     * Extrait un UUID Samsung d'un certificat déjà analysé par OpenSSL.
     *
     * @param array<string,mixed> $parsedCertificate Certificat analysé.
     * @return string UUID normalisé, ou chaîne vide.
     */
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

    /**
     * Retourne ou génère un certificat client propre à un appareil.
     *
     * @param string $deviceId Identifiant stable de l'appareil.
     * @return array{0:string,1:string} Chemins du certificat complet et de la clé.
     */
    public function mintLeaf($deviceId)
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException(__('Les certificats LocalThings ne sont pas configurés', __FILE__));
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
            throw new RuntimeException(__('Autorité de certification LocalThings invalide', __FILE__));
        }
        $leafKey = openssl_pkey_new(array(
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ));
        if ($leafKey === false) {
            throw new RuntimeException(__('Création de la clé cliente impossible', __FILE__));
        }
        $distinguishedName = array(
            'countryName' => 'KR',
            'organizationName' => 'Samsung Electronics',
            'organizationalUnitName' => 'uuid:' . $uuid,
            'commonName' => 'urn:uuid:' . $uuid,
        );
        $csr = openssl_csr_new($distinguishedName, $leafKey, array('digest_alg' => 'sha256'));
        if ($csr === false) {
            throw new RuntimeException(__('Création de la requête de certificat impossible', __FILE__));
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
            throw new RuntimeException(__('Signature du certificat client impossible', __FILE__));
        }
        $leafPem = '';
        $leafKeyPem = '';
        if (!openssl_x509_export($leafCertificate, $leafPem) || !openssl_pkey_export($leafKey, $leafKeyPem)) {
            throw new RuntimeException(__('Export du certificat client impossible', __FILE__));
        }
        $this->ensureDirectory($directory);
        $this->atomicWrite($certificatePath, trim($leafPem) . "\n" . ltrim($caPem), 0600);
        $this->atomicWrite($keyPath, trim($leafKeyPem) . "\n", 0600);
        return array($certificatePath, $keyPath);
    }

    /**
     * Retourne le chemin de la chaîne de l'autorité installée.
     *
     * @return string
     */
    public function caCertificatePath()
    {
        return $this->caCertificatePath;
    }

    /**
     * Retourne le répertoire racine du magasin.
     *
     * @return string
     */
    public function dataDirectory()
    {
        return $this->root;
    }

    /**
     * Vérifie qu'une paire cliente est cohérente et encore valide.
     *
     * @param string $certificatePath Chemin du certificat.
     * @param string $keyPath Chemin de la clé privée.
     * @return bool
     */
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
        if (
            !is_array($parsed)
            || (isset($parsed['validFrom_time_t']) && (int) $parsed['validFrom_time_t'] > time())
            || (isset($parsed['validTo_time_t']) && (int) $parsed['validTo_time_t'] <= time() + 86400)
        ) {
            return false;
        }
        $authority = $this->firstCertificate((string) file_get_contents($this->caCertificatePath));
        if ($authority === null) {
            return false;
        }
        $authorityKey = openssl_pkey_get_public($authority);
        return $authorityKey !== false && openssl_x509_verify($certificate, $authorityKey) === 1;
    }

    /**
     * Supprime les certificats clients dérivés après changement d'autorité.
     *
     * @return void
     */
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

    /**
     * Extrait les blocs de certificats d'un contenu PEM.
     *
     * @param string $value Contenu PEM.
     * @return string[]
     */
    private function certificateBlocks($value)
    {
        preg_match_all(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            (string) $value,
            $matches
        );
        return $matches[0] ?? array();
    }

    /**
     * Extrait les blocs de clés privées d'un contenu PEM.
     *
     * @param string $value Contenu PEM.
     * @return string[]
     */
    private function privateKeyBlocks($value)
    {
        preg_match_all(
            '/-----BEGIN (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----.*?-----END (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----/s',
            (string) $value,
            $matches
        );
        return $matches[0] ?? array();
    }

    /**
     * Lit le premier certificat d'une chaîne PEM.
     *
     * @param string $value Contenu PEM.
     * @return mixed Certificat OpenSSL ou `null`.
     */
    private function firstCertificate($value)
    {
        $blocks = $this->certificateBlocks($value);
        if (count($blocks) === 0) {
            return null;
        }
        $certificate = openssl_x509_read($blocks[0]);
        return $certificate === false ? null : $certificate;
    }

    /**
     * Remplace un fichier de manière atomique avec les permissions demandées.
     *
     * @param string $path Destination.
     * @param string $data Contenu binaire.
     * @param int $mode Permissions Unix.
     * @return void
     */
    private function atomicWrite($path, $data, $mode)
    {
        $directory = dirname($path);
        $this->ensureDirectory($directory);
        $temporary = tempnam($directory, '.localthings-');
        if ($temporary === false) {
            throw new RuntimeException(__('Création du fichier temporaire impossible', __FILE__));
        }
        $handle = fopen($temporary, 'wb');
        if (!is_resource($handle)) {
            @unlink($temporary);
            throw new RuntimeException(__('Ouverture du fichier temporaire impossible', __FILE__));
        }
        $written = fwrite($handle, $data);
        fflush($handle);
        fclose($handle);
        if ($written !== strlen($data)) {
            @unlink($temporary);
            throw new RuntimeException(__('Écriture du fichier incomplète', __FILE__));
        }
        @chmod($temporary, $mode);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException(__('Installation atomique du fichier impossible', __FILE__));
        }
        @chmod($path, $mode);
    }

    /**
     * Crée un répertoire privé s'il n'existe pas.
     *
     * @param string $path Chemin à créer.
     * @return void
     */
    private function ensureDirectory($path)
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException(__('Création du répertoire impossible : ', __FILE__) . $path);
        }
        @chmod($path, 0700);
    }

    /**
     * Formate le sujet d'un certificat pour le diagnostic.
     *
     * @param array<string,mixed> $parsedCertificate Certificat analysé.
     * @return string
     */
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

    /**
     * Aplatit récursivement une valeur OpenSSL en chaînes scalaires.
     *
     * @param mixed $value Valeur à aplatir.
     * @return string[]
     */
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

    /**
     * Vérifie qu'une chaîne est un UUID RFC 4122.
     *
     * @param string $value Valeur à vérifier.
     * @return bool
     */
    private static function isUuid($value)
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (string) $value
        ) === 1;
    }

    /**
     * Transmet un message au journaliseur fourni par Jeedom.
     *
     * @param string $level Niveau de journalisation.
     * @param string $message Message à écrire.
     * @return void
     */
    private function log($level, $message)
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $level, $message);
        }
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

    /**
     * Prépare un transport DTLS piloté par le binaire OpenSSL.
     *
     * @param string $openssl Chemin de l'exécutable OpenSSL.
     * @param string $host Adresse IPv4 distante.
     * @param int $port Port DTLS distant.
     * @param int $localPort Port UDP source.
     * @param string $certificatePath Certificat client.
     * @param string $certificateChainPath Chaîne client.
     * @param string $keyPath Clé privée cliente.
     * @param string $rootCaPath Autorité racine de confiance.
     * @param callable|null $logger Journaliseur facultatif.
     */
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

    /**
     * Lance OpenSSL et attend la fin du handshake DTLS.
     *
     * @param float $timeout Délai maximal en secondes.
     * @return void
     */
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
            throw new RuntimeException(__('Démarrage du client OpenSSL DTLS impossible', __FILE__));
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
                    __('[DTLS] Processus OpenSSL arrêté pendant le handshake', __FILE__)
                    . ($error !== '' ? ' : ' . $error : '')
                );
                throw new RuntimeException(__('Connexion DTLS refusée', __FILE__) . ($error !== '' ? ' : ' . $error : ''));
            }
            if (
                stripos($this->stderr, 'CONNECTION ESTABLISHED') !== false
                || stripos($this->stderr, 'Protocol version: DTLS') !== false
                || stripos($this->stderr, 'Ciphersuite:') !== false
            ) {
                $this->log(
                    'info',
                    sprintf(
                        __('[DTLS] Handshake réussi en %d ms', __FILE__),
                        (int) round((microtime(true) - $started) * 1000)
                    ) . $this->handshakeSummary()
                );
                return;
            }
            usleep(50000);
        }
        $error = $this->errorSummary();
        $this->close();
        $this->log(
            'warning',
            sprintf(
                __('[DTLS] Timeout du handshake après %d ms', __FILE__),
                (int) round((microtime(true) - $started) * 1000)
            ) . ($error !== '' ? ' : ' . $error : '')
        );
        throw new RuntimeException(__('Délai de négociation DTLS dépassé', __FILE__) . ($error !== '' ? ' : ' . $error : ''));
    }

    /**
     * Envoie une trame applicative dans la session DTLS.
     *
     * @param string $data Données binaires à envoyer.
     * @return void
     */
    public function write($data)
    {
        if (!is_resource($this->process) || $this->closed) {
            throw new RuntimeException(__('Session DTLS fermée', __FILE__));
        }
        $data = (string) $data;
        $offset = 0;
        $length = strlen($data);
        $deadline = microtime(true) + 3.0;
        while ($offset < $length) {
            $written = @fwrite($this->pipes[0], substr($data, $offset));
            if ($written === false) {
                throw new RuntimeException(__('Écriture DTLS impossible : ', __FILE__) . $this->errorSummary());
            }
            if ($written === 0) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException(__('Délai d’écriture DTLS dépassé', __FILE__));
                }
                usleep(10000);
                continue;
            }
            $offset += $written;
        }
        fflush($this->pipes[0]);
        $this->log(
            'debug',
            sprintf(__('[DTLS] %d octets applicatifs envoyés', __FILE__), $length)
        );
    }

    /**
     * Lit une trame CoAP complète depuis la sortie OpenSSL.
     *
     * @param float $timeout Délai maximal en secondes.
     * @return string|null Trame binaire, ou `null` en cas de délai dépassé.
     */
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
                throw new RuntimeException(__('Attente DTLS interrompue', __FILE__));
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
                    throw new RuntimeException(__('Lecture DTLS impossible', __FILE__));
                }
                if ($chunk !== '') {
                    $this->log(
                        'debug',
                        sprintf(__('[DTLS] %d octets applicatifs reçus', __FILE__), strlen($chunk))
                    );
                    $this->receiveBuffer .= $chunk;
                    $this->lastReceiveAt = microtime(true);
                }
            }
            if (!$this->isRunning()) {
                throw new RuntimeException(__('Session DTLS interrompue : ', __FILE__) . $this->errorSummary());
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
                sprintf(
                    __('[DTLS] Trame CoAP incomplète écartée : reçue=%d', __FILE__),
                    strlen($this->receiveBuffer)
                ) . (
                    is_int($expected) && $expected > 0
                    ? sprintf(__(', attendue=%d', __FILE__), $expected)
                    : ''
                )
            );
            $this->receiveBuffer = '';
        }
        return null;
    }

    /**
     * Retourne les dernières lignes OpenSSL utiles au diagnostic.
     *
     * @return string
     */
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

    /**
     * Ferme les tubes et termine le processus OpenSSL.
     *
     * @return void
     */
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

    /**
     * Garantit la fermeture du processus lors de la destruction de l'objet.
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Extrait une trame complète du tampon de réception.
     *
     * @param bool $allowVariableLength Autorise la consommation du tampon entier.
     * @return string|null
     */
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

    /**
     * Transfère les diagnostics OpenSSL dans le tampon borné.
     *
     * @return void
     */
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

    /**
     * Indique si le processus OpenSSL est toujours actif.
     *
     * @return bool
     */
    private function isRunning()
    {
        if (!is_resource($this->process)) {
            return false;
        }
        $status = proc_get_status($this->process);
        return !empty($status['running']);
    }

    /**
     * Valide l'exécutable, l'adresse, les ports et les fichiers DTLS.
     *
     * @return void
     */
    private function validateConfiguration()
    {
        if (!is_executable($this->openssl)) {
            throw new InvalidArgumentException(__('Exécutable OpenSSL introuvable', __FILE__));
        }
        if (filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException(__('Adresse IPv4 DTLS invalide', __FILE__));
        }
        if ($this->port < 1 || $this->port > 65535 || $this->localPort < 1024 || $this->localPort > 65535) {
            throw new InvalidArgumentException(__('Port DTLS invalide', __FILE__));
        }
        foreach (array($this->certificatePath, $this->certificateChainPath, $this->keyPath, $this->rootCaPath) as $path) {
            if (!is_file($path) || !is_readable($path)) {
                throw new InvalidArgumentException(__('Fichier DTLS illisible : ', __FILE__) . $path);
            }
        }
        if (!function_exists('proc_open')) {
            throw new RuntimeException(__('La fonction PHP proc_open est désactivée', __FILE__));
        }
    }

    /**
     * Résume la version DTLS et la suite cryptographique négociées.
     *
     * @return string
     */
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
}
