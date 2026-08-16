#!/usr/bin/env php
<?php

require_once __DIR__ . '/../../../core/php/core.inc.php';
require_once __DIR__ . '/../core/class/localthings.class.php';

$options = getopt('', array('job:'));
$jobPath = isset($options['job']) ? realpath((string) $options['job']) : false;
$tmpRoot = realpath(jeedom::getTmpFolder('localthings'));
if ($jobPath === false || $tmpRoot === false || strpos($jobPath, $tmpRoot . DIRECTORY_SEPARATOR) !== 0) {
    fwrite(STDERR, __('Tâche LocalThings invalide', __FILE__) . "\n");
    exit(1);
}

$logger = function ($level, $message) {
    log::add('localthings', $level, $message);
};

LocalThingsDiscovery::run($jobPath, function ($host, $exhaustive) {
    $snapshot = localthings::deviceClient()->probe($host, null, $exhaustive);
    localthings::registerSnapshot($snapshot);
    return $snapshot;
}, $logger);
