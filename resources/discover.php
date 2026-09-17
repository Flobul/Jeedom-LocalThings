#!/usr/bin/env php
<?php

require_once __DIR__ . '/../../../core/php/core.inc.php';
require_once __DIR__ . '/../core/class/localthings.class.php';

$options = getopt('', array('job:'));
$jobId = (string) ($options['job'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/D', $jobId)) {
    fwrite(STDERR, __('Tâche LocalThings invalide', __FILE__) . "\n");
    exit(1);
}

$logger = function ($level, $message) {
    log::add('localthings', $level, $message);
};
$logger('info', '[Diagnostic] LocalThings ' . localthings::$_pluginVersion
    . ' ; PHP ' . PHP_VERSION . ' ; ' . PHP_OS_FAMILY . ' ; transport direct, relais UDP réservé à 5684');

try {
    LocalThingsDiscovery::run($jobId, function ($host, $exhaustive) {
        $knownPorts = array();
        foreach (eqLogic::byType('localthings') as $equipment) {
            $port = (int) $equipment->getConfiguration('port', 0);
            if ($equipment->getConfiguration('host', '') === $host && $port > 0 && $port <= 65535) {
                $knownPorts[$port] = $port;
            }
        }
        $preferredPort = count($knownPorts) === 1 ? reset($knownPorts) : null;
        $snapshot = localthings::deviceClient()->probe($host, $preferredPort, $exhaustive);
        LocalThingsDiscovery::checkpoint(true);
        localthings::registerSnapshot($snapshot);
        return $snapshot;
    }, $logger);
} catch (Throwable $error) {
    $logger('error', '[Discovery] ' . $error->getMessage());
    exit(1);
}
