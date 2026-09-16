<?php

/** Lecture seule des ressources OCF publiques, sans identifiant ni clé dans les logs. */
class LocalThingsOcfDiagnostic
{
    private const PATHS = array('/oic/sec/doxm', '/oic/sec/pstat', '/oic/p');
    private $host;
    private $port;

    public function __construct($host, $port = 5683)
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Adresse OCF invalide');
        }
        $this->host = $host;
        $this->port = (int) $port;
    }

    /** Trois lectures bornées, exécutées uniquement après un refus du certificat client. */
    public function inspect(callable $logger)
    {
        foreach (self::PATHS as $path) {
            LocalThingsDiscovery::checkpoint(true);
            try {
                $result = $this->read($path);
                $detail = 'CoAP ' . LocalThingsCoap::formatCode($result['code']);
                if ($result['fields']) {
                    $detail .= ' ' . json_encode($result['fields'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                } elseif (($result['code'] >> 5) === 2) {
                    $detail .= ' ; aucun champ de diagnostic reconnu';
                }
            } catch (LocalThingsDiscoveryCancelled $error) {
                throw $error;
            } catch (Throwable $error) {
                // Ne jamais reprendre une réponse distante brute ou une erreur de décodage.
                $detail = 'non disponible (absence de réponse ou réponse non exploitable)';
            }
            $logger('info', '[OCF public] ' . $this->host . ':' . $this->port . ' GET ' . $path . ' : ' . $detail);
        }
    }

    /** GET uniquement, réponse corrélée, Block2 borné et délai total de deux secondes. */
    public function read($path)
    {
        if (!in_array($path, self::PATHS, true)) {
            throw new InvalidArgumentException('Ressource de diagnostic non autorisée');
        }
        $socket = @stream_socket_client('udp://' . $this->host . ':' . $this->port, $errno, $error, 0.2);
        if ($socket === false) {
            throw new RuntimeException('Service OCF inaccessible');
        }
        stream_set_blocking($socket, false);
        $deadline = microtime(true) + 2.0;
        $token = random_bytes(4);
        $payload = '';
        $szx = 6;
        $etag = null;
        try {
            for ($block = 0; $block < 16; $block++) {
                $options = array();
                foreach (explode('/', trim($path, '/')) as $segment) {
                    $options[] = array(LocalThingsCoap::OPTION_URI_PATH, $segment);
                }
                $options[] = array(LocalThingsCoap::OPTION_BLOCK2, LocalThingsCoap::blockValue($block, false, $szx));
                $mid = random_int(0, 65535);
                $request = LocalThingsCoap::build(LocalThingsCoap::TYPE_CON, LocalThingsCoap::METHOD_GET, $mid, $token, $options);
                $packet = null;
                $nextSend = 0;
                $acknowledged = false;
                while (microtime(true) < $deadline) {
                    LocalThingsDiscovery::checkpoint();
                    if (!$acknowledged && microtime(true) >= $nextSend) {
                        if (@fwrite($socket, $request) !== strlen($request)) {
                            throw new RuntimeException('Envoi CoAP impossible');
                        }
                        $nextSend = microtime(true) + 1.0;
                    }
                    $read = array($socket);
                    $write = $except = null;
                    if (@stream_select($read, $write, $except, 0, 100000) <= 0) {
                        continue;
                    }
                    $bytes = @fread($socket, 16385);
                    if ($bytes === false || strlen($bytes) > 16384) {
                        throw new RuntimeException('Réception CoAP impossible');
                    }
                    try {
                        $response = LocalThingsCoap::parse($bytes);
                    } catch (Throwable $error) {
                        continue;
                    }
                    if ($response['message_id'] === $mid && $response['code'] === 0) {
                        if ($response['type'] === LocalThingsCoap::TYPE_RST) {
                            throw new RuntimeException('Requête CoAP refusée');
                        }
                        if ($response['type'] === LocalThingsCoap::TYPE_ACK) {
                            $acknowledged = true;
                        }
                        continue;
                    }
                    if (!hash_equals($token, $response['token']) || $response['type'] === LocalThingsCoap::TYPE_RST
                        || ($response['type'] === LocalThingsCoap::TYPE_ACK && $response['message_id'] !== $mid)) {
                        continue;
                    }
                    if ($response['type'] === LocalThingsCoap::TYPE_CON) {
                        @fwrite($socket, LocalThingsCoap::build(LocalThingsCoap::TYPE_ACK, 0, $response['message_id'], ''));
                    }
                    $blocks = LocalThingsCoap::optionValues($response, LocalThingsCoap::OPTION_BLOCK2);
                    $blockValue = $blocks ? LocalThingsCoap::uintOption($blocks[0]) : null;
                    if ($blockValue !== null && ($blockValue >> 4) !== $block) {
                        continue;
                    }
                    $packet = $response;
                    break;
                }
                if ($packet === null) {
                    throw new RuntimeException('Délai CoAP dépassé');
                }
                if (($packet['code'] >> 5) !== 2) {
                    return array('code' => $packet['code'], 'fields' => array());
                }
                $tags = LocalThingsCoap::optionValues($packet, 4);
                $currentTag = $tags ? $tags[0] : null;
                if ($block > 0 && $currentTag !== $etag) {
                    throw new RuntimeException('Représentation OCF modifiée entre deux blocs');
                }
                $etag = $currentTag;
                if ($blockValue !== null) {
                    $nextSzx = $blockValue & 7;
                    if ($nextSzx > 6 || ($block > 0 && $nextSzx !== $szx)) {
                        throw new RuntimeException('Taille de bloc OCF inattendue');
                    }
                    $szx = $nextSzx;
                } elseif ($block > 0) {
                    throw new RuntimeException('Réponse OCF fragmentée incomplète');
                }
                $payload .= $packet['payload'];
                if (strlen($payload) > 16384) {
                    throw new RuntimeException('Réponse OCF trop volumineuse');
                }
                if ($blockValue === null || ($blockValue & 8) === 0) {
                    $formats = LocalThingsCoap::optionValues($packet, LocalThingsCoap::OPTION_CONTENT_FORMAT);
                    $format = $formats ? LocalThingsCoap::uintOption($formats[0]) : 60;
                    if (!in_array($format, array(50, 60, 10000), true)) {
                        throw new RuntimeException('Format OCF non pris en charge');
                    }
                    $decoded = $format === 50 ? json_decode($payload, true, 32) : LocalThingsCbor::decode($payload);
                    return array('code' => $packet['code'], 'fields' => self::safeFields($path, $decoded));
                }
                if (strlen($packet['payload']) !== (1 << ($szx + 4))) {
                    throw new RuntimeException('Bloc OCF incomplet');
                }
            }
            throw new RuntimeException('Trop de blocs OCF');
        } finally {
            fclose($socket);
        }
    }

    /** Liste fermée de champs : aucun UUID, propriétaire, nonce, numéro de série ou credential. */
    public static function safeFields($path, $data)
    {
        if (!is_array($data)) {
            return array();
        }
        $fields = array();
        $allowed = $path === '/oic/sec/doxm' ? array('oxms', 'oxmsel', 'sct', 'owned')
            : ($path === '/oic/sec/pstat' ? array('isop', 'cm', 'tm', 'om', 'sm') : array());
        foreach ($allowed as $key) {
            $value = $data[$key] ?? null;
            if (is_bool($value) || (is_int($value) && $value >= 0 && $value <= 65535)) {
                $fields[$key] = $value;
            } elseif (is_array($value) && count($value) <= 16
                && count(array_filter($value, function ($item) { return is_int($item) && $item >= 0 && $item <= 65535; })) === count($value)) {
                $fields[$key] = array_values($value);
            }
        }
        if ($path === '/oic/sec/pstat' && isset($data['dos']['s']) && is_int($data['dos']['s'])
            && $data['dos']['s'] >= 0 && $data['dos']['s'] <= 255) {
            $fields['dos_state'] = $data['dos']['s'];
        }
        if ($path === '/oic/p') {
            foreach (array('mnmo', 'mnfv', 'mnhw') as $key) {
                if (isset($data[$key]) && is_string($data[$key]) && preg_match('/^[a-zA-Z0-9 ._+()\/-]{1,80}$/D', $data[$key])) {
                    $fields[$key] = $data[$key];
                }
            }
        }
        return $fields;
    }
}
