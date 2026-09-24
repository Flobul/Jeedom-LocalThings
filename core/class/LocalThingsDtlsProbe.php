<?php

/** Sonde DTLS sans état : seul le ClientHello initial quitte Jeedom. */
class LocalThingsDtlsProbe
{
    /** Génère un premier vol avec le même OpenSSL et le même chiffrement que les sessions. */
    public static function clientHello($openssl)
    {
        $socket = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        if ($socket === false) {
            throw new RuntimeException('Préparation de la sonde DTLS impossible');
        }
        $process = null;
        $pipes = array();
        try {
            $process = proc_open(array($openssl, 's_client', '-dtls1_2', '-connect', stream_socket_get_name($socket, false),
                '-cipher', 'ECDHE-ECDSA-AES128-GCM-SHA256:@SECLEVEL=0', '-mtu', '1200', '-quiet', '-ign_eof'),
                array(0 => array('pipe', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
                $pipes, null, null, array('bypass_shell' => true));
            if (!is_resource($process)) {
                throw new RuntimeException('Démarrage de la sonde DTLS impossible');
            }
            stream_set_blocking($socket, false);
            $deadline = microtime(true) + 2;
            while (microtime(true) < $deadline) {
                LocalThingsDiscovery::checkpoint();
                $read = array($socket);
                $write = $except = null;
                if (stream_select($read, $write, $except, 0, 100000) > 0) {
                    $packet = stream_socket_recvfrom($socket, 8192);
                    // En-têtes DTLS et handshake, session-id puis cookie de longueur zéro.
                    if (strlen($packet) >= 61 && ord($packet[0]) === 22 && ord($packet[13]) === 1
                        && substr($packet, 3, 2) === "\0\0" && substr($packet, 19, 3) === "\0\0\0"
                        && strlen($packet) === 13 + unpack('n', substr($packet, 11, 2))[1]) {
                        $cookieOffset = 60 + ord($packet[59]);
                        if (isset($packet[$cookieOffset]) && ord($packet[$cookieOffset]) === 0) {
                            return $packet;
                        }
                    }
                    throw new RuntimeException('Premier ClientHello DTLS inattendu');
                }
            }
            throw new RuntimeException('ClientHello DTLS non généré');
        } finally {
            fclose($socket);
            foreach ($pipes as $pipe) { fclose($pipe); }
            if (is_resource($process)) {
                proc_terminate($process);
                $until = microtime(true) + 0.3;
                while (proc_get_status($process)['running'] && microtime(true) < $until) { usleep(10000); }
                if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
                proc_close($process);
            }
        }
    }

    /** Sonde les candidats en parallèle, retransmet à l'identique, n'émet jamais de cookie. */
    public static function scan($host, $ports, $openssl, $timeout = 2.0)
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('La sonde LocalThings attend une adresse IPv4');
        }
        $hello = self::clientHello($openssl);
        $sockets = $results = array();
        try {
            foreach (array_slice(array_unique($ports), 0, 20) as $port) {
                $port = (int) $port;
                if ($port < 1 || $port > 65535) { continue; }
                $socket = @stream_socket_server('udp://0.0.0.0:0', $errno, $error, STREAM_SERVER_BIND);
                $results[$port] = array('kind' => 'no_response', 'attempts' => 0);
                if ($socket === false) { $results[$port]['kind'] = 'socket_error'; continue; }
                stream_set_blocking($socket, false);
                $sockets[$port] = $socket;
            }
            $deadline = microtime(true) + max(0.1, min(5.0, $timeout));
            $nextSend = 0;
            while ($sockets && microtime(true) < $deadline) {
                LocalThingsDiscovery::checkpoint();
                if (microtime(true) >= $nextSend) {
                    foreach ($sockets as $port => $socket) {
                        if (@stream_socket_sendto($socket, $hello, 0, $host . ':' . $port) !== strlen($hello)) {
                            $results[$port]['kind'] = 'send_error';
                            fclose($socket); unset($sockets[$port]);
                        } else { $results[$port]['attempts']++; }
                    }
                    $nextSend = microtime(true) + 1.0;
                }
                if (!$sockets) { break; }
                $read = array_values($sockets);
                $write = $except = null;
                if (@stream_select($read, $write, $except, 0, 100000) <= 0) { continue; }
                foreach ($read as $socket) {
                    $port = array_search($socket, $sockets, true);
                    $peer = '';
                    $packet = @stream_socket_recvfrom($socket, 8192, 0, $peer);
                    $separator = strrpos($peer, ':');
                    if ($packet === false || $separator === false
                        || substr($peer, 0, $separator) !== $host) { continue; }
                    $responderPort = filter_var(substr($peer, $separator + 1), FILTER_VALIDATE_INT,
                        array('options' => array('min_range' => 1, 'max_range' => 65535)));
                    if ($responderPort === false) { continue; }
                    $reply = self::firstReply($packet);
                    if ($reply === null) { continue; }
                    // Le socket OCF sécurisé de l'appareil peut répondre depuis
                    // son port éphémère même lorsque 5684 a été ciblé. Le port
                    // source, et non le port composé, est l'endpoint à joindre.
                    $reply['responder_port'] = (int) $responderPort;
                    $results[$port] = array_merge($results[$port], $reply);
                    // Ne jamais transmettre cette réponse au processus OpenSSL.
                    fclose($socket); unset($sockets[$port]);
                }
            }
        } finally {
            foreach ($sockets as $socket) { fclose($socket); }
        }
        return $results;
    }

    /** Valide un premier vol complet d'époque zéro sans exposer son contenu. */
    public static function firstReply($packet)
    {
        $offset = 0;
        $result = null;
        while ($offset < strlen($packet)) {
            if (strlen($packet) - $offset < 13) { return null; }
            $size = unpack('n', substr($packet, $offset + 11, 2))[1];
            if ($offset + 13 + $size > strlen($packet)) { return null; }
            $record = substr($packet, $offset, 13 + $size);
            $offset += 13 + $size;
            if (!in_array(substr($record, 1, 2), array("\xfe\xfd", "\xfe\xff"), true)
                || substr($record, 3, 2) !== "\0\0") { continue; }
            if (ord($record[0]) === 21 && $size === 2 && in_array(ord($record[13]), array(1, 2), true)) {
                $result = array('kind' => 'alert', 'alert' => ord($record[14]));
            } elseif (ord($record[0]) === 22) {
                for ($at = 13; $at < strlen($record);) {
                    if ($at + 12 > strlen($record)) { return null; }
                    $length = unpack('N', "\0" . substr($record, $at + 1, 3))[1];
                    $fragment = unpack('N', "\0" . substr($record, $at + 9, 3))[1];
                    if ($at + 12 + $fragment > strlen($record)) { return null; }
                    $body = substr($record, $at + 12, $fragment);
                    if (substr($record, $at + 6, 3) === "\0\0\0" && $length === $fragment
                        && in_array(substr($body, 0, 2), array("\xfe\xfd", "\xfe\xff"), true)) {
                        if (ord($record[$at]) === 3 && strlen($body) >= 3 && strlen($body) === 3 + ord($body[2])) {
                            if (($result['kind'] ?? '') !== 'alert') {
                                $result = array('kind' => 'hello_verify_request');
                            }
                        } elseif (ord($record[$at]) === 2 && strlen($body) >= 38 && ord($body[34]) <= 32) {
                            $end = 38 + ord($body[34]);
                            if (strlen($body) === $end || (strlen($body) >= $end + 2
                                && strlen($body) === $end + 2 + unpack('n', substr($body, $end, 2))[1])) {
                                if (($result['kind'] ?? '') !== 'alert') {
                                    $result = array('kind' => 'server_hello');
                                }
                            }
                        }
                    }
                    $at += 12 + $fragment;
                }
            }
        }
        return $result;
    }

    /** Endpoints DTLS réels, dédupliqués par port source dans l'ordre du scan. */
    public static function livePorts($results)
    {
        $ports = array();
        foreach ($results as $result) {
            if (!in_array($result['kind'] ?? '', array('hello_verify_request', 'server_hello'), true)
                || !isset($result['responder_port'])) {
                continue;
            }
            $port = (int) $result['responder_port'];
            if ($port >= 1 && $port <= 65535) { $ports[$port] = $port; }
        }
        return array_values($ports);
    }

    /** Choisit uniquement un port source prouvé et non ambigu, ou la préférence explicite. */
    public static function select($results, $preferred = null)
    {
        $live = self::livePorts($results);
        if ($preferred !== null && in_array((int) $preferred, $live, true)) { return (int) $preferred; }
        if (count($live) === 1) { return $live[0]; }
        if (count($live) > 1) { throw new RuntimeException('Plusieurs ports DTLS répondent : sélection ambiguë'); }
        throw new RuntimeException('Aucun service DTLS acceptant un ClientHello initial détecté');
    }
}
