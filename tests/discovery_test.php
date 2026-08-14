<?php

require_once __DIR__ . '/../core/class/LocalThingsClient.php';

function discoveryCheck($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$directory = sys_get_temp_dir() . '/localthings-discovery-' . bin2hex(random_bytes(4));
mkdir($directory, 0700, true);
$jobPath = $directory . '/job.json';
$statusPath = $directory . '/status.json';
file_put_contents($jobPath, json_encode(array(
    'status_path' => $statusPath,
    'networks' => array(),
    'hosts' => array('192.0.2.10'),
)));

$probeCalls = array();
$logs = array();
LocalThingsDiscovery::run(
    $jobPath,
    function ($host, $exhaustive) use (&$probeCalls) {
        $probeCalls[] = array($host, $exhaustive);
        return array('device' => array('host' => $host, 'device_id' => 'test'));
    },
    function ($level, $message) use (&$logs) {
        $logs[] = array($level, $message);
    }
);

$status = LocalThingsDiscovery::readStatus($statusPath);
discoveryCheck($probeCalls === array(array('192.0.2.10', true)), 'direct probe mode');
discoveryCheck(!$status['running'], 'completed status');
discoveryCheck(count($status['found']) === 1, 'discovered device');
discoveryCheck(count($logs) >= 4, 'discovery progress logs');
discoveryCheck(!is_file($jobPath), 'job cleanup');

@unlink($statusPath);
@rmdir($directory);

echo 'Discovery tests: OK' . PHP_EOL;
