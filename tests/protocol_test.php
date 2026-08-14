<?php

require_once __DIR__ . '/../core/class/LocalThingsProtocol.php';
require_once __DIR__ . '/../core/class/LocalThingsTransport.php';

function check($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
        exit(1);
    }
}

$cborVectors = array(
    '00' => 0,
    '01' => 1,
    '0a' => 10,
    '1818' => 24,
    '1903e8' => 1000,
    '20' => -1,
    '29' => -10,
    'f4' => false,
    'f5' => true,
    'f6' => null,
    '63666f6f' => 'foo',
    '83010203' => array(1, 2, 3),
    'a26161016162820203' => array('a' => 1, 'b' => array(2, 3)),
    '9f018202039f0405ffff' => array(1, array(2, 3), array(4, 5)),
    '9f41ffff' => array("\xFF"),
    '7f657374726561646d696e67ff' => 'streaming',
);
foreach ($cborVectors as $hex => $expected) {
    $consumed = 0;
    $decoded = LocalThingsCbor::decode(hex2bin($hex), $consumed);
    check($decoded === $expected, 'CBOR decode ' . $hex);
    check($consumed === strlen(hex2bin($hex)), 'CBOR consumed ' . $hex);
}

$roundTrips = array(
    0,
    23,
    24,
    65535,
    -42,
    true,
    false,
    null,
    3.1415926,
    'Samsung',
    array(1, 'two', false),
    array('href' => '/device/0', 'value' => 42),
);
foreach ($roundTrips as $value) {
    $decoded = LocalThingsCbor::decode(LocalThingsCbor::encode($value));
    if (is_float($value)) {
        check(abs($decoded - $value) < 0.0000001, 'CBOR float round trip');
    } else {
        check($decoded === $value, 'CBOR round trip');
    }
}

$token = hex2bin('01020304');
$options = array(
    array(LocalThingsCoap::OPTION_URI_PATH, 'device'),
    array(LocalThingsCoap::OPTION_URI_PATH, '0'),
    array(LocalThingsCoap::OPTION_ACCEPT, LocalThingsCoap::encodeUint(60)),
    array(LocalThingsCoap::OPTION_BLOCK2, LocalThingsCoap::blockValue(3, false, 6)),
);
$coap = LocalThingsCoap::build(
    LocalThingsCoap::TYPE_CON,
    LocalThingsCoap::METHOD_GET,
    0x1234,
    $token,
    $options
);
$parsed = LocalThingsCoap::parse($coap);
check($parsed['type'] === LocalThingsCoap::TYPE_CON, 'CoAP type');
check($parsed['code'] === LocalThingsCoap::METHOD_GET, 'CoAP code');
check($parsed['message_id'] === 0x1234, 'CoAP MID');
check($parsed['token'] === $token, 'CoAP token');
check(
    LocalThingsCoap::optionValues($parsed, LocalThingsCoap::OPTION_URI_PATH)
        === array('device', '0'),
    'CoAP URI path order'
);
check(
    LocalThingsCoap::uintOption(
        LocalThingsCoap::optionValues($parsed, LocalThingsCoap::OPTION_BLOCK2)[0]
    ) === 0x36,
    'CoAP Block2'
);

$payload = LocalThingsCbor::encode(array('value' => 'On'));
$post = LocalThingsCoap::build(
    LocalThingsCoap::TYPE_CON,
    LocalThingsCoap::METHOD_POST,
    7,
    $token,
    array(
        array(LocalThingsCoap::OPTION_URI_PATH, 'power'),
        array(LocalThingsCoap::OPTION_CONTENT_FORMAT, LocalThingsCoap::encodeUint(60)),
    ),
    $payload
);
$parsedPost = LocalThingsCoap::parse($post);
check(LocalThingsCbor::decode($parsedPost['payload']) === array('value' => 'On'), 'CoAP payload');

$blockPayload = str_repeat("\x01", 1024);
$blockFrame = LocalThingsCoap::build(
    LocalThingsCoap::TYPE_ACK,
    69,
    8,
    $token,
    array(
        array(LocalThingsCoap::OPTION_CONTENT_FORMAT, LocalThingsCoap::encodeUint(60)),
        array(LocalThingsCoap::OPTION_BLOCK2, LocalThingsCoap::blockValue(1, true, 6)),
    ),
    $blockPayload
);
check(
    LocalThingsCoap::streamFrameLength(substr($blockFrame, 0, 1024)) === strlen($blockFrame),
    'CoAP streamed non-final Block2 length'
);
check(
    LocalThingsCoap::streamFrameLength(substr($blockFrame, 0, 6)) === 0,
    'CoAP streamed incomplete header'
);
$finalBlock = LocalThingsCoap::build(
    LocalThingsCoap::TYPE_ACK,
    69,
    9,
    $token,
    array(array(LocalThingsCoap::OPTION_BLOCK2, LocalThingsCoap::blockValue(2, false, 6))),
    str_repeat("\x02", 173)
);
check(LocalThingsCoap::streamFrameLength($finalBlock) === null, 'CoAP final Block2 variable length');
$emptyAck = LocalThingsCoap::build(LocalThingsCoap::TYPE_ACK, 0, 10, '', array());
check(LocalThingsCoap::streamFrameLength($emptyAck) === 4, 'CoAP empty ACK length');

$transportReflection = new ReflectionClass('LocalThingsDtlsClient');
$transport = $transportReflection->newInstanceWithoutConstructor();
$receiveBuffer = $transportReflection->getProperty('receiveBuffer');
$takeFrame = $transportReflection->getMethod('takeBufferedFrame');
if (PHP_VERSION_ID < 80100) {
    $receiveBuffer->setAccessible(true);
    $takeFrame->setAccessible(true);
}
$firstChunk = substr($blockFrame, 0, 1024);
$receiveBuffer->setValue($transport, $firstChunk);
check($takeFrame->invoke($transport, false) === null, 'DTLS waits for complete Block2 frame');
$receiveBuffer->setValue($transport, $firstChunk . substr($blockFrame, 1024));
check($takeFrame->invoke($transport, false) === $blockFrame, 'DTLS rejoins split Block2 frame');
$receiveBuffer->setValue($transport, $finalBlock);
check($takeFrame->invoke($transport, false) === null, 'DTLS waits for final frame idle');
check($takeFrame->invoke($transport, true) === $finalBlock, 'DTLS releases final frame after idle');

echo "Protocol tests: OK" . PHP_EOL;
