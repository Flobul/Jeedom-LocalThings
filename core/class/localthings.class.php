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
require_once __DIR__ . '/LocalThingsClient.php';

class localthings extends eqLogic
{
    public static $_pluginVersion = '0.2.1';
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
            '[Discovery] Découverte réseau demandée : ' . implode(', ', $networks)
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
            '[Discovery] Tâche PHP lancée, PID=' . (int) ($status['worker_pid'] ?? 0)
        );
        return $status;
    }

    public static function probeHost($host)
    {
        self::assertCertificates();
        log::add(
            __CLASS__,
            'info',
            '[Discovery] Ajout manuel demandé pour ' . trim((string) $host)
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
            '[Discovery] Tâche PHP manuelle lancée, PID=' . (int) ($status['worker_pid'] ?? 0)
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
                $command->setName(
                    self::reserveCommandName(
                        $nameRegistry,
                        $infoName . ' - ' . (string) ($action['name'] ?? $action['key']),
                        $actionLogicalId
                    )
                );
                $actionSubtype = (string) ($action['subtype'] ?? 'other');
                if (!in_array($actionSubtype, array('other', 'slider', 'select', 'message', 'color'), true)) {
                    $actionSubtype = 'other';
                }
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
                $commands[$key]->event($value);
            }
        }
        $eqLogic->checkAndUpdateCmd('connected', $connected ? 1 : 0);
    }

    public static function cron()
    {
        $interval = max(1, min(1440, (int) config::byKey('poll_interval', __CLASS__, 5)));
        $now = time();
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            $lastRefresh = (int) $eqLogic->getConfiguration('last_refresh', 0);
            if ($lastRefresh > 0 && $now - $lastRefresh < $interval * 60) {
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

    public static function health()
    {
        $dependency = self::dependancy_info();
        $certificate = self::certificateStatus();
        $equipment = self::byType(__CLASS__);
        $connected = 0;
        foreach ($equipment as $eqLogic) {
            $command = $eqLogic->getCmd('info', 'connected');
            if (is_object($command) && (int) $command->execCmd() === 1) {
                $connected++;
            }
        }
        return array(
            array(
                'test' => __('Transport OpenSSL DTLS', __FILE__),
                'result' => strtoupper($dependency['state']),
                'advice' => $dependency['state'] === 'ok' ? '' : __('Relancez les dépendances', __FILE__),
                'state' => $dependency['state'] === 'ok',
            ),
            array(
                'test' => __('Certificats DTLS', __FILE__),
                'result' => !empty($certificate['configured']) ? 'OK' : 'NOK',
                'advice' => !empty($certificate['configured']) ? '' : __('Configurez les certificats dans la page du plugin', __FILE__),
                'state' => !empty($certificate['configured']),
            ),
            array(
                'test' => __('Appareils connectés', __FILE__),
                'result' => $connected . ' / ' . count($equipment),
                'advice' => '',
                'state' => $connected > 0 || count($equipment) === 0,
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
