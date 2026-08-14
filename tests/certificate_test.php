<?php

require_once __DIR__ . '/../core/class/LocalThingsTransport.php';

function certCheck($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
        exit(1);
    }
}

function removeTree($path)
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $item = $path . '/' . $entry;
        is_dir($item) ? removeTree($item) : unlink($item);
    }
    rmdir($path);
}

$directory = sys_get_temp_dir() . '/localthings-cert-' . bin2hex(random_bytes(4));
$key = openssl_pkey_new(array(
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'private_key_bits' => 2048,
));
$csr = openssl_csr_new(
    array(
        'countryName' => 'KR',
        'organizationName' => 'Samsung Electronics',
        'commonName' => 'LocalThings test CA',
    ),
    $key,
    array('digest_alg' => 'sha256')
);
$certificate = openssl_csr_sign($csr, null, $key, 30, array('digest_alg' => 'sha256'), 1);
$certificatePem = '';
$keyPem = '';
openssl_x509_export($certificate, $certificatePem);
openssl_pkey_export($key, $keyPem);

try {
    $store = new LocalThingsCertificateStore($directory);
    $status = $store->install($certificatePem, $keyPem);
    certCheck(!empty($status['configured']), 'CA install');

    $gatewayUuid = 'ab0b0ac4-aae9-4958-a04d-8ec36fe1b2f9';
    $gatewayCsr = openssl_csr_new(
        array(
            'countryName' => 'KR',
            'organizationName' => 'Samsung Electronics',
            'organizationalUnitName' => 'uuid:' . $gatewayUuid,
            'commonName' => '*.samsungiotcloud.com',
        ),
        $key,
        array('digest_alg' => 'sha256')
    );
    $gatewayCertificate = openssl_csr_sign(
        $gatewayCsr,
        null,
        $key,
        30,
        array('digest_alg' => 'sha256'),
        2
    );
    $parsedGateway = openssl_x509_parse($gatewayCertificate, false);
    certCheck(
        isset($parsedGateway['subject']['organizationalUnitName']),
        'OpenSSL long organizational unit name'
    );
    certCheck(
        LocalThingsCertificateStore::extractSamsungUuid($parsedGateway) === $gatewayUuid,
        'UUID from parsed OpenSSL certificate'
    );
    certCheck(
        LocalThingsCertificateStore::extractSamsungUuid(array(
            'subject' => array('OU' => array('OCF Server', 'uuid:' . strtoupper($gatewayUuid))),
        )) === $gatewayUuid,
        'UUID from OpenSSL short subject name array'
    );
    certCheck(
        LocalThingsCertificateStore::extractSamsungUuid(array(
            'subject' => array('commonName' => 'urn:uuid:' . $gatewayUuid),
        )) === $gatewayUuid,
        'UUID from certificate common name fallback'
    );

    file_put_contents(
        $directory . '/certificates/samsung-cloud.uuid',
        "123e4567-e89b-42d3-a456-426614174000\n"
    );
    list($leafPath, $leafKeyPath) = $store->mintLeaf('host:192.0.2.1');
    certCheck(is_file($leafPath) && is_file($leafKeyPath), 'leaf files');
    $leaf = openssl_x509_read(file_get_contents($leafPath));
    $leafKey = openssl_pkey_get_private(file_get_contents($leafKeyPath));
    certCheck(openssl_x509_check_private_key($leaf, $leafKey), 'leaf key match');
    certCheck(
        strpos(file_get_contents($leafPath), 'BEGIN CERTIFICATE') !== false,
        'leaf full chain'
    );
} finally {
    removeTree($directory);
}

echo "Certificate tests: OK" . PHP_EOL;
