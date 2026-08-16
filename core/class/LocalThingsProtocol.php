<?php

/**
 * Minimal CBOR codec used by Samsung LocalThings resources.
 *
 * It supports all definite-length values, indefinite strings/arrays/maps,
 * tags and IEEE-754 floats. Byte strings are decoded as PHP strings; command
 * payload strings are encoded as UTF-8 text strings.
 */
class LocalThingsCbor
{
    public static function encode($value)
    {
        if ($value === null) {
            return "\xF6";
        }
        if ($value === false) {
            return "\xF4";
        }
        if ($value === true) {
            return "\xF5";
        }
        if (is_int($value)) {
            return $value >= 0
                ? self::encodeHead(0, $value)
                : self::encodeHead(1, -1 - $value);
        }
        if (is_float($value)) {
            return "\xFB" . pack('E', $value);
        }
        if (is_string($value)) {
            return self::encodeHead(3, strlen($value)) . $value;
        }
        if (is_array($value)) {
            if (self::isList($value)) {
                $encoded = self::encodeHead(4, count($value));
                foreach ($value as $item) {
                    $encoded .= self::encode($item);
                }
                return $encoded;
            }
            $encoded = self::encodeHead(5, count($value));
            foreach ($value as $key => $item) {
                $encoded .= self::encode($key);
                $encoded .= self::encode($item);
            }
            return $encoded;
        }
        if (is_object($value)) {
            return self::encode(get_object_vars($value));
        }
        throw new InvalidArgumentException(__('Type CBOR non pris en charge', __FILE__));
    }

    public static function decode($data, &$consumed = null)
    {
        if (!is_string($data) || $data === '') {
            throw new InvalidArgumentException(__('Données CBOR vides', __FILE__));
        }
        $offset = 0;
        $value = self::decodeValue($data, $offset, false);
        $consumed = $offset;
        return $value;
    }

    private static function decodeValue($data, &$offset, $allowBreak)
    {
        self::requireBytes($data, $offset, 1);
        $initial = ord($data[$offset++]);
        if ($initial === 0xFF) {
            if ($allowBreak) {
                return self::breakMarker();
            }
            throw new UnexpectedValueException(__('Marqueur CBOR break inattendu', __FILE__));
        }

        $major = $initial >> 5;
        $additional = $initial & 0x1F;
        if ($additional === 31 && !in_array($major, array(2, 3, 4, 5), true)) {
            throw new UnexpectedValueException(__('Longueur CBOR indéfinie invalide', __FILE__));
        }

        switch ($major) {
            case 0:
                return self::readLength($data, $offset, $additional);
            case 1:
                return -1 - self::readLength($data, $offset, $additional);
            case 2:
                return self::decodeString($data, $offset, $additional, 2);
            case 3:
                return self::decodeString($data, $offset, $additional, 3);
            case 4:
                return self::decodeArray($data, $offset, $additional);
            case 5:
                return self::decodeMap($data, $offset, $additional);
            case 6:
                self::readLength($data, $offset, $additional);
                return self::decodeValue($data, $offset, false);
            case 7:
                return self::decodeSimple($data, $offset, $additional);
        }
        throw new UnexpectedValueException(__('Type CBOR inconnu', __FILE__));
    }

    private static function decodeString($data, &$offset, $additional, $expectedMajor)
    {
        if ($additional !== 31) {
            $length = self::readLength($data, $offset, $additional);
            self::requireBytes($data, $offset, $length);
            $value = substr($data, $offset, $length);
            $offset += $length;
            return $value;
        }

        $value = '';
        while (true) {
            self::requireBytes($data, $offset, 1);
            if (ord($data[$offset]) === 0xFF) {
                $offset++;
                return $value;
            }
            $initial = ord($data[$offset++]);
            $major = $initial >> 5;
            $chunkAdditional = $initial & 0x1F;
            if ($major !== $expectedMajor || $chunkAdditional === 31) {
                throw new UnexpectedValueException(__('Fragment CBOR de type incorrect', __FILE__));
            }
            $length = self::readLength($data, $offset, $chunkAdditional);
            self::requireBytes($data, $offset, $length);
            $value .= substr($data, $offset, $length);
            $offset += $length;
        }
    }

    private static function decodeArray($data, &$offset, $additional)
    {
        $result = array();
        if ($additional !== 31) {
            $length = self::readLength($data, $offset, $additional);
            for ($index = 0; $index < $length; $index++) {
                $result[] = self::decodeValue($data, $offset, false);
            }
            return $result;
        }
        while (true) {
            $item = self::decodeValue($data, $offset, true);
            if ($item instanceof LocalThingsCborBreakMarker) {
                return $result;
            }
            $result[] = $item;
        }
    }

    private static function decodeMap($data, &$offset, $additional)
    {
        $result = array();
        $remaining = $additional === 31
            ? null
            : self::readLength($data, $offset, $additional);
        while ($remaining === null || $remaining > 0) {
            $key = self::decodeValue($data, $offset, $remaining === null);
            if ($key instanceof LocalThingsCborBreakMarker && $remaining === null) {
                return $result;
            }
            $value = self::decodeValue($data, $offset, false);
            if (is_int($key) || is_string($key)) {
                $result[$key] = $value;
            } else {
                $result[json_encode($key)] = $value;
            }
            if ($remaining !== null) {
                $remaining--;
            }
        }
        return $result;
    }

    private static function decodeSimple($data, &$offset, $additional)
    {
        switch ($additional) {
            case 20:
                return false;
            case 21:
                return true;
            case 22:
            case 23:
                return null;
            case 24:
                self::requireBytes($data, $offset, 1);
                return ord($data[$offset++]);
            case 25:
                self::requireBytes($data, $offset, 2);
                $raw = unpack('n', substr($data, $offset, 2))[1];
                $offset += 2;
                return self::decodeHalfFloat($raw);
            case 26:
                self::requireBytes($data, $offset, 4);
                $value = unpack('G', substr($data, $offset, 4))[1];
                $offset += 4;
                return $value;
            case 27:
                self::requireBytes($data, $offset, 8);
                $value = unpack('E', substr($data, $offset, 8))[1];
                $offset += 8;
                return $value;
            case 31:
                throw new UnexpectedValueException(__('Marqueur CBOR break inattendu', __FILE__));
            default:
                return $additional;
        }
    }

    private static function decodeHalfFloat($raw)
    {
        $sign = ($raw & 0x8000) ? -1 : 1;
        $exponent = ($raw >> 10) & 0x1F;
        $fraction = $raw & 0x03FF;
        if ($exponent === 0) {
            return $sign * pow(2, -14) * ($fraction / 1024);
        }
        if ($exponent === 31) {
            return $fraction === 0 ? $sign * INF : NAN;
        }
        return $sign * pow(2, $exponent - 15) * (1 + ($fraction / 1024));
    }

    private static function readLength($data, &$offset, $additional)
    {
        if ($additional < 24) {
            return $additional;
        }
        if ($additional === 24) {
            self::requireBytes($data, $offset, 1);
            return ord($data[$offset++]);
        }
        if ($additional === 25) {
            self::requireBytes($data, $offset, 2);
            $value = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            return $value;
        }
        if ($additional === 26) {
            self::requireBytes($data, $offset, 4);
            $value = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
            return $value;
        }
        if ($additional === 27) {
            self::requireBytes($data, $offset, 8);
            $parts = unpack('Nhigh/Nlow', substr($data, $offset, 8));
            $offset += 8;
            if (PHP_INT_SIZE < 8 || $parts['high'] > 0x7FFFFFFF) {
                throw new OverflowException(__('Entier CBOR hors capacité PHP', __FILE__));
            }
            return ($parts['high'] << 32) | $parts['low'];
        }
        if ($additional === 31) {
            return null;
        }
        throw new UnexpectedValueException(__('Longueur CBOR réservée', __FILE__));
    }

    private static function encodeHead($major, $value)
    {
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException(__('Longueur CBOR invalide', __FILE__));
        }
        $prefix = $major << 5;
        if ($value < 24) {
            return chr($prefix | $value);
        }
        if ($value <= 0xFF) {
            return chr($prefix | 24) . chr($value);
        }
        if ($value <= 0xFFFF) {
            return chr($prefix | 25) . pack('n', $value);
        }
        if ($value <= 0xFFFFFFFF) {
            return chr($prefix | 26) . pack('N', $value);
        }
        return chr($prefix | 27)
            . pack('N2', ($value >> 32) & 0xFFFFFFFF, $value & 0xFFFFFFFF);
    }

    private static function isList($value)
    {
        $index = 0;
        foreach ($value as $key => $unused) {
            if ($key !== $index++) {
                return false;
            }
        }
        return true;
    }

    private static function requireBytes($data, $offset, $length)
    {
        if ($length < 0 || $offset < 0 || strlen($data) - $offset < $length) {
            throw new UnderflowException(__('Données CBOR tronquées', __FILE__));
        }
    }

    private static function breakMarker()
    {
        static $marker = null;
        if ($marker === null) {
            $marker = new LocalThingsCborBreakMarker();
        }
        return $marker;
    }
}

final class LocalThingsCborBreakMarker
{
}

class LocalThingsCoap
{
    public const TYPE_CON = 0;
    public const TYPE_NON = 1;
    public const TYPE_ACK = 2;
    public const TYPE_RST = 3;

    public const METHOD_GET = 0x01;
    public const METHOD_POST = 0x02;

    public const OPTION_OBSERVE = 6;
    public const OPTION_URI_PATH = 11;
    public const OPTION_CONTENT_FORMAT = 12;
    public const OPTION_URI_QUERY = 15;
    public const OPTION_ACCEPT = 17;
    public const OPTION_BLOCK2 = 23;
    public const OPTION_SIZE2 = 28;

    public const CONTENT_FORMAT_CBOR = 60;
    public const BLOCK_SZX = 6;

    public static function build($type, $code, $messageId, $token, $options = array(), $payload = '')
    {
        $tokenLength = strlen($token);
        if ($tokenLength > 8) {
            throw new InvalidArgumentException(__('Jeton CoAP trop long', __FILE__));
        }
        if ($type < 0 || $type > 3 || $code < 0 || $code > 255) {
            throw new InvalidArgumentException(__('En-tête CoAP invalide', __FILE__));
        }
        $header = chr(0x40 | (($type & 0x03) << 4) | $tokenLength)
            . chr($code)
            . pack('n', $messageId & 0xFFFF);
        $packet = $header . $token . self::encodeOptions($options);
        if ($payload !== '') {
            $packet .= "\xFF" . $payload;
        }
        return $packet;
    }

    public static function parse($data)
    {
        if (!is_string($data) || strlen($data) < 4) {
            throw new UnderflowException(__('Trame CoAP tronquée', __FILE__));
        }
        $first = ord($data[0]);
        $version = $first >> 6;
        if ($version !== 1) {
            throw new UnexpectedValueException(__('Version CoAP non prise en charge', __FILE__));
        }
        $tokenLength = $first & 0x0F;
        if ($tokenLength > 8 || strlen($data) < 4 + $tokenLength) {
            throw new UnexpectedValueException(__('Longueur de jeton CoAP invalide', __FILE__));
        }

        $result = array(
            'type' => ($first >> 4) & 0x03,
            'code' => ord($data[1]),
            'message_id' => unpack('n', substr($data, 2, 2))[1],
            'token' => substr($data, 4, $tokenLength),
            'options' => array(),
            'payload' => '',
            'payload_offset' => null,
        );

        $offset = 4 + $tokenLength;
        $previous = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $byte = ord($data[$offset++]);
            if ($byte === 0xFF) {
                $result['payload_offset'] = $offset;
                $result['payload'] = substr($data, $offset);
                return $result;
            }
            $delta = self::decodeExtended($data, $offset, $byte >> 4);
            $optionLength = self::decodeExtended($data, $offset, $byte & 0x0F);
            if ($offset + $optionLength > $length) {
                throw new UnderflowException(__('Option CoAP tronquée', __FILE__));
            }
            $number = $previous + $delta;
            $result['options'][] = array(
                'number' => $number,
                'value' => substr($data, $offset, $optionLength),
            );
            $offset += $optionLength;
            $previous = $number;
        }
        return $result;
    }

    /**
     * Returns the exact frame length when CoAP carries a non-final Block2
     * response, null for a complete variable-length frame, or 0 while its
     * header/options are incomplete.
     */
    public static function streamFrameLength($data)
    {
        if (!is_string($data) || strlen($data) < 4) {
            return 0;
        }
        try {
            $packet = self::parse($data);
        } catch (Exception $exception) {
            return 0;
        }
        if (
            $packet['type'] === self::TYPE_ACK
            && $packet['code'] === 0
            && $packet['token'] === ''
            && $packet['payload'] === ''
        ) {
            return 4;
        }
        if ($packet['payload_offset'] === null) {
            return null;
        }
        $blocks = self::optionValues($packet, self::OPTION_BLOCK2);
        if (count($blocks) === 0) {
            return null;
        }
        $blockValue = self::uintOption($blocks[0]);
        $more = (($blockValue >> 3) & 1) === 1;
        $szx = $blockValue & 0x07;
        if (!$more || $szx > 6) {
            return null;
        }
        return (int) $packet['payload_offset'] + (1 << ($szx + 4));
    }

    public static function optionValues($packet, $number)
    {
        $values = array();
        foreach ($packet['options'] ?? array() as $option) {
            if ((int) ($option['number'] ?? -1) === (int) $number) {
                $values[] = (string) ($option['value'] ?? '');
            }
        }
        return $values;
    }

    public static function uintOption($value)
    {
        $length = strlen($value);
        if ($length === 0) {
            return 0;
        }
        if ($length > 4) {
            throw new OverflowException(__('Option CoAP entière trop grande', __FILE__));
        }
        $result = 0;
        for ($index = 0; $index < $length; $index++) {
            $result = ($result << 8) | ord($value[$index]);
        }
        return $result;
    }

    public static function encodeUint($value)
    {
        $value = (int) $value;
        if ($value < 0) {
            throw new InvalidArgumentException(__('Option CoAP négative', __FILE__));
        }
        if ($value === 0) {
            return '';
        }
        $result = '';
        while ($value > 0) {
            $result = chr($value & 0xFF) . $result;
            $value >>= 8;
        }
        return $result;
    }

    public static function blockValue($number, $more = false, $szx = self::BLOCK_SZX)
    {
        return self::encodeUint(
            (((int) $number) << 4)
            | ($more ? 0x08 : 0)
            | ((int) $szx & 0x07)
        );
    }

    public static function formatCode($code)
    {
        return ((int) $code >> 5) . '.' . str_pad((string) ((int) $code & 0x1F), 2, '0', STR_PAD_LEFT);
    }

    private static function encodeOptions($options)
    {
        $indexed = array();
        foreach ($options as $index => $option) {
            $indexed[] = array('index' => $index, 'option' => $option);
        }
        usort($indexed, function ($left, $right) {
            $comparison = ((int) $left['option'][0]) <=> ((int) $right['option'][0]);
            return $comparison !== 0 ? $comparison : $left['index'] <=> $right['index'];
        });
        $result = '';
        $previous = 0;
        foreach ($indexed as $row) {
            $option = $row['option'];
            if (!is_array($option) || count($option) < 2) {
                throw new InvalidArgumentException(__('Option CoAP invalide', __FILE__));
            }
            $number = (int) $option[0];
            $value = (string) $option[1];
            if ($number < $previous) {
                throw new InvalidArgumentException(__('Ordre des options CoAP invalide', __FILE__));
            }
            list($deltaNibble, $deltaExtension) = self::encodeExtended($number - $previous);
            list($lengthNibble, $lengthExtension) = self::encodeExtended(strlen($value));
            $result .= chr(($deltaNibble << 4) | $lengthNibble)
                . $deltaExtension
                . $lengthExtension
                . $value;
            $previous = $number;
        }
        return $result;
    }

    private static function encodeExtended($value)
    {
        if ($value < 13) {
            return array($value, '');
        }
        if ($value < 269) {
            return array(13, chr($value - 13));
        }
        if ($value <= 65804) {
            return array(14, pack('n', $value - 269));
        }
        throw new OverflowException(__('Option CoAP trop volumineuse', __FILE__));
    }

    private static function decodeExtended($data, &$offset, $nibble)
    {
        if ($nibble < 13) {
            return $nibble;
        }
        if ($nibble === 13) {
            if ($offset >= strlen($data)) {
                throw new UnderflowException(__('Extension CoAP tronquée', __FILE__));
            }
            return 13 + ord($data[$offset++]);
        }
        if ($nibble === 14) {
            if ($offset + 2 > strlen($data)) {
                throw new UnderflowException(__('Extension CoAP tronquée', __FILE__));
            }
            $value = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            return 269 + $value;
        }
        throw new UnexpectedValueException(__('Nibble CoAP réservé', __FILE__));
    }
}

class LocalThingsSession
{
    private const RESPONSE_TIMEOUT = 4.0;
    private const MAX_ATTEMPTS = 3;
    private const MAX_BLOCKS = 32;

    private $transport;
    private $messageId;
    private $tokenCounter;
    private $lastSend = 0.0;
    private $logger;

    public function __construct(LocalThingsDtlsClient $transport, $logger = null)
    {
        $this->transport = $transport;
        $this->messageId = random_int(0, 0xFFFF);
        $this->tokenCounter = random_int(0, 0x7FFFFFFF);
        $this->logger = is_callable($logger) ? $logger : null;
    }

    public function connect($timeout = 12.0)
    {
        $this->transport->connect($timeout);
    }

    public function get($pathSegments, $timeout = 25.0, $query = array())
    {
        $pathSegments = $this->normalizePath($pathSegments);
        $pathLabel = '/' . implode('/', $pathSegments);
        $started = microtime(true);
        $this->log('debug', sprintf(__('[CoAP] GET %s démarré', __FILE__), $pathLabel));
        $token = $this->nextToken();
        $payload = '';
        $block = 0;
        $szx = LocalThingsCoap::BLOCK_SZX;
        $deadline = microtime(true) + max(1.0, (float) $timeout);
        $lastCode = 0;

        while (true) {
            if ($block > 0) {
                $this->pace();
            }
            $options = array();
            foreach ($pathSegments as $segment) {
                $options[] = array(LocalThingsCoap::OPTION_URI_PATH, $segment);
            }
            foreach ((array) $query as $value) {
                $options[] = array(LocalThingsCoap::OPTION_URI_QUERY, (string) $value);
            }
            $options[] = array(
                LocalThingsCoap::OPTION_ACCEPT,
                LocalThingsCoap::encodeUint(LocalThingsCoap::CONTENT_FORMAT_CBOR)
            );
            if ($block > 0) {
                $options[] = array(
                    LocalThingsCoap::OPTION_BLOCK2,
                    LocalThingsCoap::blockValue($block, false, $szx)
                );
            }
            $response = $this->exchange(
                LocalThingsCoap::METHOD_GET,
                $token,
                $options,
                '',
                $deadline,
                $block
            );
            $lastCode = (int) $response['code'];
            $payload .= (string) $response['payload'];
            $this->log(
                'debug',
                sprintf(
                    __('[CoAP] GET %1$s bloc=%2$d -> %3$s, payload=%4$d octets', __FILE__),
                    $pathLabel,
                    $block,
                    LocalThingsCoap::formatCode($lastCode),
                    strlen((string) $response['payload'])
                )
            );
            if (($lastCode >> 5) !== 2) {
                return array($lastCode, $payload);
            }
            $blockOptions = LocalThingsCoap::optionValues($response, LocalThingsCoap::OPTION_BLOCK2);
            $more = false;
            if (count($blockOptions) > 0) {
                $value = LocalThingsCoap::uintOption($blockOptions[0]);
                $more = (($value >> 3) & 1) === 1;
                $szx = $value & 0x07;
            }
            if (!$more) {
                $this->log(
                    'debug',
                    sprintf(
                        __('[CoAP] GET %1$s terminé en %2$d ms, %3$d octets, %4$d bloc(s)', __FILE__),
                        $pathLabel,
                        (int) round((microtime(true) - $started) * 1000),
                        strlen($payload),
                        $block + 1
                    )
                );
                return array($lastCode, $payload);
            }
            $block++;
            if ($block > self::MAX_BLOCKS) {
                throw new RuntimeException(__('Réponse CoAP supérieure à ', __FILE__) . self::MAX_BLOCKS . ' blocs');
            }
        }
    }

    public function post($pathSegments, $value, $timeout = 15.0)
    {
        $pathSegments = $this->normalizePath($pathSegments);
        $pathLabel = '/' . implode('/', $pathSegments);
        $this->log('debug', sprintf(__('[CoAP] POST %s démarré', __FILE__), $pathLabel));
        $token = $this->nextToken();
        $options = array();
        foreach ($pathSegments as $segment) {
            $options[] = array(LocalThingsCoap::OPTION_URI_PATH, $segment);
        }
        $options[] = array(
            LocalThingsCoap::OPTION_CONTENT_FORMAT,
            LocalThingsCoap::encodeUint(LocalThingsCoap::CONTENT_FORMAT_CBOR)
        );
        $options[] = array(
            LocalThingsCoap::OPTION_ACCEPT,
            LocalThingsCoap::encodeUint(LocalThingsCoap::CONTENT_FORMAT_CBOR)
        );
        $response = $this->exchange(
            LocalThingsCoap::METHOD_POST,
            $token,
            $options,
            LocalThingsCbor::encode($value),
            microtime(true) + max(1.0, (float) $timeout)
        );
        $this->log(
            'debug',
            sprintf(
                __('[CoAP] POST %1$s -> %2$s, payload=%3$d octets', __FILE__),
                $pathLabel,
                LocalThingsCoap::formatCode((int) $response['code']),
                strlen((string) $response['payload'])
            )
        );
        return array((int) $response['code'], (string) $response['payload']);
    }

    public function close()
    {
        $this->transport->close();
    }

    private function exchange($method, $token, $options, $payload, $deadline, $expectedBlock = null)
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $messageId = $this->nextMessageId();
            $packet = LocalThingsCoap::build(
                LocalThingsCoap::TYPE_CON,
                $method,
                $messageId,
                $token,
                $options,
                $payload
            );
            $this->transport->write($packet);
            $this->log(
                'debug',
                sprintf(
                    __('[CoAP] Requête MID=%1$d, tentative=%2$d, paquet=%3$d octets', __FILE__),
                    $messageId,
                    $attempt,
                    strlen($packet)
                )
            );
            $this->lastSend = microtime(true);
            $attemptDeadline = min($deadline, microtime(true) + self::RESPONSE_TIMEOUT);
            while (microtime(true) < $attemptDeadline) {
                $frame = $this->transport->readFrame($attemptDeadline - microtime(true));
                if ($frame === null || strlen($frame) < 4) {
                    continue;
                }
                try {
                    $response = LocalThingsCoap::parse($frame);
                } catch (Exception $exception) {
                    continue;
                }
                if ($response['type'] === LocalThingsCoap::TYPE_CON) {
                    $this->transport->write(
                        LocalThingsCoap::build(
                            LocalThingsCoap::TYPE_ACK,
                            0,
                            $response['message_id'],
                            '',
                            array()
                        )
                    );
                }
                if (
                    $response['type'] === LocalThingsCoap::TYPE_ACK
                    && $response['code'] === 0
                    && $response['token'] === ''
                    && $response['payload'] === ''
                ) {
                    $attemptDeadline = $deadline;
                    continue;
                }
                if (
                    hash_equals($token, (string) $response['token'])
                    && $this->matchesExpectedBlock($response, $expectedBlock)
                ) {
                    return $response;
                }
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            $this->log('debug', __('[CoAP] Pas de réponse, retransmission', __FILE__));
        }
        throw new RuntimeException(__('Délai de réponse CoAP dépassé', __FILE__));
    }

    private function matchesExpectedBlock($response, $expectedBlock)
    {
        if ($expectedBlock === null) {
            return true;
        }
        $values = LocalThingsCoap::optionValues($response, LocalThingsCoap::OPTION_BLOCK2);
        if (count($values) === 0) {
            return (int) $expectedBlock === 0;
        }
        $actualBlock = LocalThingsCoap::uintOption($values[0]) >> 4;
        if ($actualBlock === (int) $expectedBlock) {
            return true;
        }
        $this->log(
            'debug',
            sprintf(
                __('[CoAP] Réponse Block2 retardée ignorée : attendue=%1$d, reçue=%2$d', __FILE__),
                (int) $expectedBlock,
                $actualBlock
            )
        );
        return false;
    }

    private function pace()
    {
        $remaining = 0.2 - (microtime(true) - $this->lastSend);
        if ($remaining > 0) {
            usleep((int) ($remaining * 1000000));
        }
    }

    private function normalizePath($path)
    {
        if (is_string($path)) {
            $path = explode('/', trim($path, '/'));
        }
        $result = array();
        foreach ((array) $path as $segment) {
            $segment = trim((string) $segment);
            if ($segment !== '') {
                $result[] = $segment;
            }
        }
        return $result;
    }

    private function nextMessageId()
    {
        $this->messageId = ($this->messageId + 1) & 0xFFFF;
        return $this->messageId;
    }

    private function nextToken()
    {
        $this->tokenCounter = ($this->tokenCounter + 1) & 0xFFFFFFFF;
        return pack('N', $this->tokenCounter);
    }

    private function log($level, $message)
    {
        if ($this->logger === null) {
            return;
        }
        call_user_func($this->logger, (string) $level, substr((string) $message, 0, 1800));
    }
}
