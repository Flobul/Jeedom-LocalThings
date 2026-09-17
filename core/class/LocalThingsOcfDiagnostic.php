<?php

/** Lecture seule des ressources OCF publiques, sans identifiant ni clé dans les logs. */
class LocalThingsOcfDiagnostic
{
    private const PATHS = array('/oic/res', '/oic/sec/doxm', '/oic/sec/pstat', '/oic/p');
    private $host;
    private $port;
    private $directoryPorts = array();
    private $received = 0;
    private $matched = 0;
    private $stage = 'transport';

    public function __construct($host, $port = 5683)
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Adresse OCF invalide');
        }
        $this->host = $host;
        $this->port = (int) $port;
    }

    /** Trois lectures bornées pour préciser un échec de découverte ou d'authentification. */
    public function inspect(callable $logger)
    {
        $fields = array();
        foreach (array('/oic/sec/doxm', '/oic/sec/pstat', '/oic/p') as $path) {
            LocalThingsDiscovery::checkpoint(true);
            try {
                $result = $this->read($path);
                $fields[$path] = $result['fields'];
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
                $detail = 'non disponible ; étape=' . $this->stage;
            }
            $logger('info', '[OCF public] GET ' . $path . ' : ' . $detail
                . ' ; datagrammes reçus=' . $this->received . ', corrélés=' . $this->matched);
        }
        return $fields;
    }

    /** Découvre les ports annoncés sans suivre une adresse fournie par un autre hôte. */
    public function discoverPorts(callable $logger)
    {
        $deadline = microtime(true) + 3.0;
        $ports = array();
        foreach (array(array(), array('rt=oic.r.doxm')) as $query) {
            if (microtime(true) >= $deadline) { break; }
            try {
                $result = $this->read('/oic/res', $query, $deadline - microtime(true));
                $logger('info', '[OCF public] GET /oic/res' . ($query ? '?rt=oic.r.doxm' : '')
                    . ' : CoAP ' . LocalThingsCoap::formatCode($result['code'])
                    . ' ' . json_encode($result['fields']));
                $ports = $this->directoryPorts;
                if ($ports || ($result['code'] >> 5) !== 2) { break; }
            } catch (LocalThingsDiscoveryCancelled $error) {
                throw $error;
            } catch (Throwable $error) {
                $logger('info', '[OCF public] GET /oic/res : non disponible ; étape=' . $this->stage
                    . ' ; datagrammes reçus=' . $this->received . ', corrélés=' . $this->matched);
                break;
            }
        }
        return $ports;
    }

    /** GET uniquement, réponse corrélée, Block2 borné et délai total plafonné à trois secondes. */
    public function read($path, $query = array(), $timeout = 2.0)
    {
        if (!in_array($path, self::PATHS, true)) {
            throw new InvalidArgumentException('Ressource de diagnostic non autorisée');
        }
        if ($query && ($path !== '/oic/res' || $query !== array('rt=oic.r.doxm'))) {
            throw new InvalidArgumentException('Filtre OCF non autorisé');
        }
        $this->received = $this->matched = 0;
        $this->stage = 'transport';
        $this->directoryPorts = array();
        $socket = @stream_socket_server('udp://0.0.0.0:0', $errno, $error, STREAM_SERVER_BIND);
        $target = $this->host . ':' . $this->port;
        $pinned = null;
        if ($socket === false) {
            throw new RuntimeException('Service OCF inaccessible');
        }
        stream_set_blocking($socket, false);
        $deadline = microtime(true) + max(0.01, min(3.0, $timeout));
        $token = random_bytes(4);
        $payload = '';
        $szx = 6;
        $etag = null;
        try {
            for ($block = 0; $block < 64; $block++) {
                $options = array();
                foreach (explode('/', trim($path, '/')) as $segment) {
                    $options[] = array(LocalThingsCoap::OPTION_URI_PATH, $segment);
                }
                foreach ($query as $filter) { $options[] = array(LocalThingsCoap::OPTION_URI_QUERY, $filter); }
                $options[] = array(LocalThingsCoap::OPTION_BLOCK2, LocalThingsCoap::blockValue($block, false, $szx));
                $mid = random_int(0, 65535);
                $request = LocalThingsCoap::build(LocalThingsCoap::TYPE_CON, LocalThingsCoap::METHOD_GET, $mid, $token, $options);
                $packet = null;
                $nextSend = 0;
                $acknowledged = false;
                while (microtime(true) < $deadline) {
                    LocalThingsDiscovery::checkpoint();
                    if (!$acknowledged && microtime(true) >= $nextSend) {
                        if (@stream_socket_sendto($socket, $request, 0, $target) !== strlen($request)) {
                            throw new RuntimeException('Envoi CoAP impossible');
                        }
                        $nextSend = microtime(true) + 1.0;
                    }
                    $read = array($socket);
                    $write = $except = null;
                    if (@stream_select($read, $write, $except, 0, 100000) <= 0) {
                        continue;
                    }
                    $peer = '';
                    $bytes = @stream_socket_recvfrom($socket, 16385, 0, $peer);
                    $this->received++;
                    if (substr($peer, 0, strrpos($peer, ':')) !== $this->host || ($pinned !== null && $peer !== $pinned)) { continue; }
                    $this->stage = 'corrélation CoAP';
                    if ($bytes === false || strlen($bytes) > 16384) {
                        throw new RuntimeException('Réception CoAP impossible');
                    }
                    try {
                        $response = LocalThingsCoap::parse($bytes);
                    } catch (Throwable $error) {
                        continue;
                    }
                    if ($peer === $target && $response['message_id'] === $mid && $response['code'] === 0) {
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
                        @stream_socket_sendto($socket, LocalThingsCoap::build(LocalThingsCoap::TYPE_ACK, 0, $response['message_id'], ''), 0, $peer);
                    }
                    $blocks = LocalThingsCoap::optionValues($response, LocalThingsCoap::OPTION_BLOCK2);
                    $blockValue = $blocks ? LocalThingsCoap::uintOption($blocks[0]) : null;
                    if ($blockValue !== null && ($blockValue >> 4) !== $block) {
                        continue;
                    }
                    $this->matched++;
                    $this->stage = 'assemblage Block2';
                    $pinned = $target = $peer;
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
                if (strlen($payload) > 65536) {
                    throw new RuntimeException('Réponse OCF trop volumineuse');
                }
                if ($blockValue === null || ($blockValue & 8) === 0) {
                    $formats = LocalThingsCoap::optionValues($packet, LocalThingsCoap::OPTION_CONTENT_FORMAT);
                    $format = $formats ? LocalThingsCoap::uintOption($formats[0]) : 60;
                    if (!in_array($format, array(50, 60, 10000), true)) {
                        throw new RuntimeException('Format OCF non pris en charge');
                    }
                    $this->stage = 'décodage';
                    $consumed = 0;
                    $decoded = $format === 50 ? json_decode($payload, true, 32) : LocalThingsCbor::decode($payload, $consumed);
                    if (!is_array($decoded) || ($format !== 50 && $consumed !== strlen($payload))) {
                        throw new RuntimeException('Contenu OCF invalide');
                    }
                    if ($path === '/oic/res') {
                        $summary = $this->directory($decoded);
                    } else {
                        $summary = self::safeFields($path, $decoded);
                    }
                    return array('code' => $packet['code'], 'fields' => $summary);
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

    /** Résumé borné du répertoire ; les URI et UUID ne quittent pas cette méthode. */
    private function directory($data)
    {
        $containers = array_keys($data) === range(0, count($data) - 1) ? $data : array($data);
        $links = array();
        foreach ($containers as $container) {
            if (!is_array($container)) { continue; }
            $candidates = isset($container['links']) && is_array($container['links'])
                ? $container['links'] : (isset($container['href']) ? array($container) : array());
            foreach ($candidates as $link) {
                if (count($links) >= 256) { throw new RuntimeException('Répertoire OCF trop volumineux'); }
                if (is_array($link)) { $links[] = $link; }
            }
        }
        $ports = array();
        $ipv6 = 0;
        foreach ($links as $link) {
            foreach (array_slice(is_array($link['eps'] ?? null) ? $link['eps'] : array(), 0, 32) as $endpoint) {
                $uri = is_array($endpoint) ? ($endpoint['ep'] ?? '') : '';
                if (!is_string($uri) || strlen($uri) > 512) { continue; }
                $parsed = parse_url($uri);
                if (!$parsed || ($parsed['scheme'] ?? '') !== 'coaps' || isset($parsed['user'])
                    || isset($parsed['query']) || isset($parsed['fragment']) || !in_array($parsed['path'] ?? '', array('', '/'), true)) { continue; }
                $host = $parsed['host'] ?? '';
                if (strpos($host, '[') === 0) { $ipv6++; continue; } // Pas de conversion d'une adresse IPv6 ou de son scope en IPv4.
                if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                    || inet_pton($host) !== inet_pton($this->host)) { continue; }
                $port = $parsed['port'] ?? 5684;
                if (is_int($port) && $port > 0 && $port <= 65535) { $ports[] = $port; }
            }
            $legacy = $link['p'] ?? array();
            if (is_array($legacy) && ($legacy['sec'] ?? false) === true) {
                $port = $link['port'] ?? ($legacy['port'] ?? null);
                if (is_int($port) && $port > 0 && $port <= 65535) { $ports[] = $port; }
            }
        }
        $this->directoryPorts = array_slice(array_values(array_unique($ports)), 0, 8);
        return array('ressources' => count($links), 'ports_dtls' => $this->directoryPorts,
            'endpoints_ipv6_non_pris_en_charge' => $ipv6);
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
