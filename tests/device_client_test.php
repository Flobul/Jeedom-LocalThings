<?php

require_once __DIR__ . '/../core/class/LocalThingsClient.php';

function deviceClientCheck($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$filtered = LocalThingsDeviceClient::buildProbeOrder(
    array(49153, 49154),
    49155,
    false
);
deviceClientCheck(
    $filtered === array(49155, 49153, 49154),
    'filtered port order'
);

$exhaustive = LocalThingsDeviceClient::buildProbeOrder(
    array(49153),
    null,
    true
);
deviceClientCheck($exhaustive[0] === 49153, 'detected port stays first');
deviceClientCheck(
    count($exhaustive) === count(LocalThingsDeviceClient::PROBE_PORTS),
    'exhaustive port count'
);
foreach (LocalThingsDeviceClient::PROBE_PORTS as $port) {
    deviceClientCheck(in_array($port, $exhaustive, true), 'missing exhaustive port ' . $port);
}

$clientReflection = new ReflectionClass(LocalThingsDeviceClient::class);
$client = $clientReflection->newInstanceWithoutConstructor();
deviceClientCheck(
    $clientReflection->getConstant('WRITE_SETTLE_DELAY_US') >= 4000000,
    'write verification waits past the Samsung revert window'
);
$normalizeIdentifier = $clientReflection->getMethod('normalizeIdentifier');
if (PHP_VERSION_ID < 80100) {
    $normalizeIdentifier->setAccessible(true);
}
deviceClientCheck(
    $normalizeIdentifier->invoke(null, 'FFFFFFFFFFFFFFF') === '',
    'Samsung placeholder serial is ignored'
);
deviceClientCheck(
    $normalizeIdentifier->invoke(null, '00000000-0000-0000-0000-000000000000') === '',
    'nil OCF identifier is ignored'
);
deviceClientCheck(
    $normalizeIdentifier->invoke(null, 'SERIAL-1234') === 'SERIAL-1234',
    'real serial is preserved'
);
$contains = $clientReflection->getMethod('representationContains');
if (PHP_VERSION_ID < 80100) {
    $contains->setAccessible(true);
}
deviceClientCheck(
    $contains->invoke(
        $client,
        array('value' => true, 'level' => '2'),
        array('value' => true, 'level' => 2)
    ),
    'write verification scalar normalization'
);
deviceClientCheck(
    $contains->invoke(
        $client,
        array('x.com.samsung.da.options' => array('Course_1C', 'BubbleSoak_On')),
        array('x.com.samsung.da.options' => array('BubbleSoak_On'))
    ),
    'write verification partial options list'
);
deviceClientCheck(
    !$contains->invoke(
        $client,
        array('x.com.samsung.da.state' => 'Ready'),
        array('x.com.samsung.da.state' => 'Run')
    ),
    'write verification detects ignored command'
);

echo 'Device client tests: OK' . PHP_EOL;
