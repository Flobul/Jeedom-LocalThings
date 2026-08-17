<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/LocalThingsProtocol.php';
require_once __DIR__ . '/LocalThingsTransport.php';
require_once __DIR__ . '/LocalThingsMapper.php';
require_once __DIR__ . '/LocalThingsWidget.php';
require_once __DIR__ . '/LocalThingsClient.php';

class localthings extends eqLogic
{
    public static $_pluginVersion = '0.4.6';
    public static $_widgetPossibility = array('custom' => true, 'custom::layout' => true);

    private static function resourcePath()
    {
        return realpath(__DIR__ . '/../../resources');
    }

    private static function dataPath()
    {
        $path = realpath(__DIR__ . '/../..') . '/data';
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException(__('Création du répertoire de données impossible', __FILE__));
        }
        @chmod($path, 0700);
        return $path;
    }

    private static function statusPath()
    {
        $directory = jeedom::getTmpFolder(__CLASS__);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        return $directory . '/discovery-status.json';
    }

    private static function configuredNetworks()
    {
        $raw = (string) config::byKey('discovery_networks', __CLASS__, '');
        $rows = preg_split('/[\s,;]+/', trim($raw));
        $networks = array_values(array_filter(array_map('trim', $rows), 'strlen'));
        if (count($networks) === 0) {
            $host = (string) parse_url(network::getNetworkAccess('internal'), PHP_URL_HOST);
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $octets = explode('.', $host);
                $networks[] = $octets[0] . '.' . $octets[1] . '.' . $octets[2] . '.0/24';
            }
        }
        return LocalThingsDiscovery::validateNetworks(array_values(array_unique($networks)));
    }

    public static function certificateStore()
    {
        return new LocalThingsCertificateStore(self::dataPath());
    }

    public static function deviceClient()
    {
        $openssl = LocalThingsDeviceClient::findOpenSsl();
        if ($openssl === '' || !LocalThingsDeviceClient::supportsDtls($openssl)) {
            throw new RuntimeException(__('OpenSSL avec prise en charge DTLS est introuvable', __FILE__));
        }
        return new LocalThingsDeviceClient(
            $openssl,
            self::certificateStore(),
            self::resourcePath() . '/certificates/ocf_root_ca.pem',
            null,
            function ($level, $message) {
                log::add(__CLASS__, $level, $message);
            }
        );
    }

    public static function dependancy_info()
    {
        $openssl = LocalThingsDeviceClient::findOpenSsl();
        $state = $openssl !== '' && LocalThingsDeviceClient::supportsDtls($openssl) ? 'ok' : 'nok';
        return array(
            'log' => __CLASS__ . '_update',
            'progress_file' => jeedom::getTmpFolder(__CLASS__) . '/dependance',
            'state' => $state,
        );
    }

    public static function dependancy_install()
    {
        log::remove(__CLASS__ . '_update');
        return array(
            'script' => '/bin/bash ' . escapeshellarg(self::resourcePath() . '/install.sh')
                . ' ' . escapeshellarg(jeedom::getTmpFolder(__CLASS__) . '/dependance'),
            'log' => log::getPathToLog(__CLASS__ . '_update'),
        );
    }

    public static function synchronize()
    {
        self::assertCertificates();
        $networks = self::configuredNetworks();
        log::add(
            __CLASS__,
            'info',
            sprintf(
                __('[Discovery] Découverte réseau demandée : %s', __FILE__),
                implode(', ', $networks)
            )
        );
        $status = LocalThingsDiscovery::start(
            self::statusPath(),
            self::resourcePath() . '/discover.php',
            $networks,
            array(),
            log::getPathToLog(__CLASS__)
        );
        log::add(
            __CLASS__,
            'info',
            sprintf(
                __('[Discovery] Tâche PHP lancée, PID=%d', __FILE__),
                (int) ($status['worker_pid'] ?? 0)
            )
        );
        return $status;
    }

    public static function probeHost($host)
    {
        self::assertCertificates();
        log::add(
            __CLASS__,
            'info',
            sprintf(
                __('[Discovery] Ajout manuel demandé pour %s', __FILE__),
                trim((string) $host)
            )
        );
        $status = LocalThingsDiscovery::start(
            self::statusPath(),
            self::resourcePath() . '/discover.php',
            array(),
            array((string) $host),
            log::getPathToLog(__CLASS__)
        );
        log::add(
            __CLASS__,
            'info',
            sprintf(
                __('[Discovery] Tâche PHP manuelle lancée, PID=%d', __FILE__),
                (int) ($status['worker_pid'] ?? 0)
            )
        );
        return $status;
    }

    public static function scanStatus()
    {
        return LocalThingsDiscovery::readStatus(self::statusPath());
    }

    public static function transportStatus()
    {
        $openssl = LocalThingsDeviceClient::findOpenSsl();
        return array(
            'ok' => $openssl !== '' && LocalThingsDeviceClient::supportsDtls($openssl),
            'path' => $openssl,
            'php' => PHP_VERSION,
            'proc_open' => function_exists('proc_open'),
        );
    }

    public static function certificateStatus()
    {
        return self::certificateStore()->status();
    }

    public static function bootstrapCertificates()
    {
        return self::certificateStore()->bootstrap();
    }

    public static function installCertificates($certificate, $privateKey)
    {
        return self::certificateStore()->install($certificate, $privateKey);
    }

    private static function assertCertificates()
    {
        if (!self::certificateStore()->isConfigured()) {
            throw new RuntimeException(__('Configurez d’abord les certificats LocalThings', __FILE__));
        }
    }

    public static function registerSnapshot($snapshot)
    {
        if (!is_array($snapshot) || !isset($snapshot['device']) || !is_array($snapshot['device'])) {
            throw new InvalidArgumentException(__('Réponse LocalThings invalide', __FILE__));
        }
        $device = $snapshot['device'];
        $deviceId = trim((string) ($device['device_id'] ?? ''));
        if ($deviceId === '' || strlen($deviceId) > 255) {
            throw new InvalidArgumentException(__('Identifiant d’appareil invalide', __FILE__));
        }
        $eqLogic = self::byDeviceId($deviceId);
        if (!is_object($eqLogic)) {
            $eqLogic = self::byEndpoint(
                (string) ($device['host'] ?? ''),
                (int) ($device['port'] ?? 0)
            );
        }
        $isNew = !is_object($eqLogic);
        if ($isNew) {
            $eqLogic = new self();
            $eqLogic->setEqType_name(__CLASS__);
            $eqLogic->setLogicalId('device_' . substr(sha1($deviceId), 0, 24));
            $eqLogic->setName(trim((string) ($device['name'] ?? '')) ?: 'Samsung LocalThings');
            $eqLogic->setIsEnable(1);
            $eqLogic->setIsVisible(1);
        }
        $eqLogic->setConfiguration('device_id', $deviceId);
        $eqLogic->setConfiguration('serial', (string) ($device['serial'] ?? ''));
        $eqLogic->setConfiguration('host', (string) ($device['host'] ?? ''));
        $eqLogic->setConfiguration('port', (int) ($device['port'] ?? 0));
        $eqLogic->setConfiguration('manufacturer', (string) ($device['manufacturer'] ?? 'Samsung'));
        $eqLogic->setConfiguration('model', (string) ($device['model'] ?? ''));
        $eqLogic->setConfiguration('device_type', (string) ($device['device_type'] ?? 'unknown'));
        $eqLogic->setConfiguration('last_error', (string) ($device['last_error'] ?? ''));
        $lastUpdate = (int) ($device['last_update'] ?? time());
        $eqLogic->setConfiguration('last_communication', date('Y-m-d H:i:s', $lastUpdate));
        $eqLogic->setConfiguration('last_refresh', $lastUpdate);
        $eqLogic->save();

        self::ensureConnectedCommand($eqLogic);
        self::ensureRefreshCommand($eqLogic);
        if (isset($snapshot['entities']) && is_array($snapshot['entities'])) {
            self::syncCommands($eqLogic, $snapshot['entities']);
        }
        self::applyStates($eqLogic, $snapshot['states'] ?? array(), true);
        if ($isNew) {
            log::add(__CLASS__, 'info', __('Nouvel appareil local découvert : ', __FILE__) . $eqLogic->getHumanName());
        }
        return array(
            'id' => $eqLogic->getId(),
            'name' => $eqLogic->getName(),
            'device_id' => $deviceId,
            'host' => $eqLogic->getConfiguration('host'),
        );
    }

    private static function byDeviceId($deviceId)
    {
        foreach (self::byType(__CLASS__) as $eqLogic) {
            if ((string) $eqLogic->getConfiguration('device_id') === (string) $deviceId) {
                return $eqLogic;
            }
        }
        return null;
    }

    private static function byEndpoint($host, $port)
    {
        if ($host === '' || $port === 0) {
            return null;
        }
        foreach (self::byType(__CLASS__) as $eqLogic) {
            if (
                (string) $eqLogic->getConfiguration('host') === (string) $host
                && (int) $eqLogic->getConfiguration('port') === (int) $port
            ) {
                return $eqLogic;
            }
        }
        return null;
    }

    private static function ensureConnectedCommand($eqLogic)
    {
        $command = $eqLogic->getCmd('info', 'connected');
        if (!is_object($command)) {
            $command = new localthingsCmd();
            $command->setEqLogic_id($eqLogic->getId());
            $command->setLogicalId('connected');
            $command->setName(__('Connecté', __FILE__));
            $command->setType('info');
            $command->setSubType('binary');
            $command->setIsVisible(1);
            $command->setIsHistorized(0);
            $command->setConfiguration('managedByLocalThings', 1);
            $command->setConfiguration('entityKey', '__connected');
            $command->save();
        }
    }

    private static function ensureRefreshCommand($eqLogic)
    {
        $command = $eqLogic->getCmd('action', 'refresh');
        $nameRegistry = self::commandNameRegistry($eqLogic);
        if (!is_object($command)) {
            $command = new localthingsCmd();
            $command->setEqLogic_id($eqLogic->getId());
            $command->setLogicalId('refresh');
            $command->setType('action');
        }
        self::releaseCommandName($nameRegistry, $command);
        $command->setName(
            self::reserveCommandName($nameRegistry, __('Rafraîchir', __FILE__), 'refresh')
        );
        $command->setSubType('other');
        $command->setIsVisible(1);
        $command->setConfiguration('managedByLocalThings', 1);
        $command->setConfiguration('entityKey', '__refresh');
        $command->setConfiguration('operation', 'refresh');
        $command->setConfiguration('fixedValue', 'null');
        $command->setConfiguration('extra', '{}');
        $command->save();
    }

    private static function commandLogicalId($prefix, $key)
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $key);
        $clean = trim($clean, '_') ?: 'value';
        return $prefix . '_' . substr($clean, 0, 80) . '_' . substr(sha1((string) $key), 0, 8);
    }

    private static function commandNameKey($name)
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $name));
        return function_exists('mb_strtolower')
            ? mb_strtolower($name, 'UTF-8')
            : strtolower($name);
    }

    private static function commandNameRegistry($eqLogic)
    {
        $registry = array();
        foreach (array('info', 'action') as $type) {
            foreach ($eqLogic->getCmd($type) as $command) {
                $key = self::commandNameKey($command->getName());
                if ($key !== '') {
                    $registry[$key] = (string) $command->getLogicalId();
                }
            }
        }
        return $registry;
    }

    private static function releaseCommandName(&$registry, $command)
    {
        if (!is_object($command)) {
            return;
        }
        $key = self::commandNameKey($command->getName());
        $logicalId = (string) $command->getLogicalId();
        if ($key !== '' && isset($registry[$key]) && $registry[$key] === $logicalId) {
            unset($registry[$key]);
        }
    }

    private static function reserveCommandName(&$registry, $preferredName, $logicalId)
    {
        $base = preg_replace('/\s+/u', ' ', trim((string) $preferredName));
        if ($base === '') {
            $base = __('Commande', __FILE__);
        }

        $name = $base;
        $suffix = 2;
        $key = self::commandNameKey($name);
        while (isset($registry[$key]) && $registry[$key] !== (string) $logicalId) {
            $name = $base . ' (' . $suffix++ . ')';
            $key = self::commandNameKey($name);
        }
        $registry[$key] = (string) $logicalId;
        return $name;
    }

    private static function syncCommands($eqLogic, $entities)
    {
        $nameRegistry = self::commandNameRegistry($eqLogic);
        $processedEntityKeys = array();
        $desiredActionLogicalIds = array();
        foreach ($entities as $entity) {
            if (!is_array($entity) || empty($entity['key'])) {
                continue;
            }
            $entityKey = (string) $entity['key'];
            $processedEntityKeys[$entityKey] = true;
            $logicalId = self::commandLogicalId('info', $entityKey);
            $info = $eqLogic->getCmd('info', $logicalId);
            if (!is_object($info)) {
                $info = new localthingsCmd();
                $info->setEqLogic_id($eqLogic->getId());
                $info->setLogicalId($logicalId);
                $info->setType('info');
                $info->setIsVisible(1);
                $info->setIsHistorized(0);
            }
            self::releaseCommandName($nameRegistry, $info);
            $infoName = self::reserveCommandName(
                $nameRegistry,
                (string) ($entity['name'] ?? $entityKey),
                $logicalId
            );
            $info->setName($infoName);
            $subtype = (string) ($entity['subtype'] ?? 'string');
            if (!in_array($subtype, array('binary', 'numeric', 'string'), true)) {
                $subtype = 'string';
            }
            $info->setSubType($subtype);
            $info->setUnite((string) ($entity['unit'] ?? ''));
            $info->setConfiguration('managedByLocalThings', 1);
            $info->setConfiguration('entityKey', $entityKey);
            $info->setConfiguration('platform', (string) ($entity['platform'] ?? ''));
            $info->setConfiguration('entityCategory', (string) ($entity['category'] ?? ''));
            $info->save();

            foreach (($entity['actions'] ?? array()) as $action) {
                if (!is_array($action) || empty($action['key'])) {
                    continue;
                }
                $actionIdentity = $entityKey . '::' . (string) $action['key'];
                $actionLogicalId = self::commandLogicalId('action', $actionIdentity);
                $desiredActionLogicalIds[$actionLogicalId] = true;
                $command = $eqLogic->getCmd('action', $actionLogicalId);
                if (!is_object($command)) {
                    $command = new localthingsCmd();
                    $command->setEqLogic_id($eqLogic->getId());
                    $command->setLogicalId($actionLogicalId);
                    $command->setType('action');
                    $command->setIsVisible(1);
                }
                self::releaseCommandName($nameRegistry, $command);
                $actionSubtype = (string) ($action['subtype'] ?? 'other');
                if (!in_array($actionSubtype, array('other', 'slider', 'select', 'message', 'color'), true)) {
                    $actionSubtype = 'other';
                }
                $command->setName(
                    self::reserveCommandName(
                        $nameRegistry,
                        self::generatedActionName($entityKey, $infoName, $action, $actionSubtype),
                        $actionLogicalId
                    )
                );
                $command->setSubType($actionSubtype);
                $command->setValue($info->getId());
                $command->setUnite((string) ($action['unit'] ?? ''));
                $command->setConfiguration('managedByLocalThings', 1);
                $command->setConfiguration('entityKey', (string) ($action['target'] ?? $entityKey));
                $command->setConfiguration('operation', (string) ($action['operation'] ?? 'write'));
                $command->setConfiguration(
                    'fixedValue',
                    json_encode($action['fixed_value'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
                $command->setConfiguration(
                    'extra',
                    json_encode($action['extra'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
                if ($actionSubtype === 'select') {
                    $values = array();
                    foreach (($action['options'] ?? array()) as $option) {
                        if (is_array($option)) {
                            $optionValue = $option['value'] ?? null;
                            $optionLabel = $option['label'] ?? $optionValue;
                        } else {
                            $optionValue = $option;
                            $optionLabel = $option;
                        }
                        if (!is_scalar($optionValue) || !is_scalar($optionLabel)) {
                            continue;
                        }
                        $optionValue = str_replace(array(';', '|'), '', (string) $optionValue);
                        $optionLabel = str_replace(array(';', '|'), '', (string) $optionLabel);
                        $values[] = $optionValue . '|' . $optionLabel;
                    }
                    $command->setConfiguration('listValue', implode(';', $values));
                } else {
                    $command->setConfiguration('listValue', '');
                }
                if ($actionSubtype === 'slider') {
                    $command->setConfiguration('minValue', isset($action['min']) && is_numeric($action['min']) ? $action['min'] : '');
                    $command->setConfiguration('maxValue', isset($action['max']) && is_numeric($action['max']) ? $action['max'] : '');
                    $command->setConfiguration('step', isset($action['step']) && is_numeric($action['step']) ? $action['step'] : '');
                } else {
                    $command->setConfiguration('minValue', '');
                    $command->setConfiguration('maxValue', '');
                    $command->setConfiguration('step', '');
                }
                $command->save();
            }
        }

        foreach ($eqLogic->getCmd('action') as $command) {
            if ((int) $command->getConfiguration('managedByLocalThings', 0) !== 1) {
                continue;
            }
            $entityKey = (string) $command->getConfiguration('entityKey', '');
            if (
                isset($processedEntityKeys[$entityKey])
                && !isset($desiredActionLogicalIds[(string) $command->getLogicalId()])
            ) {
                $command->remove();
            }
        }

        self::removeDeprecatedGeneratedCommands($eqLogic, $processedEntityKeys);

        // Some firmwares return temporary {"href": ...} stubs in /device/0.
        // Keep previously discovered commands so one partial poll cannot
        // erase a working equipment schema.
    }

    private static function generatedActionName($entityKey, $infoName, $action, $subtype)
    {
        $actionName = (string) ($action['name'] ?? $action['key'] ?? __('Action', __FILE__));
        if (in_array($subtype, array('select', 'slider'), true)) {
            return $infoName;
        }
        if (strpos((string) $entityKey, 'operational_controls_') === 0) {
            return $actionName;
        }
        $fixedValue = $action['fixed_value'] ?? null;
        if (is_bool($fixedValue)) {
            return ($fixedValue ? __('Activer', __FILE__) : __('Désactiver', __FILE__)) . ' ' . $infoName;
        }
        return $infoName . ' - ' . $actionName;
    }

    private static function removeDeprecatedGeneratedCommands($eqLogic, $processedEntityKeys)
    {
        $rules = array(
            array('current' => 'power_0_value_', 'obsolete' => 'power_vs_0_power_'),
            array('current' => 'kidslock_0_value_', 'obsolete' => 'kidslock_vs_0_kidsLock_'),
            array('current' => 'remotectrl_0_value_', 'obsolete' => 'remotectrl_vs_0_remoteControlEnabled_'),
            array('current' => 'washer_cycle_', 'obsolete' => 'course_vs_0_option_Course_'),
        );
        $obsoletePrefixes = array();
        foreach ($rules as $rule) {
            foreach (array_keys($processedEntityKeys) as $entityKey) {
                if (strpos($entityKey, $rule['current']) === 0) {
                    $obsoletePrefixes[] = $rule['obsolete'];
                    break;
                }
            }
        }
        if (count($obsoletePrefixes) === 0) {
            return;
        }
        foreach (array('action', 'info') as $type) {
            foreach ($eqLogic->getCmd($type) as $command) {
                if ((int) $command->getConfiguration('managedByLocalThings', 0) !== 1) {
                    continue;
                }
                $entityKey = (string) $command->getConfiguration('entityKey', '');
                foreach ($obsoletePrefixes as $prefix) {
                    if (strpos($entityKey, $prefix) === 0) {
                        $command->remove();
                        break;
                    }
                }
            }
        }
    }

    private static function applyStates($eqLogic, $states, $connected)
    {
        $commands = array();
        foreach ($eqLogic->getCmd('info') as $command) {
            $key = (string) $command->getConfiguration('entityKey', '');
            if ($key !== '') {
                $commands[$key] = $command;
            }
        }
        foreach ((array) $states as $key => $value) {
            if (isset($commands[$key])) {
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } elseif (is_bool($value)) {
                    $value = $value ? 1 : 0;
                }
                // Laisse le core formater la valeur et n'émettre un évènement
                // que lorsqu'elle a réellement changé.
                $eqLogic->checkAndUpdateCmd($commands[$key], $value, false);
            }
        }
        $eqLogic->checkAndUpdateCmd('connected', $connected ? 1 : 0);
    }

    public static function pollIntervalSeconds($value = null)
    {
        if ($value === null) {
            $legacy = config::byKey('poll_interval', __CLASS__, 5);
            $value = config::byKey('poll_interval_online', __CLASS__, $legacy);
        }
        $value = strtolower(trim((string) $value));
        if (preg_match('/^(\d+)s$/', $value, $matches) === 1) {
            return max(10, min(86400, (int) $matches[1]));
        }
        return max(1, min(1440, (int) $value)) * 60;
    }

    public static function pollIntervalSecondsForState($online)
    {
        $legacy = config::byKey('poll_interval', __CLASS__, 5);
        $key = $online ? 'poll_interval_online' : 'poll_interval_offline';
        $fallback = $online ? $legacy : 5;
        $value = config::byKey($key, __CLASS__, $fallback);
        if (trim((string) $value) === '') {
            $value = $fallback;
        }
        return self::pollIntervalSeconds($value);
    }

    private static function equipmentIsOnline($eqLogic)
    {
        $connected = $eqLogic->getCmd('info', 'connected');
        return is_object($connected) && (int) $connected->execCmd() === 1;
    }

    private static function pollIntervalLabel($seconds)
    {
        $seconds = max(1, (int) $seconds);
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        if ($seconds % 86400 === 0) {
            return ($seconds / 86400) . ' j';
        }
        if ($seconds % 3600 === 0) {
            return ($seconds / 3600) . ' h';
        }
        return ($seconds / 60) . ' min';
    }

    public static function poll()
    {
        $now = time();
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            $online = self::equipmentIsOnline($eqLogic);
            $interval = self::pollIntervalSecondsForState($online);
            $lastRefresh = (int) $eqLogic->getConfiguration('last_refresh', 0);
            if ($lastRefresh > 0 && $now - $lastRefresh < $interval) {
                continue;
            }
            try {
                $eqLogic->refresh();
            } catch (Exception $exception) {
                log::add(
                    __CLASS__,
                    'warning',
                    $eqLogic->getHumanName() . ' : ' . $exception->getMessage()
                );
            }
        }
    }

    public static function cron()
    {
        $pollCron = cron::byClassAndFunction(__CLASS__, 'poll');
        if (is_object($pollCron) && $pollCron->running()) {
            return;
        }
        self::poll();
    }

    public static function deamon_info()
    {
        $pollCron = cron::byClassAndFunction(__CLASS__, 'poll');
        return array(
            'log' => __CLASS__,
            'state' => is_object($pollCron) && $pollCron->running() ? 'ok' : 'nok',
            'launchable' => is_object($pollCron) ? 'ok' : 'nok',
            'launchable_message' => is_object($pollCron)
                ? ''
                : __('Tâche de rafraîchissement introuvable, réinstallez le plugin', __FILE__),
        );
    }

    public static function deamon_start()
    {
        self::deamon_stop();
        $pollCron = cron::byClassAndFunction(__CLASS__, 'poll');
        if (!is_object($pollCron)) {
            throw new RuntimeException(__('Tâche de rafraîchissement introuvable', __FILE__));
        }
        $pollCron->run();
    }

    public static function deamon_stop()
    {
        $pollCron = cron::byClassAndFunction(__CLASS__, 'poll');
        if (is_object($pollCron)) {
            $pollCron->halt();
        }
    }

    public static function health()
    {
        $dependency = self::dependancy_info();
        $transport = self::transportStatus();
        $certificate = self::certificateStatus();
        $equipment = self::byType(__CLASS__);
        $connected = 0;
        $enabled = 0;
        foreach ($equipment as $eqLogic) {
            if (!$eqLogic->getIsEnable()) {
                continue;
            }
            $enabled++;
            $command = $eqLogic->getCmd('info', 'connected');
            if (is_object($command) && (int) $command->execCmd() === 1) {
                $connected++;
            }
        }
        $certificateExpires = !empty($certificate['expires']) ? strtotime($certificate['expires']) : false;
        $certificateOk = !empty($certificate['configured'])
            && ($certificateExpires === false || $certificateExpires > time());
        $certificateResult = $certificateOk ? 'OK' : 'NOK';
        if ($certificateExpires !== false) {
            $certificateResult .= ' (' . date('Y-m-d', $certificateExpires) . ')';
        }
        $pollIntervalOnline = self::pollIntervalSecondsForState(true);
        $pollIntervalOffline = self::pollIntervalSecondsForState(false);
        $pollCron = cron::byClassAndFunction(__CLASS__, 'poll');
        $pollRunning = is_object($pollCron) && $pollCron->running();
        return array(
            array(
                'test' => __('Transport OpenSSL DTLS', __FILE__),
                'result' => strtoupper($dependency['state'])
                    . (!empty($transport['path']) ? ' - ' . $transport['path'] : ''),
                'advice' => $dependency['state'] === 'ok' ? '' : __('Relancez les dépendances', __FILE__),
                'state' => $dependency['state'] === 'ok',
            ),
            array(
                'test' => __('Fonction PHP proc_open', __FILE__),
                'result' => !empty($transport['proc_open']) ? 'OK' : 'NOK',
                'advice' => !empty($transport['proc_open']) ? '' : __('Activez proc_open dans PHP', __FILE__),
                'state' => !empty($transport['proc_open']),
            ),
            array(
                'test' => __('Certificats DTLS', __FILE__),
                'result' => $certificateResult,
                'advice' => $certificateOk ? '' : __('Configurez les certificats dans la page du plugin', __FILE__),
                'state' => $certificateOk,
            ),
            array(
                'test' => __('Appareils connectés', __FILE__),
                'result' => $connected . ' / ' . $enabled,
                'advice' => $enabled > 0 && $connected === 0
                    ? __('Testez la communication depuis la page de l’équipement', __FILE__)
                    : '',
                'state' => $connected > 0 || $enabled === 0,
            ),
            array(
                'test' => __('Rafraîchissement automatique', __FILE__),
                'result' => __('En ligne', __FILE__) . ' : '
                    . self::pollIntervalLabel($pollIntervalOnline)
                    . ' / ' . __('Hors ligne', __FILE__) . ' : '
                    . self::pollIntervalLabel($pollIntervalOffline)
                    . ' - ' . ($pollRunning ? 'OK' : __('Démon arrêté', __FILE__)),
                'advice' => $pollRunning ? '' : __('Démarrez le démon du plugin', __FILE__),
                'state' => $pollRunning,
            ),
        );
    }

    public function refresh()
    {
        $host = (string) $this->getConfiguration('host');
        $port = (int) $this->getConfiguration('port');
        if ($host === '' || $port === 0) {
            throw new RuntimeException(__('Adresse LocalThings absente', __FILE__));
        }
        try {
            $snapshot = self::deviceClient()->refresh($host, $port);
            self::registerSnapshot($snapshot);
            return $snapshot;
        } catch (Exception $exception) {
            $this->checkAndUpdateCmd('connected', 0);
            $this->setConfiguration('last_error', $exception->getMessage());
            $this->setConfiguration('last_refresh', time());
            $this->save(true);
            throw $exception;
        }
    }

    public function testCommunication()
    {
        $host = trim((string) $this->getConfiguration('host'));
        $port = (int) $this->getConfiguration('port');
        if ($host === '' || $port === 0) {
            throw new RuntimeException(__('Adresse LocalThings absente', __FILE__));
        }
        $started = microtime(true);
        try {
            $snapshot = self::deviceClient()->refresh($host, $port);
            if (!is_array($snapshot) || empty($snapshot['device'])) {
                throw new RuntimeException(__('Réponse LocalThings invalide', __FILE__));
            }
            $duration = (int) round((microtime(true) - $started) * 1000);
            $lastCommunication = date('Y-m-d H:i:s');
            $this->checkAndUpdateCmd('connected', 1);
            $this->setConfiguration('last_communication', $lastCommunication);
            $this->setConfiguration('last_error', '');
            $this->save(true);
            log::add(
                __CLASS__,
                'info',
                sprintf(
                    __('[Test] Communication réussie avec %1$s en %2$d ms', __FILE__),
                    $this->getHumanName(),
                    $duration
                )
            );
            return array(
                'success' => true,
                'duration_ms' => $duration,
                'last_communication' => $lastCommunication,
                'last_error' => '',
                'message' => __('Communication avec l’appareil réussie', __FILE__)
                    . ' (' . $duration . ' ms)',
            );
        } catch (Exception $exception) {
            $this->checkAndUpdateCmd('connected', 0);
            $this->setConfiguration('last_error', $exception->getMessage());
            $this->save(true);
            log::add(
                __CLASS__,
                'warning',
                sprintf(
                    __('[Test] Échec de communication avec %1$s : %2$s', __FILE__),
                    $this->getHumanName(),
                    $exception->getMessage()
                )
            );
            throw $exception;
        }
    }

    public function applyCommandResult($result)
    {
        if (!is_array($result)) {
            return;
        }
        if (isset($result['entities']) && is_array($result['entities'])) {
            self::syncCommands($this, $result['entities']);
        }
        self::applyStates($this, $result['states'] ?? array(), true);
        $this->setConfiguration('last_communication', date('Y-m-d H:i:s'));
        $this->setConfiguration('last_refresh', time());
        $this->setConfiguration('last_error', '');
        $this->save(true);
    }

    /**
     * Génère le widget personnalisé en organisant les widgets de commandes du
     * core dans des pages compactes selon le type de l'appareil.
     *
     * @param string $_version Version d'affichage Jeedom.
     * @return string HTML du widget.
     */
    public function toHtml($_version = 'dashboard')
    {
        if ((int) $this->getDisplay('widgetTmpl', 0) !== 1) {
            return parent::toHtml($_version);
        }

        $version = jeedom::versionAlias($_version);
        if (!in_array($version, array('dashboard', 'mobile'), true)) {
            return parent::toHtml($_version);
        }
        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }

        $profile = LocalThingsWidget::profile($this->getConfiguration('device_type', 'unknown'));
        $sections = array(
            'status' => array(),
            'settings' => array(),
            'controls' => array(),
            'maintenance' => array(),
            'energy' => array(),
            'details' => array(),
        );
        $statusCandidates = array();
        $actions = array();
        $actionEntityKeys = array();

        foreach ($this->getCmd('action') as $command) {
            if (!$command->getIsVisible()) {
                continue;
            }
            $entityKey = (string) $command->getConfiguration('entityKey', '');
            $group = LocalThingsWidget::group(
                $profile['type'],
                $entityKey,
                $command->getType(),
                $command->getSubType(),
                $command->getConfiguration('entityCategory', ''),
                $command->getName()
            );
            if ($group === 'refresh') {
                continue;
            }
            if ($group === 'hidden') {
                continue;
            }
            if (!isset($sections[$group])) {
                $group = 'controls';
            }
            $actions[] = array('command' => $command, 'group' => $group);
            if ($entityKey !== '') {
                $actionEntityKeys[$entityKey] = true;
            }
        }

        foreach ($this->getCmd('info') as $command) {
            if (!$command->getIsVisible()) {
                continue;
            }
            $entityKey = (string) $command->getConfiguration('entityKey', '');
            $group = LocalThingsWidget::group(
                $profile['type'],
                $entityKey,
                $command->getType(),
                $command->getSubType(),
                $command->getConfiguration('entityCategory', ''),
                $command->getName()
            );
            if ($group === 'hidden' || isset($actionEntityKeys[$entityKey])) {
                continue;
            }
            if (!isset($sections[$group])) {
                $group = 'details';
            }
            if ($group === 'status') {
                $statusCandidates[] = $command;
                continue;
            }
            $sections[$group][] = $command;
        }

        usort($statusCandidates, function ($left, $right) use ($profile) {
            $leftPriority = LocalThingsWidget::statusPriority(
                $profile['type'],
                $left->getConfiguration('entityKey', ''),
                $left->getName()
            );
            $rightPriority = LocalThingsWidget::statusPriority(
                $profile['type'],
                $right->getConfiguration('entityKey', ''),
                $right->getName()
            );
            if ($leftPriority === $rightPriority) {
                return (int) $left->getId() <=> (int) $right->getId();
            }
            return $leftPriority <=> $rightPriority;
        });
        $statusSlots = array();
        foreach ($statusCandidates as $command) {
            $slot = LocalThingsWidget::statusSlot(
                $command->getConfiguration('entityKey', ''),
                $command->getName()
            );
            if ($slot !== '' && count($sections['status']) < 3 && !isset($statusSlots[$slot])) {
                $sections['status'][] = $command;
                $statusSlots[$slot] = true;
            }
        }

        foreach ($actions as $item) {
            $sections[$item['group']][] = $item['command'];
        }

        $renderedSections = array();
        foreach ($sections as $section => $commands) {
            usort($commands, function ($left, $right) use ($profile, $section) {
                if ($section === 'status') {
                    $leftPriority = LocalThingsWidget::statusPriority(
                        $profile['type'],
                        $left->getConfiguration('entityKey', ''),
                        $left->getName()
                    );
                    $rightPriority = LocalThingsWidget::statusPriority(
                        $profile['type'],
                        $right->getConfiguration('entityKey', ''),
                        $right->getName()
                    );
                } else {
                    $leftPriority = LocalThingsWidget::priority(
                        $profile['type'],
                        $left->getConfiguration('entityKey', ''),
                        $left->getName()
                    );
                    $rightPriority = LocalThingsWidget::priority(
                        $profile['type'],
                        $right->getConfiguration('entityKey', ''),
                        $right->getName()
                    );
                }
                if ($leftPriority === $rightPriority) {
                    return (int) $left->getId() <=> (int) $right->getId();
                }
                return $leftPriority <=> $rightPriority;
            });
            $commands = $this->deduplicateWidgetCommands($commands, $section);
            $renderedSections[$section] = $this->renderWidgetCommands(
                $commands,
                $_version,
                $section,
                $profile['type']
            );
        }

        // preToHtml() fournit déjà l'identifiant de la commande refresh visible.
        $replace['#refresh_display#'] = $replace['#refresh_id#'] === '' ? 'none' : 'inline-block';
        $health = $this->getCmd('info', 'connected');
        $healthValue = is_object($health) ? (int) $health->execCmd() : 0;
        $replace['#health_id#'] = is_object($health) ? (int) $health->getId() : '';
        $replace['#health_display#'] = is_object($health) && $health->getIsVisible() ? 'inline-block' : 'none';
        $replace['#health_online#'] = $healthValue === 1 ? 'true' : 'false';
        $replace['#health_status#'] = htmlspecialchars(
            $healthValue === 1 ? __('En ligne', __FILE__) : __('Hors ligne', __FILE__),
            ENT_QUOTES,
            'UTF-8'
        );
        $replace['#health_icon#'] = $healthValue === 1 ? 'fas fa-link' : 'fas fa-unlink';
        $replace['#health_color#'] = $healthValue === 1 ? '#4caf50' : '#d9534f';
        $replace['#device_type#'] = htmlspecialchars($profile['type'], ENT_QUOTES, 'UTF-8');
        $replace['#settings_title#'] = htmlspecialchars($profile['settings_title'], ENT_QUOTES, 'UTF-8');
        $replace['#status_commands#'] = $renderedSections['status'];
        $replace['#settings_commands#'] = $renderedSections['settings'];
        $replace['#control_commands#'] = $renderedSections['controls'];
        $replace['#maintenance_commands#'] = $renderedSections['maintenance'];
        $replace['#energy_commands#'] = $renderedSections['energy'];
        $replace['#detail_commands#'] = $renderedSections['details'];
        foreach ($renderedSections as $section => $content) {
            $replace['#' . $section . '_display#'] = $content === ''
                ? 'none'
                : ($section === 'status' ? 'flex' : 'block');
        }

        $templateName = 'localthings.device.template';
        $template = getTemplate('core', $version, $templateName, __CLASS__);
        if (!is_string($template) || $template === '') {
            return parent::toHtml($_version);
        }
        $html = template_replace($replace, $template);
        return translate::exec(
            $html,
            'plugins/localthings/core/template/' . $version . '/' . $templateName . '.html'
        );
    }

    private function renderWidgetCommands($commands, $version, $group, $deviceType)
    {
        $rendered = '';
        $renderedToggleKeys = array();
        foreach ($commands as $command) {
            $fixedValue = json_decode((string) $command->getConfiguration('fixedValue', 'null'));
            $entityKey = (string) $command->getConfiguration('entityKey', '');
            if ($command->getType() === 'action' && is_bool($fixedValue) && $entityKey !== '') {
                if (isset($renderedToggleKeys[$entityKey])) {
                    continue;
                }
                $toggleCommands = array();
                foreach ($commands as $candidate) {
                    $candidateFixedValue = json_decode((string) $candidate->getConfiguration('fixedValue', 'null'));
                    if (
                        $candidate->getType() === 'action'
                        && is_bool($candidateFixedValue)
                        && (string) $candidate->getConfiguration('entityKey', '') === $entityKey
                    ) {
                        $toggleCommands[] = $candidate;
                    }
                }
                $rendered .= $this->renderWidgetToggleCommands(
                    $toggleCommands,
                    $version,
                    $group,
                    $deviceType
                );
                $renderedToggleKeys[$entityKey] = true;
                continue;
            }
            $rendered .= $this->renderWidgetCommand($command, $version, $group, $deviceType);
        }
        return $rendered;
    }

    private function deduplicateWidgetCommands($commands, $section)
    {
        if (!in_array($section, array('maintenance', 'energy', 'details'), true)) {
            return $commands;
        }
        $seen = array();
        $filtered = array();
        foreach ($commands as $command) {
            $entityKey = (string) $command->getConfiguration('entityKey', '');
            if ($section === 'maintenance') {
                $role = LocalThingsWidget::maintenanceRole($entityKey, $command->getName());
            } elseif ($section === 'energy') {
                $role = LocalThingsWidget::energyRole($entityKey, $command->getName());
            } else {
                $role = LocalThingsWidget::detailRole($entityKey, $command->getName());
            }
            $roleKey = $section === 'details' ? $command->getType() . ':' . $role : $role;
            if ($role !== '' && isset($seen[$roleKey]) && $seen[$roleKey] !== $entityKey) {
                continue;
            }
            if ($role !== '') {
                $seen[$roleKey] = $entityKey;
            }
            $filtered[] = $command;
        }
        return $filtered;
    }

    private function renderWidgetToggleCommands($commands, $version, $group, $deviceType)
    {
        if (count($commands) === 0) {
            return '';
        }
        $onCommand = null;
        $offCommand = null;
        foreach ($commands as $command) {
            $fixedValue = json_decode((string) $command->getConfiguration('fixedValue', 'null'));
            if ($fixedValue === true) {
                $onCommand = $command;
            } elseif ($fixedValue === false) {
                $offCommand = $command;
            }
        }
        if (is_object($onCommand) && is_object($offCommand)) {
            $stateId = (int) $onCommand->getValue();
            $stateCommand = $stateId > 0 ? cmd::byId($stateId) : null;
            $currentValue = is_object($stateCommand) ? $stateCommand->execCmd() : 0;
            $checked = in_array(
                strtolower(trim((string) $currentValue)),
                array('1', 'true', 'on', 'enable', 'enabled', 'yes'),
                true
            );
            $stateLabel = $checked ? __('Activé', __FILE__) : __('Désactivé', __FILE__);
            $html = '<label class="localthings-widget-switch">'
                . '<input type="checkbox" class="localthings-toggle-input" role="switch"'
                . ' data-on-cmd_id="' . (int) $onCommand->getId() . '"'
                . ' data-off-cmd_id="' . (int) $offCommand->getId() . '"'
                . ' data-state-cmd_id="' . $stateId . '"'
                . ' data-on-label="' . htmlspecialchars(__('Activé', __FILE__), ENT_QUOTES, 'UTF-8') . '"'
                . ' data-off-label="' . htmlspecialchars(__('Désactivé', __FILE__), ENT_QUOTES, 'UTF-8') . '"'
                . ' aria-checked="' . ($checked ? 'true' : 'false') . '"'
                . ($checked ? ' checked' : '') . '>'
                . '<span class="localthings-widget-switch-track" aria-hidden="true">'
                . '<span class="localthings-widget-switch-thumb"></span></span>'
                . '<span class="localthings-widget-switch-state">'
                . htmlspecialchars($stateLabel, ENT_QUOTES, 'UTF-8') . '</span></label>';
            return $this->renderWidgetCommandFrame(
                $onCommand,
                $html,
                $group,
                $deviceType,
                'toggle'
            );
        }
        $html = '<div class="localthings-widget-toggle-actions">';
        foreach ($commands as $command) {
            $commandHtml = $command->toHtml($version);
            if (is_string($commandHtml)) {
                $html .= $commandHtml;
            }
        }
        $html .= '</div>';
        return $this->renderWidgetCommandFrame(
            reset($commands),
            $html,
            $group,
            $deviceType,
            'toggle'
        );
    }

    private function renderWidgetCommand($command, $version, $group, $deviceType)
    {
        $entityKey = (string) $command->getConfiguration('entityKey', '');
        if (
            $group === 'maintenance'
            && $command->getType() === 'info'
            && LocalThingsWidget::maintenanceRole($entityKey, $command->getName()) === 'alarm'
        ) {
            $rawAlarm = $command->execCmd();
            $alarmSummary = (new LocalThingsMapper())->formatAlarmSummary($rawAlarm);
            if ((string) $alarmSummary !== (string) $rawAlarm) {
                // Migre immédiatement les anciennes valeurs JSON sans attendre
                // le prochain rafraîchissement complet de l'appareil.
                $this->checkAndUpdateCmd($command, $alarmSummary, false);
            }
        }
        $html = $command->toHtml($version);
        if (!is_string($html) || $html === '') {
            return '';
        }
        return $this->renderWidgetCommandFrame(
            $command,
            $html,
            $group,
            $deviceType,
            $command->getSubType()
        );
    }

    private function renderWidgetCommandFrame($command, $html, $group, $deviceType, $subType)
    {
        $subType = preg_replace('/[^a-z0-9_-]/i', '', (string) $subType);
        $entityKey = (string) $command->getConfiguration('entityKey', '');
        $statusSlot = $group === 'status'
            ? LocalThingsWidget::statusSlot($entityKey, $command->getName())
            : '';
        $isPercentage = $command->getType() === 'info'
            && $command->getSubType() === 'numeric'
            && LocalThingsWidget::isPercentageUnit($command->getUnite());
        $presentation = LocalThingsWidget::presentation(
            $deviceType,
            $entityKey,
            $command->getType(),
            $group,
            $command->getName()
        );
        $visual = '';
        if ($presentation['asset'] !== '') {
            $visual = '<span class="localthings-widget-command-icon"><img src="plugins/localthings/core/template/img/'
                . htmlspecialchars($presentation['asset'], ENT_QUOTES, 'UTF-8') . '" alt=""></span>';
        } elseif ($presentation['icon'] !== '') {
            $visual = '<span class="localthings-widget-command-icon"><i class="'
                . htmlspecialchars($presentation['icon'], ENT_QUOTES, 'UTF-8') . '"></i></span>';
        }
        $label = $presentation['label'] === ''
            ? ''
            : '<span class="localthings-widget-command-label">'
                . htmlspecialchars($presentation['label'], ENT_QUOTES, 'UTF-8') . '</span>';
        if ($isPercentage && $label === '') {
            $label = '<span class="localthings-widget-command-label">'
                . htmlspecialchars($command->getName(), ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $percentage = '';
        if ($isPercentage) {
            $percentageValue = LocalThingsWidget::percentageValue($command->execCmd());
            $percentageText = rtrim(rtrim(number_format($percentageValue, 1, '.', ''), '0'), '.');
            $percentage = '<div class="localthings-widget-percentage"'
                . ' data-cmd_id="' . (int) $command->getId() . '"'
                . ' data-value="' . $percentageText . '"'
                . ' role="progressbar" aria-valuemin="0" aria-valuemax="100"'
                . ' aria-valuenow="' . $percentageText . '">'
                . '<span class="localthings-widget-percentage-track" aria-hidden="true">'
                . '<span class="localthings-widget-percentage-value" style="width:'
                . $percentageText . '%"></span></span></div>';
        }
        $visualClass = $visual !== '' || $label !== '' || $percentage !== ''
            ? ' localthings-widget-command-presented'
            : '';
        $maintenanceClass = '';
        if ($group === 'maintenance') {
            $maintenanceRole = LocalThingsWidget::maintenanceRole($entityKey, $command->getName());
            if ($maintenanceRole !== '') {
                $maintenanceClass = ' localthings-widget-maintenance-'
                    . preg_replace('/[^a-z0-9_-]/i', '', $maintenanceRole);
            }
        }
        $commandTypeClass = ' localthings-widget-command-type-'
            . preg_replace('/[^a-z0-9_-]/i', '', (string) $command->getType());
        $statusClass = $statusSlot === '' ? '' : ' localthings-widget-status-' . $statusSlot;
        $statusAttributes = '';
        if ($statusSlot !== '') {
            $statusValue = (string) $command->execCmd();
            $statusAttributes = ' data-status-slot="' . $statusSlot . '"'
                . ' data-status-value="'
                . htmlspecialchars($statusValue, ENT_QUOTES, 'UTF-8') . '"';
            if ($statusSlot === 'state') {
                $statusAttributes .= ' data-operating="'
                    . (LocalThingsWidget::isOperatingState($statusValue) ? 'true' : 'false') . '"';
            }
        }
        return '<div class="localthings-widget-command localthings-widget-command-' . $subType
            . $visualClass . $statusClass . $maintenanceClass . $commandTypeClass
            . '" data-command-group="' . $group . '" data-cmd_id="' . (int) $command->getId() . '"'
            . $statusAttributes . '>'
            . $visual . '<div class="localthings-widget-command-content">'
            . $label . $html . $percentage . '</div></div>';
    }
}

class localthingsCmd extends cmd
{
    public function execute($_options = array())
    {
        if ($this->getType() !== 'action') {
            return null;
        }
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) {
            throw new RuntimeException(__('Équipement LocalThings introuvable', __FILE__));
        }
        if ((string) $this->getConfiguration('operation', '') === 'refresh') {
            return $eqLogic->refresh();
        }
        $connected = $eqLogic->getCmd('info', 'connected');
        if (!is_object($connected) || (int) $connected->execCmd() !== 1) {
            throw new RuntimeException(
                __('L’appareil est hors ligne. Rafraîchissez son état avant d’envoyer une commande.', __FILE__)
            );
        }
        $fixed = json_decode((string) $this->getConfiguration('fixedValue', 'null'), true);
        switch ($this->getSubType()) {
            case 'slider':
                $value = $_options['slider'] ?? null;
                break;
            case 'select':
                $value = $_options['select'] ?? null;
                break;
            case 'message':
                $value = $_options['message'] ?? ($_options['title'] ?? null);
                break;
            case 'color':
                $value = $_options['color'] ?? null;
                break;
            default:
                $value = $fixed;
                break;
        }
        $extra = json_decode((string) $this->getConfiguration('extra', '{}'), true);
        $recipe = is_array($extra) ? ($extra['recipe'] ?? null) : null;
        if (!is_array($recipe)) {
            throw new RuntimeException(__('Recette de commande LocalThings absente', __FILE__));
        }
        try {
            $result = localthings::deviceClient()->execute(
                (string) $eqLogic->getConfiguration('host'),
                (int) $eqLogic->getConfiguration('port'),
                $recipe,
                $value,
                (bool) $eqLogic->getConfiguration('bypass_remote_control', 0)
            );
            $eqLogic->applyCommandResult($result);
            return $result;
        } catch (Exception $exception) {
            if (!($exception instanceof LocalThingsCommandRejectedException)) {
                $eqLogic->checkAndUpdateCmd('connected', 0);
            }
            $eqLogic->setConfiguration('last_error', $exception->getMessage());
            $eqLogic->save(true);
            throw $exception;
        }
    }
}
