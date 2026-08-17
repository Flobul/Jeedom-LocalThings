<?php

/**
 * Converts OCF resource representations to Jeedom command metadata.
 *
 * The map deliberately keeps every non-sensitive resource readable. Writable
 * controls are only exposed for Samsung resource shapes whose POST contract
 * is known, so an unknown field never becomes a dangerous guessed action.
 */
class LocalThingsMapper
{
    private const SENSITIVE_PATTERN = '/(?:password|passwd|token|secret|credential|certificate|privatekey|accesskey)/i';

    public function deviceType($resources, $identity = array())
    {
        $types = (array) ($identity['device_types'] ?? array());
        $ocfTypes = array(
            'oic.d.airconditioner' => 'airconditioner',
            'oic.d.airpurifier' => 'air_purifier',
            'oic.d.dishwasher' => 'dishwasher',
            'oic.d.dryer' => 'dryer',
            'oic.d.oven' => 'oven',
            'oic.d.refrigerator' => 'refrigerator',
            'oic.d.washer' => 'washer',
            'x.com.st.d.stickcleaner' => 'vacuum_station',
            'x.com.st.d.steamcloset' => 'air_dresser',
        );
        foreach ($types as $type) {
            if (isset($ocfTypes[$type])) {
                return $ocfTypes[$type];
            }
        }

        $information = $resources['/information/vs/0'] ?? array();
        $model = strtoupper((string) ($information['x.com.samsung.da.modelNum'] ?? ''));
        $description = strtoupper((string) ($information['x.com.samsung.da.description'] ?? ''));
        $tokens = preg_split('/[^A-Z0-9]+/', explode('|', $model, 2)[0] . ' ' . explode('/', $description, 2)[0]);
        $tokenMap = array(
            'REF' => 'refrigerator',
            'RAC' => 'airconditioner',
            'PRAC' => 'airconditioner',
            'KRAC' => 'airconditioner',
            'WAC' => 'airconditioner',
            'FAC' => 'airconditioner',
            'CAWW' => 'airconditioner',
            'CAC' => 'airconditioner',
            'ARA' => 'airconditioner',
            'DHM' => 'dehumidifier',
            'TVTL' => 'air_purifier',
            'VTWW' => 'air_purifier',
            'AVT' => 'air_purifier',
            'AIR' => 'air_purifier',
            'WATERPURIFIER' => 'water_purifier',
            'ADW' => 'dishwasher',
            'AHD' => 'range_hood',
            'RANGE' => 'range',
            'OVEN' => 'oven',
            'MICROWAVE' => 'microwave',
            'COOKTOP' => 'induction_cooktop',
            'CT' => 'cooktop',
            'VSKR' => 'vacuum_station',
            'VSWW' => 'vacuum_station',
            'DF' => 'air_dresser',
        );
        if (in_array('REF', $tokens, true) && in_array('WATERPURIFIER', $tokens, true)) {
            return 'water_purifier';
        }
        foreach ($tokens as $token) {
            if (isset($tokenMap[$token])) {
                return $tokenMap[$token];
            }
        }
        foreach (array_reverse(explode('_', explode('/', $description, 2)[0])) as $segment) {
            $prefix = substr($segment, 0, 2);
            if (in_array($prefix, array('WW', 'WD', 'WF', 'WV', 'WA'), true)) {
                return 'washer';
            }
            if ($prefix === 'DV') {
                return 'dryer';
            }
            if ($prefix === 'DW') {
                return 'dishwasher';
            }
        }
        if (isset($resources['/hood/fanspeed/vs/0'])) {
            return 'range_hood';
        }
        if (isset($resources['/oven/vs/0']) && isset($resources['/cooktop/status/vs/0'])) {
            return 'range';
        }
        if (isset($resources['/oven/vs/0'])) {
            return 'range';
        }
        $modeOptions = (array) (($resources['/mode/vs/0']['x.com.samsung.da.options'] ?? array()));
        $operationStates = 0;
        $hasDeviceType = false;
        foreach ($modeOptions as $option) {
            if (!is_string($option)) {
                continue;
            }
            $hasDeviceType = $hasDeviceType || strpos($option, 'DeviceType_') === 0;
            if (strpos($option, 'OperationState') === 0) {
                $operationStates++;
            }
        }
        if ($hasDeviceType && $operationStates >= 2) {
            return 'cooktop';
        }
        if (isset($resources['/washer/vs/0'])) {
            return 'washer';
        }
        if (isset($resources['/dryer/vs/0'])) {
            return 'dryer';
        }
        if (isset($resources['/refrigeration/vs/0']) || isset($resources['/icemaker/vs/0'])) {
            return 'refrigerator';
        }
        if (isset($resources['/wind/strength/vs/0']) || isset($resources['/airflow/0'])) {
            return isset($resources['/temperature/desired/0']) ? 'airconditioner' : 'air_purifier';
        }
        return 'unknown';
    }

    public function map($resources)
    {
        $entities = array();
        $states = array();
        foreach ($resources as $href => $representation) {
            if (!is_string($href) || !is_array($representation)) {
                continue;
            }
            foreach ($representation as $field => $rawValue) {
                if (!$this->includeField($field) || $this->skipFallbackField($href, $field, $resources)) {
                    continue;
                }
                $key = $this->entityKey($href, $field);
                $value = $this->displayValue($href, $field, $rawValue, $representation);
                $actions = $this->actionsFor(
                    $href,
                    $field,
                    $rawValue,
                    $representation,
                    $resources
                );
                foreach ($actions as &$action) {
                    $action['target'] = $key;
                }
                unset($action);
                $entity = array(
                    'key' => $key,
                    'name' => $this->humanName($href, $field),
                    'platform' => $this->platform($href, $field, $rawValue, $actions),
                    'type' => 'info',
                    'subtype' => $this->subtype($href, $field, $rawValue, $actions),
                    'unit' => $this->unit($href, $field, $representation, $rawValue),
                    'category' => $this->category($href, $field),
                    'value' => $value,
                    'options' => $this->optionsFor($field, $rawValue, $representation),
                    'actions' => $actions,
                );
                $entities[] = $entity;
                $states[$key] = $value;
            }
        }
        $this->appendOptionEntities($resources, $entities, $states);
        $this->appendCycleEntity($resources, $entities, $states);
        $this->appendOperationalEntities($resources, $entities, $states);
        $this->appendMaintenanceSummary($resources, $entities, $states);
        $this->ensureUniqueEntityNames($entities);
        return array('entities' => $entities, 'states' => $states);
    }

    public function buildWrite($recipe, $value, $resources)
    {
        if (!is_array($recipe) || empty($recipe['href'])) {
            throw new InvalidArgumentException(__('Recette d’écriture LocalThings invalide', __FILE__));
        }
        $href = (string) $recipe['href'];
        $field = (string) ($recipe['field'] ?? '');
        $encoding = (string) ($recipe['encoding'] ?? 'same');
        $representation = $resources[$href] ?? array();

        switch ($encoding) {
            case 'bool':
                $bodyValue = $this->toBoolean($value);
                break;
            case 'on_off':
                $bodyValue = $this->toBoolean($value) ? 'On' : 'Off';
                break;
            case 'enable_ready':
                $bodyValue = $this->toBoolean($value) ? 'Enable' : 'Ready';
                break;
            case 'lower_on_off':
                $bodyValue = $this->toBoolean($value) ? 'on' : 'off';
                break;
            case 'mode_list':
                $bodyValue = array($value);
                break;
            case 'integer':
                if (!is_numeric($value)) {
                    throw new InvalidArgumentException(__('Valeur numérique invalide', __FILE__));
                }
                $bodyValue = (int) $value;
                break;
            case 'number':
                if (!is_numeric($value)) {
                    throw new InvalidArgumentException(__('Valeur numérique invalide', __FILE__));
                }
                $bodyValue = $value + 0;
                break;
            case 'delay_hours':
                if (!is_numeric($value)) {
                    throw new InvalidArgumentException(__('Durée invalide', __FILE__));
                }
                $minutes = (int) round(max(0, (float) $value) * 60);
                $bodyValue = sprintf('%d:%02d:00', intdiv($minutes, 60), $minutes % 60);
                if (array_key_exists('x.com.samsung.da.delayEndTime', $representation)) {
                    $field = 'x.com.samsung.da.delayEndTime';
                }
                break;
            case 'option_on_off':
                $bodyValue = array(
                    (string) ($recipe['prefix'] ?? '')
                    . '_'
                    . ($this->toBoolean($value) ? 'On' : 'Off')
                );
                break;
            case 'option_token':
                $bodyValue = array(
                    (string) ($recipe['prefix'] ?? '') . '_' . (string) $value
                );
                break;
            case 'fixed':
                $bodyValue = $recipe['fixed'] ?? null;
                break;
            case 'same':
            default:
                $bodyValue = $value;
                break;
        }
        if ($field === '') {
            throw new InvalidArgumentException(__('Champ d’écriture LocalThings absent', __FILE__));
        }
        return array(
            'path' => array_values(array_filter(explode('/', trim($href, '/')), 'strlen')),
            'body' => array($field => $bodyValue),
        );
    }

    public function remoteControlEnabled($resources)
    {
        if (isset($resources['/remotectrl/0'])) {
            return $this->toBoolean($resources['/remotectrl/0']['value'] ?? false);
        }
        if (isset($resources['/remotectrl/vs/0'])) {
            return $this->toBoolean(
                $resources['/remotectrl/vs/0']['x.com.samsung.da.remoteControlEnabled'] ?? false
            );
        }
        return true;
    }

    private function actionsFor($href, $field, $value, $representation, $resources)
    {
        $actions = array();
        $recipe = array('href' => $href, 'field' => $field);

        if ($href === '/power/0' && $field === 'value') {
            if (!$this->modelAllowsPowerOnOff($resources)) {
                return array();
            }
            $recipe['encoding'] = 'bool';
            return $this->switchActions($recipe);
        }
        if ($href === '/power/vs/0' && $field === 'x.com.samsung.da.power') {
            if (isset($resources['/power/0']) || !$this->modelAllowsPowerOnOff($resources)) {
                return array();
            }
            $recipe['encoding'] = 'on_off';
            return $this->switchActions($recipe);
        }
        if ($href === '/kidslock/0' && $field === 'value') {
            $recipe['encoding'] = 'bool';
            return $this->switchActions($recipe);
        }
        if ($href === '/kidslock/vs/0' && $field === 'x.com.samsung.da.kidsLock') {
            if (isset($resources['/kidslock/0'])) {
                return array();
            }
            $recipe['encoding'] = 'enable_ready';
            return $this->switchActions($recipe);
        }

        $knownSwitches = array(
            'x.com.samsung.da.wrinklePrevent',
            'x.com.samsung.da.sanitize',
            'x.com.samsung.da.rapidFridge',
            'x.com.samsung.da.rapidFreezing',
            'x.com.samsung.da.autofill',
            'x.com.samsung.da.sabbathMode',
            'x.com.samsung.da.displayLight',
            'x.com.samsung.da.filterRemind',
            'x.com.samsung.da.remindBeep',
            'x.com.samsung.da.nightLight',
        );
        if (in_array($field, $knownSwitches, true)) {
            $recipe['encoding'] = 'on_off';
            return $this->switchActions($recipe);
        }

        $options = $this->optionsFor($field, $value, $representation);
        if (count($options) > 0 && $this->isWritableOptionField($href, $field)) {
            $recipe['encoding'] = is_array($value) && strpos($href, '/mode/') !== false
                ? 'mode_list'
                : 'same';
            $actions[] = $this->valueAction(
                'set',
                __('Choisir', __FILE__),
                'select',
                $recipe,
                $this->labelOptions($field, $options)
            );
            return $actions;
        }

        $range = $this->rangeFor($field, $representation);
        if ($range !== null && is_numeric($value) && $this->isWritableNumberField($href, $field)) {
            $recipe['encoding'] = is_int($value) ? 'integer' : 'number';
            $action = $this->valueAction('set', __('Régler', __FILE__), 'slider', $recipe);
            $action['min'] = $range[0];
            $action['max'] = $range[1];
            $action['step'] = $range[2];
            $action['unit'] = $this->unit($href, $field, $representation, $value);
            $actions[] = $action;
        }
        return $actions;
    }

    private function modelAllowsPowerOnOff($resources)
    {
        $setInfo = $resources['/wm/setinfo/vs/0'] ?? null;
        if (!is_array($setInfo)) {
            return true;
        }
        $field = 'x.com.samsung.da.isModelSettingPowerOnOff';
        if (!array_key_exists($field, $setInfo)) {
            return true;
        }
        return strtolower(trim((string) $setInfo[$field])) !== 'false';
    }

    private function appendOperationalEntities($resources, &$entities, &$states)
    {
        $href = '/operational/state/vs/0';
        $rep = $resources[$href] ?? null;
        if (!is_array($rep)) {
            return;
        }
        $stateField = 'x.com.samsung.da.state';
        if (!array_key_exists($stateField, $rep)) {
            return;
        }
        $key = 'operational_controls_' . substr(sha1($href), 0, 8);
        $current = (string) $rep[$stateField];
        $currentDisplay = $this->operationalStateLabel($current);
        $actions = array();
        foreach (array(
            'Run' => __('Démarrer', __FILE__),
            'Pause' => __('Pause', __FILE__),
            'Ready' => __('Arrêter', __FILE__),
        ) as $fixed => $name) {
            $recipe = array(
                'href' => $href,
                'field' => $stateField,
                'encoding' => 'fixed',
                'fixed' => $fixed,
            );
            $actions[] = $this->fixedAction(strtolower($fixed), $name, $recipe);
        }
        $entities[] = array(
            'key' => $key,
            'name' => __('Commandes du cycle', __FILE__),
            'platform' => 'button',
            'type' => 'info',
            'subtype' => 'string',
            'unit' => '',
            'category' => '',
            'value' => $currentDisplay,
            'options' => array(),
            'actions' => $actions,
        );
        $states[$key] = $currentDisplay;

        $delayField = array_key_exists('x.com.samsung.da.delayEndTime', $rep)
            ? 'x.com.samsung.da.delayEndTime'
            : 'x.com.samsung.da.delayStartTime';
        if (array_key_exists($delayField, $rep)) {
            $delayKey = 'delay_start_hours_' . substr(sha1($href), 0, 8);
            $hours = $this->durationHours($rep[$delayField]);
            $recipe = array(
                'href' => $href,
                'field' => $delayField,
                'encoding' => 'delay_hours',
            );
            $action = $this->valueAction('set', __('Régler', __FILE__), 'slider', $recipe);
            $action['min'] = 0;
            $action['max'] = 24;
            $action['step'] = 1;
            $action['unit'] = 'h';
            $entities[] = array(
                'key' => $delayKey,
                'name' => __('Fin différée', __FILE__),
                'platform' => 'number',
                'type' => 'info',
                'subtype' => 'numeric',
                'unit' => 'h',
                'category' => '',
                'value' => $hours,
                'options' => array(),
                'actions' => array($action),
            );
            $states[$delayKey] = $hours;
        }
    }

    private function appendOptionEntities($resources, &$entities, &$states)
    {
        $writableSwitches = array(
            'UpperLamp',
            'Sound',
            'fastpreheat',
            'NaturalSteam',
            'EnergySaving',
            'BurnerOnAlert',
            'Spi',
            'Autoclean',
            'AirMonitoring',
            'StormWashZone',
            'AutoDoorRelease',
            'BubbleSoak',
            'AddWash',
            'PreWashSetting',
            'IntensiveSetting',
        );
        foreach ($resources as $href => $representation) {
            if (!is_array($representation)) {
                continue;
            }
            foreach ($representation as $field => $tokens) {
                if (!$this->endsWith((string) $field, '.options') || !is_array($tokens)) {
                    continue;
                }
                $seen = array();
                foreach ($tokens as $token) {
                    if (!is_string($token) || strpos($token, '_') === false) {
                        continue;
                    }
                    list($prefix, $value) = explode('_', $token, 2);
                    if ($prefix === '' || $value === '') {
                        continue;
                    }
                    if ($prefix === 'Course') {
                        continue;
                    }
                    if (isset($seen[$prefix])) {
                        continue;
                    }
                    $seen[$prefix] = true;
                    $key = $this->entityKey($href, 'option:' . $prefix);
                    $actions = array();
                    if (
                        in_array($prefix, $writableSwitches, true)
                        && in_array($value, array('On', 'Off'), true)
                    ) {
                        $recipe = array(
                            'href' => $href,
                            'field' => $field,
                            'encoding' => 'option_on_off',
                            'prefix' => $prefix,
                        );
                        $actions = $this->switchActions($recipe);
                    }
                    $isReadOnlyAlarm = in_array(
                        strtolower($prefix),
                        array('detergentalarm', 'softeneralarm'),
                        true
                    ) && in_array($value, array('On', 'Off'), true);
                    $entities[] = array(
                        'key' => $key,
                        'name' => $this->optionName($prefix),
                        'platform' => count($actions) > 0 ? 'switch' : 'sensor',
                        'type' => 'info',
                        'subtype' => count($actions) > 0
                            ? 'binary'
                            : ($isReadOnlyAlarm ? 'string' : (is_numeric($value) ? 'numeric' : 'string')),
                        'unit' => $prefix === 'EnergyKW' ? 'Wh' : '',
                        'category' => '',
                        'value' => count($actions) > 0
                            ? ($value === 'On' ? 1 : 0)
                            : ($isReadOnlyAlarm
                                ? ($value === 'On' ? __('Active', __FILE__) : __('Inactive', __FILE__))
                                : $value),
                        'options' => array(),
                        'actions' => $actions,
                    );
                    $states[$key] = count($actions) > 0
                        ? ($value === 'On' ? 1 : 0)
                        : ($isReadOnlyAlarm
                            ? ($value === 'On' ? __('Active', __FILE__) : __('Inactive', __FILE__))
                            : $value);
                }
            }
        }
    }

    private function appendMaintenanceSummary($resources, &$entities, &$states)
    {
        $proposal = null;
        $washingTimes = null;
        foreach ($resources as $representation) {
            if (!is_array($representation)) {
                continue;
            }
            foreach ($representation as $field => $value) {
                if (!is_numeric($value)) {
                    continue;
                }
                $normalized = strtolower((string) $field);
                if (strpos($normalized, 'drumcleanproposal') !== false) {
                    $proposal = (int) $value;
                } elseif (strpos($normalized, 'washingtimes') !== false) {
                    $washingTimes = (int) $value;
                }
            }
        }
        if ($proposal === null || $washingTimes === null || $proposal <= 0) {
            return;
        }

        $key = 'maintenance_drum_clean_status';
        $value = $washingTimes >= $proposal
            ? __('Nettoyage recommandé', __FILE__)
            : __('Aucun nettoyage nécessaire', __FILE__);
        $entities[] = array(
            'key' => $key,
            'name' => __('Nettoyage du tambour', __FILE__),
            'platform' => 'sensor',
            'type' => 'info',
            'subtype' => 'string',
            'unit' => '',
            'category' => 'maintenance',
            'value' => $value,
            'options' => array(),
            'actions' => array(),
        );
        $states[$key] = $value;
    }

    private function appendCycleEntity($resources, &$entities, &$states)
    {
        $href = '/course/vs/0';
        $representation = $resources[$href] ?? null;
        if (!is_array($representation)) {
            return;
        }
        $field = 'x.com.samsung.da.options';
        $current = $this->optionValue($representation[$field] ?? array(), 'Course');
        if ($current === null || $current === '') {
            return;
        }
        $current = strtoupper($current);
        $codes = $this->cycleOptions($resources, $current);
        $selectable = count($codes) > 0;
        if (!in_array($current, $codes, true)) {
            $codes[] = $current;
        }
        $table = $this->courseTable($resources);
        $options = array();
        foreach ($codes as $code) {
            $options[] = array(
                'value' => $code,
                'label' => $this->courseLabel($table, $code),
            );
        }

        $cycleType = isset($resources['/st/dryercourse/vs/0']) ? 'dryer' : 'washer';
        $key = $cycleType . '_cycle_' . substr(sha1($href), 0, 8);
        $actions = array();
        if ($selectable) {
            $recipe = array(
                'href' => $href,
                'field' => $field,
                'encoding' => 'option_token',
                'prefix' => 'Course',
            );
            $actions[] = $this->valueAction(
                'set',
                __('Choisir', __FILE__),
                'select',
                $recipe,
                $options
            );
            $actions[0]['target'] = $key;
        }
        $entities[] = array(
            'key' => $key,
            'name' => __('Cycle', __FILE__),
            'platform' => 'select',
            'type' => 'info',
            'subtype' => 'string',
            'unit' => '',
            'category' => '',
            'value' => $current,
            'options' => $options,
            'actions' => $actions,
        );
        $states[$key] = $current;
    }

    private function cycleOptions($resources, $current)
    {
        $edit = $resources['/wm/editcourse/vs/0']['x.com.samsung.da.editCourseList'] ?? null;
        if (is_string($edit) && strpos($edit, '_') !== false) {
            $hex = strtoupper(explode('_', $edit, 2)[1]);
            if ($hex !== '' && strlen($hex) % 2 === 0 && ctype_xdigit($hex)) {
                return array_values(array_unique(str_split($hex, 2)));
            }
        }

        $course = $resources['/course/vs/0'] ?? array();
        $raw = $course['x.com.samsung.da.supportedOptions'] ?? null;
        if (is_array($raw)) {
            $raw = reset($raw);
        }
        if (!is_string($raw) || strlen($raw) < 3) {
            return array();
        }
        $body = strtoupper(substr($raw, 1));
        if ($body === '' || strlen($body) % 2 !== 0 || !ctype_xdigit($body)) {
            return array();
        }
        $totalBytes = (int) (strlen($body) / 2);
        for ($width = 1; $width <= $totalBytes; $width++) {
            if ($totalBytes % $width !== 0) {
                continue;
            }
            $count = (int) ($totalBytes / $width);
            if ($count < 2) {
                continue;
            }
            $codes = array();
            for ($index = 0; $index < $count; $index++) {
                $codes[] = substr($body, $index * $width * 2, 2);
            }
            if (count(array_unique($codes)) !== count($codes)) {
                continue;
            }
            if ($current !== null && !in_array(strtoupper($current), $codes, true)) {
                continue;
            }
            return $codes;
        }
        return array();
    }

    private function optionValue($options, $prefix)
    {
        foreach ((array) $options as $option) {
            if (!is_string($option) || strpos($option, $prefix . '_') !== 0) {
                continue;
            }
            return explode('_', $option, 2)[1];
        }
        return null;
    }

    private function courseTable($resources)
    {
        foreach (array('/st/washercourse/vs/0', '/st/dryercourse/vs/0') as $href) {
            $table = $resources[$href]['x.com.samsung.da.st.courseTable'] ?? null;
            if (is_string($table) && $table !== '') {
                return $table;
            }
        }
        return '';
    }

    private function courseLabel($table, $code)
    {
        $tables = array(
            'Table_00' => array(
                'BA' => __('Vidange / essorage', __FILE__), 'D0' => __('Coton', __FILE__), 'D1' => __('eCoton', __FILE__),
                'D2' => __('Synthétiques', __FILE__), 'D3' => __('Délicat', __FILE__), 'D4' => __('Rinçage + essorage', __FILE__),
                'D5' => __('Nettoyage tambour', __FILE__), 'D6' => __('Draps', __FILE__), 'D7' => __('Imperméable', __FILE__),
                'D8' => __('Laine', __FILE__), 'D9' => __('Couleurs', __FILE__), 'DA' => __('Eco', __FILE__), 'DB' => __('Super rapide', __FILE__),
                'DC' => __('Express 15 min', __FILE__), '5B' => __('Coton', __FILE__), '5C' => __('Super rapide', __FILE__),
                '5D' => __('Eco', __FILE__), '5E' => __('Délicat', __FILE__), '5F' => __('Bébé coton', __FILE__),
                '60' => __('Imperméable', __FILE__), '61' => __('Couleurs', __FILE__), '63' => __('Nettoyage tambour', __FILE__),
                '64' => __('Rinçage + essorage', __FILE__), '65' => __('Laine', __FILE__), '66' => __('Draps', __FILE__),
                '67' => __('Synthétiques', __FILE__), '68' => __('eCoton', __FILE__), '6C' => __('Jeans', __FILE__),
            ),
            'Table_02' => array(
                '01' => __('Normal', __FILE__), '04' => __('Lavage rapide', __FILE__), '17' => __('Téléchargé', __FILE__),
                '1B' => __('Coton', __FILE__), '1C' => __('Eco 40-60', __FILE__), '1D' => __('Super rapide', __FILE__),
                '1E' => __('Express 15 min', __FILE__), '1F' => __('Intensif à froid', __FILE__),
                '20' => __('Anti-allergènes', __FILE__), '21' => __('Couleurs', __FILE__), '22' => __('Laine', __FILE__),
                '23' => __('Extérieur', __FILE__), '24' => __('Serviettes', __FILE__), '25' => __('Synthétiques', __FILE__),
                '26' => __('Délicat', __FILE__), '27' => __('Rinçage + essorage', __FILE__),
                '28' => __('Vidange / essorage', __FILE__), '29' => __('Nettoyage tambour+', __FILE__),
                '2A' => __('Jeans', __FILE__), '2B' => __('Lavage IA', __FILE__), '2D' => __('Lavage silencieux', __FILE__),
                '2E' => __('Bébé coton', __FILE__), '2F' => __('Sport', __FILE__), '30' => __('Journée nuageuse', __FILE__),
                '32' => __('Chemises', __FILE__), '33' => __('Draps', __FILE__), '34' => __('Mix', __FILE__),
                '36' => __('Lavage + séchage', __FILE__), '37' => __('Air Wash', __FILE__),
                '38' => __('Séchage coton', __FILE__), '39' => __('Séchage synthétiques', __FILE__),
                '3A' => __('Nettoyage tambour', __FILE__), '52' => __('Eco à froid', __FILE__),
                '53' => __('Intensif', __FILE__), '54' => __('Serviettes', __FILE__), '55' => __('Sport', __FILE__),
                '57' => __('Délicat', __FILE__), '5E' => __('Rinçage + essorage', __FILE__),
                '60' => __('Auto-nettoyage+', __FILE__), '65' => __('Couleurs', __FILE__), '66' => __('Jeans', __FILE__),
                '7C' => __('Blanc', __FILE__), '7D' => __('Draps / Imperméable', __FILE__),
                '7E' => __('Auto-nettoyage', __FILE__), '7F' => __('Laine / Délicat', __FILE__),
                '86' => __('Lavage en profondeur', __FILE__), '87' => __('Téléchargé', __FILE__),
                '8F' => __('Intensif à froid', __FILE__), '96' => __('Moins de microfibres', __FILE__),
            ),
            'Table_03' => array(
                '16' => __('Coton', __FILE__), '17' => __('Super rapide', __FILE__), '18' => __('Synthétiques', __FILE__),
                '19' => __('Délicat', __FILE__), '1A' => __('Laine', __FILE__), '1B' => __('Draps', __FILE__),
                '1C' => __('Chemises', __FILE__), '1D' => __('Serviettes', __FILE__), '1E' => __('Vêtements de sport', __FILE__),
                '1F' => __('Mix', __FILE__), '20' => __('Prêt à repasser', __FILE__), '21' => __('Anti-allergènes', __FILE__),
                '23' => __('Séchage rapide 35 min', __FILE__), '24' => __('Air froid', __FILE__),
                '25' => __('Air chaud', __FILE__), '26' => __('Air Wash', __FILE__), '27' => __('Minuterie', __FILE__),
            ),
        );
        $code = strtoupper((string) $code);
        return $tables[$table][$code] ?? $code;
    }

    private function switchActions($recipe)
    {
        return array(
            $this->fixedAction('on', __('Allumer', __FILE__), array_merge($recipe, array('fixed_input' => true)), true),
            $this->fixedAction('off', __('Éteindre', __FILE__), array_merge($recipe, array('fixed_input' => false)), false),
        );
    }

    private function fixedAction($key, $name, $recipe, $value = null)
    {
        return array(
            'key' => $key,
            'name' => $name,
            'subtype' => 'other',
            'operation' => 'write',
            'kind' => '',
            'fixed_value' => array_key_exists('fixed_input', $recipe) ? $recipe['fixed_input'] : $value,
            'extra' => array('recipe' => $recipe),
        );
    }

    private function valueAction($key, $name, $subtype, $recipe, $options = array())
    {
        return array(
            'key' => $key,
            'name' => $name,
            'subtype' => $subtype,
            'operation' => 'write',
            'kind' => '',
            'options' => array_values($options),
            'min' => null,
            'max' => null,
            'step' => null,
            'unit' => '',
            'extra' => array('recipe' => $recipe),
        );
    }

    private function optionsFor($field, $value, $representation)
    {
        $candidates = array();
        if ($this->endsWith($field, '.modes')) {
            $candidates[] = substr($field, 0, -6) . '.supportedModes';
        }
        if ($field === 'mode') {
            $candidates[] = 'supportedModes';
            $candidates[] = 'modes';
        }
        if ($field === 'speed') {
            $candidates[] = 'supportedSpeeds';
        }
        foreach ($representation as $candidate => $candidateValue) {
            if (
                stripos((string) $candidate, 'supported') !== false
                && is_array($candidateValue)
                && $this->similarField($field, $candidate)
            ) {
                $candidates[] = $candidate;
            }
        }
        foreach (array_unique($candidates) as $candidate) {
            $options = $representation[$candidate] ?? null;
            if (is_array($options)) {
                return array_values(array_filter($options, 'is_scalar'));
            }
        }
        return array();
    }

    private function labelOptions($field, $options)
    {
        $result = array();
        foreach ($options as $option) {
            if (!is_scalar($option)) {
                continue;
            }
            $value = (string) $option;
            $result[] = array(
                'value' => $value,
                'label' => $this->optionLabel($field, $value),
            );
        }
        return $result;
    }

    private function optionLabel($field, $value)
    {
        $normalized = strtolower((string) $value);
        if (stripos($field, 'rinseCycles') !== false && ctype_digit((string) $value)) {
            $count = (int) $value;
            return $count . ' ' . ($count > 1
                ? __('rinçages', __FILE__)
                : __('rinçage', __FILE__));
        }
        if (stripos($field, 'spinLevel') !== false && is_numeric($value)) {
            return $value . ' tr/min';
        }
        if (stripos($field, 'waterTemperature') !== false && is_numeric($value)) {
            return $value . ' °C';
        }
        $labels = array(
            'none' => __('Aucun', __FILE__),
            'auto' => __('Automatique', __FILE__),
            'automatic' => __('Automatique', __FILE__),
            'on' => __('Activé', __FILE__),
            'off' => __('Désactivé', __FILE__),
            'enabled' => __('Activé', __FILE__),
            'disabled' => __('Désactivé', __FILE__),
            'normal' => __('Normal', __FILE__),
            'eco' => __('Éco', __FILE__),
            'heat' => __('Chauffage', __FILE__),
            'fan' => __('Ventilation', __FILE__),
            'dry' => __('Déshumidification', __FILE__),
            'nospin' => __('Sans essorage', __FILE__),
            'rinsehold' => __('Arrêt cuve pleine', __FILE__),
            'extralow' => __('Très faible', __FILE__),
            'low' => __('Faible', __FILE__),
            'medium' => __('Moyen', __FILE__),
            'high' => __('Élevé', __FILE__),
            'extrahigh' => __('Très élevé', __FILE__),
            'delicate' => __('Délicat', __FILE__),
            'tapcold' => __('Eau froide', __FILE__),
            'cold' => __('Froid', __FILE__),
            'cool' => __('Frais', __FILE__),
            'mediumlow' => __('Froid à tiède', __FILE__),
            'semihot' => __('Tiède', __FILE__),
            'warm' => __('Chaud', __FILE__),
            'hot' => __('Très chaud', __FILE__),
            'extrahot' => __('Très chaud', __FILE__),
        );
        return $labels[$normalized] ?? (string) $value;
    }

    private function rangeFor($field, $representation)
    {
        foreach (array('range', $field . 'Range', 'x.com.samsung.da.range') as $rangeField) {
            $range = $representation[$rangeField] ?? null;
            if (is_array($range) && count($range) >= 2 && is_numeric($range[0]) && is_numeric($range[1])) {
                $step = 1;
                foreach (array('increment', 'step', 'x.com.samsung.da.increment') as $stepField) {
                    if (isset($representation[$stepField]) && is_numeric($representation[$stepField])) {
                        $step = $representation[$stepField] + 0;
                        break;
                    }
                }
                return array($range[0] + 0, $range[1] + 0, $step);
            }
        }
        return null;
    }

    private function isWritableOptionField($href, $field)
    {
        if (
            $href === '/washer/vs/0'
            && in_array(
                $field,
                array(
                    'x.com.samsung.da.waterTemperature',
                    'x.com.samsung.da.spinLevel',
                    'x.com.samsung.da.rinseCycles',
                    'x.com.samsung.da.dryLevel',
                ),
                true
            )
        ) {
            return true;
        }
        return strpos($field, 'modes') !== false
            || in_array($field, array('mode', 'speed', 'aiLevel', 'roomDesiredMode'), true)
            || strpos($href, '/hood/fanspeed/') !== false
            || strpos($href, '/specialzone/') !== false;
    }

    private function isWritableNumberField($href, $field)
    {
        return strpos($href, '/temperature/desired/') !== false
            || preg_match('/(?:desired|target|setpoint|brightness|level|humidity)/i', $field) === 1;
    }

    private function includeField($field)
    {
        if (!is_string($field) || $field === '' || preg_match(self::SENSITIVE_PATTERN, $field)) {
            return false;
        }
        if ($this->endsWith($field, '.options') || stripos($field, 'supportedModes') !== false) {
            return false;
        }
        return !in_array($field, array('href', 'rt', 'if', 'p', 'eps', 'range'), true);
    }

    private function skipFallbackField($href, $field, $resources)
    {
        $fallbacks = array(
            '/power/vs/0' => array('x.com.samsung.da.power', '/power/0'),
            '/kidslock/vs/0' => array('x.com.samsung.da.kidsLock', '/kidslock/0'),
            '/remotectrl/vs/0' => array('x.com.samsung.da.remoteControlEnabled', '/remotectrl/0'),
        );
        if (!isset($fallbacks[$href])) {
            return false;
        }
        return $field === $fallbacks[$href][0] && isset($resources[$fallbacks[$href][1]]);
    }

    private function displayValue($href, $field, $value, $representation = array())
    {
        if (
            preg_match('#/alarms?/(?:vs/)?\d+$#i', $href)
            && preg_match('/(?:^|\.)items$/i', $field)
        ) {
            return $this->formatAlarmSummary($value);
        }
        if (
            is_scalar($value)
            && strpos($href, '/operational/state/') !== false
            && preg_match('/(?:^|\.)state$/i', $field)
        ) {
            return $this->operationalStateLabel($value);
        }
        if (is_numeric($value) && preg_match('/cumulative(?:Power|Consumption)$/i', $field)) {
            $sourceUnit = $this->explicitUnit($field, $representation);
            if ($sourceUnit === 'kWh') {
                return (float) $value;
            }
            if ($sourceUnit === 'MWh') {
                return round(((float) $value) * 1000, 3);
            }
            if ($sourceUnit === 'J') {
                return round(((float) $value) / 3600000, 3);
            }
            if ($sourceUnit === '' || $sourceUnit === 'Wh') {
                return round(((float) $value) / 1000, 3);
            }
            return (float) $value;
        }
        if (is_numeric($value) && preg_match('/instantaneousPower$/i', $field)) {
            return max(0.0, (float) $value);
        }
        if ($this->isBinaryField($href, $field, $value)) {
            return $this->toBoolean($value) ? 1 : 0;
        }
        if (is_array($value) && count($value) === 1 && $this->endsWith($field, '.modes')) {
            return reset($value);
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_string($value)) {
            return $this->translatedValue($value);
        }
        return $value;
    }

    /**
     * Samsung exposes /alarms/vs/0 on several appliance families. Rows kept
     * as Deleted and placeholder codes ending in _OFF are inactive; this is
     * the same cross-family rule used by mbillow/localthings common.ALARMS.
     */
    public function formatAlarmSummary($items)
    {
        if (is_string($items)) {
            $raw = trim($items);
            if ($raw !== '' && !in_array($raw[0], array('[', '{', '"'), true)) {
                return $raw;
            }
        }
        $items = $this->decodedAlarmItems($items);
        if ($items === null) {
            return __('Alarme non interprétable', __FILE__);
        }
        $active = array();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = trim((string) (
                $item['x.com.samsung.da.code']
                ?? $item['code']
                ?? ''
            ));
            $state = strtolower(trim((string) (
                $item['x.com.samsung.da.state']
                ?? $item['state']
                ?? ''
            )));
            if (
                $code === ''
                || $state === 'deleted'
                || preg_match('/_off$/i', $code)
            ) {
                continue;
            }
            $summary = $this->alarmCodeLabel($code);
            $triggeredAt = trim((string) (
                $item['x.com.samsung.da.triggeredTime']
                ?? $item['triggeredTime']
                ?? ''
            ));
            $triggeredAt = $this->alarmTimeLabel($triggeredAt);
            if ($triggeredAt !== '') {
                $summary = sprintf(
                    __('%1$s — déclenchée le %2$s', __FILE__),
                    $summary,
                    $triggeredAt
                );
            }
            $active[strtolower($code)] = $summary;
        }
        return count($active) > 0
            ? implode(' ; ', array_values($active))
            : __('Aucune alarme active', __FILE__);
    }

    private function decodedAlarmItems($items)
    {
        if (is_string($items)) {
            $raw = trim($items);
            if ($raw === '') {
                return array();
            }
            for ($depth = 0; $depth < 2 && is_string($items); $depth++) {
                $items = json_decode($items, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return null;
                }
            }
        }
        if (!is_array($items)) {
            return $items === null ? array() : null;
        }
        if (isset($items['items']) && is_array($items['items'])) {
            $items = $items['items'];
        }
        if (
            isset($items['x.com.samsung.da.code'])
            || isset($items['code'])
        ) {
            return array($items);
        }
        return array_values($items);
    }

    private function alarmTimeLabel($value)
    {
        if (
            preg_match(
                '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/',
                (string) $value,
                $matches
            )
        ) {
            return sprintf(
                __('%1$s à %2$s', __FILE__),
                $matches[3] . '/' . $matches[2] . '/' . $matches[1],
                $matches[4] . ':' . $matches[5]
            );
        }
        return '';
    }

    private function alarmCodeLabel($code)
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $code));
        $rules = array(
            '/(?:hotwarning|overheat|hightemp|temperaturehigh)/' => __('Température élevée', __FILE__),
            '/(?:door.*open|open.*door)/' => __('Porte ouverte', __FILE__),
            '/filter/' => __('Filtre à entretenir', __FILE__),
            '/(?:watersupply|waterinlet|inletwater|nowater)/' => __('Problème d’arrivée d’eau', __FILE__),
            '/(?:drain|waterout)/' => __('Problème de vidange', __FILE__),
            '/(?:gasleak|leakgas)/' => __('Fuite de gaz détectée', __FILE__),
            '/(?:waterleak|leakage|leak)/' => __('Fuite détectée', __FILE__),
            '/(?:overflow|overfill)/' => __('Débordement détecté', __FILE__),
            '/motor/' => __('Problème moteur', __FILE__),
            '/(?:voltage|powersupply|powererror)/' => __('Problème d’alimentation', __FILE__),
            '/(?:communication|network|offline)/' => __('Problème de communication', __FILE__),
            '/detergent/' => __('Vérifiez la lessive', __FILE__),
            '/softener/' => __('Vérifiez l’adoucissant', __FILE__),
            '/(?:tankempty|emptytank|reservoirempty)/' => __('Réservoir vide', __FILE__),
            '/sensor/' => __('Défaut de capteur', __FILE__),
            '/fan/' => __('Problème de ventilation', __FILE__),
            '/compressor/' => __('Problème du compresseur', __FILE__),
            '/defrost/' => __('Problème de dégivrage', __FILE__),
            '/(?:smoke|fire)/' => __('Fumée détectée', __FILE__),
            '/errorcode/' => __('Erreur de l’appareil', __FILE__),
        );
        foreach ($rules as $pattern => $label) {
            if (preg_match($pattern, $normalized)) {
                return $label;
            }
        }
        return __('Alerte', __FILE__) . ' (' . (string) $code . ')';
    }

    private function translatedValue($value)
    {
        $labels = array(
            'allowed' => __('Autorisé', __FILE__),
            'notallowed' => __('Non autorisé', __FILE__),
            'supported' => __('Pris en charge', __FILE__),
            'notsupported' => __('Non pris en charge', __FILE__),
            'enable' => __('Activé', __FILE__),
            'enabled' => __('Activé', __FILE__),
            'disable' => __('Désactivé', __FILE__),
            'disabled' => __('Désactivé', __FILE__),
            'open' => __('Ouvert', __FILE__),
            'opened' => __('Ouvert', __FILE__),
            'close' => __('Fermé', __FILE__),
            'closed' => __('Fermé', __FILE__),
            'lock' => __('Verrouillé', __FILE__),
            'locked' => __('Verrouillé', __FILE__),
            'unlock' => __('Déverrouillé', __FILE__),
            'unlocked' => __('Déverrouillé', __FILE__),
            'ok' => __('OK', __FILE__),
        );
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', trim((string) $value)));
        return $labels[$key] ?? $value;
    }

    private function operationalStateLabel($value)
    {
        $labels = array(
            'run' => __('En cours', __FILE__),
            'pause' => __('En pause', __FILE__),
            'ready' => __('Prêt', __FILE__),
            'stop' => __('Arrêté', __FILE__),
            'finished' => __('Terminé', __FILE__),
            'complete' => __('Terminé', __FILE__),
        );
        $normalized = strtolower(trim((string) $value));
        return $labels[$normalized] ?? (string) $value;
    }

    private function entityKey($href, $field)
    {
        $base = trim(str_replace(array('x.com.samsung.da.', '/', '.', '-', ':'), '_', $href . '_' . $field), '_');
        $base = preg_replace('/_+/', '_', preg_replace('/[^A-Za-z0-9_]/', '_', $base));
        return substr($base, 0, 80) . '_' . substr(sha1($href . "\0" . $field), 0, 8);
    }

    private function humanName($href, $field)
    {
        $known = array(
            '/power/0|value' => __('Alimentation', __FILE__),
            '/power/vs/0|x.com.samsung.da.power' => __('Alimentation', __FILE__),
            '/kidslock/0|value' => __('Sécurité enfants', __FILE__),
            '/kidslock/vs/0|x.com.samsung.da.kidsLock' => __('Sécurité enfants', __FILE__),
            '/remotectrl/0|value' => __('Contrôle à distance', __FILE__),
            '/remotectrl/vs/0|x.com.samsung.da.remoteControlEnabled' => __('Contrôle à distance', __FILE__),
            '/washer/vs/0|x.com.samsung.da.waterTemperature' => __('Température de lavage', __FILE__),
            '/washer/vs/0|x.com.samsung.da.spinLevel' => __('Vitesse d’essorage', __FILE__),
            '/washer/vs/0|x.com.samsung.da.rinseCycles' => __('Nombre de rinçages', __FILE__),
            '/washer/vs/0|x.com.samsung.da.supportedWaterTemperature' => __('Températures de lavage disponibles', __FILE__),
            '/washer/vs/0|x.com.samsung.da.supportedSpinLevel' => __('Vitesses d’essorage disponibles', __FILE__),
            '/washer/vs/0|x.com.samsung.da.supportedRinseCycles' => __('Nombres de rinçages disponibles', __FILE__),
            '/operational/state/vs/0|x.com.samsung.da.state' => __('État', __FILE__),
            '/operational/state/vs/0|x.com.samsung.da.remainingTime' => __('Temps restant', __FILE__),
            '/operational/state/vs/0|x.com.samsung.da.progressPercentage' => __('Progression', __FILE__),
            '/operational/state/0|state' => __('État', __FILE__),
            '/operational/state/0|remainingTime' => __('Temps restant', __FILE__),
            '/operational/state/0|progressPercentage' => __('Progression', __FILE__),
            '/energyconsumption/0|instantaneousPower' => __('Puissance instantanée', __FILE__),
            '/energyconsumption/0|cumulativePower' => __('Consommation cumulée', __FILE__),
            '/energyconsumption/0|cumulativeUnit' => __('Unité de consommation', __FILE__),
            '/energyconsumption/0|cumulativeDate' => __('Date du relevé', __FILE__),
            '/energyconsumption/0|cumulativeDateUTC' => __('Date UTC du relevé', __FILE__),
            '/alarms/vs/0|x.com.samsung.da.items' => __('Alarmes', __FILE__),
        );
        $knownKey = $href . '|' . $field;
        if (isset($known[$knownKey])) {
            return $known[$knownKey];
        }
        if (
            preg_match('#/alarms?/(?:vs/)?\d+$#i', $href)
            && preg_match('/(?:^|\.)items$/i', $field)
        ) {
            return __('Alarmes', __FILE__);
        }
        if (preg_match('/drumCleanProposal/i', $field)) {
            return __('Alerte après', __FILE__);
        }
        if (preg_match('/washingTimes/i', $field)) {
            return __('Lavages depuis le dernier nettoyage', __FILE__);
        }
        if (preg_match('/drumCleanLog/i', $field)) {
            return __('Historique des nettoyages tambour', __FILE__);
        }
        $fieldLabel = $this->translatedIdentifier($field);
        $resource = trim(preg_replace('#/(?:vs/)?\d+$#', '', $href), '/');
        $resourceLabel = $this->translatedIdentifier($resource);
        if ($fieldLabel === $resourceLabel) {
            return $fieldLabel;
        }
        if (in_array($fieldLabel, array(__('Valeur', __FILE__), __('État', __FILE__), __('Mode', __FILE__)), true)) {
            return trim($resourceLabel . ' - ' . $fieldLabel, ' -');
        }
        return $fieldLabel !== '' ? $fieldLabel : $resourceLabel;
    }

    private function ensureUniqueEntityNames(&$entities)
    {
        $used = array();
        foreach ($entities as &$entity) {
            $base = trim((string) ($entity['name'] ?? ''));
            if ($base === '') {
                $base = trim((string) ($entity['key'] ?? __('Information', __FILE__))) ?: __('Information', __FILE__);
            }
            $name = $base;
            $suffix = 2;
            while (isset($used[$this->nameKey($name)])) {
                $name = $base . ' (' . $suffix++ . ')';
            }
            $entity['name'] = $name;
            $used[$this->nameKey($name)] = true;
        }
        unset($entity);
    }

    private function nameKey($name)
    {
        $name = trim((string) $name);
        return function_exists('mb_strtolower')
            ? mb_strtolower($name, 'UTF-8')
            : strtolower($name);
    }

    private function translatedIdentifier($identifier)
    {
        $identifier = preg_replace('/^x\.com\.samsung\.da\./i', '', (string) $identifier);
        $compact = strtolower(preg_replace('/[^a-z0-9]/i', '', $identifier));
        $phrases = array(
            'information' => __('Informations de l’appareil', __FILE__),
            'washer' => __('Lave-linge', __FILE__),
            'dryer' => __('Sèche-linge', __FILE__),
            'dishwasher' => __('Lave-vaisselle', __FILE__),
            'refrigeration' => __('Réfrigérateur', __FILE__),
            'operationalstate' => __('Fonctionnement', __FILE__),
            'energyconsumption' => __('Consommation électrique', __FILE__),
            'wmstatistics' => __('Entretien', __FILE__),
            'wmsetinfo' => __('Configuration du lave-linge', __FILE__),
            'course' => __('Programme', __FILE__),
            'stwashercourse' => __('Catalogue des programmes du lave-linge', __FILE__),
            'stdryercourse' => __('Catalogue des programmes du sèche-linge', __FILE__),
            'value' => __('Valeur', __FILE__),
            'state' => __('État', __FILE__),
            'mode' => __('Mode', __FILE__),
            'wmstatus' => __('État du lave-linge', __FILE__),
            'wmconfig' => __('Configuration du lave-linge', __FILE__),
            'devicetype' => __('Type d’appareil', __FILE__),
            'updateallow' => __('Mise à jour autorisée', __FILE__),
            'laundryouttime' => __('Heure de fin du linge', __FILE__),
            'seamlesscontrol' => __('Contrôle continu', __FILE__),
            'kidslockbypass' => __('Contournement de la sécurité enfants', __FILE__),
            'detergentonce' => __('Dose unique de lessive', __FILE__),
            'detergentleft' => __('Lessive restante', __FILE__),
            'detergentbase' => __('Dose de base de lessive', __FILE__),
            'detergentalarm' => __('Alerte de lessive', __FILE__),
            'detergenttype' => __('Type de lessive', __FILE__),
            'detergenttotal' => __('Quantité totale de lessive', __FILE__),
            'softenerleft' => __('Adoucissant restant', __FILE__),
            'softeneralarm' => __('Alerte d’adoucissant', __FILE__),
            'specialfunction' => __('Fonction spéciale', __FILE__),
            'laundryplannerusersettime' => __('Heure planifiée', __FILE__),
            'energylevelset' => __('Niveau d’énergie', __FILE__),
            'mostused' => __('Programme le plus utilisé', __FILE__),
            'usagesdb' => __('Base des utilisations', __FILE__),
            'timesync' => __('Synchronisation de l’heure', __FILE__),
            'drumcleanproposal' => __('Alerte après', __FILE__),
            'washingtimes' => __('Lavages depuis le dernier nettoyage', __FILE__),
            'drumcleanlog' => __('Historique des nettoyages tambour', __FILE__),
            'watertemperature' => __('Température de lavage', __FILE__),
            'supportedwatertemperature' => __('Températures de lavage disponibles', __FILE__),
            'spinlevel' => __('Vitesse d’essorage', __FILE__),
            'supportedspinlevel' => __('Vitesses d’essorage disponibles', __FILE__),
            'rinsecycles' => __('Nombre de rinçages', __FILE__),
            'supportedrinsecycles' => __('Nombres de rinçages disponibles', __FILE__),
            'drylevel' => __('Niveau de séchage', __FILE__),
            'remainingtime' => __('Temps restant', __FILE__),
            'progresstime' => __('Durée de progression', __FILE__),
            'progresspercentage' => __('Progression', __FILE__),
            'instantaneouspower' => __('Puissance instantanée', __FILE__),
            'instantaneouspowerunit' => __('Unité de puissance', __FILE__),
            'cumulativepower' => __('Consommation cumulée', __FILE__),
            'cumulativeunit' => __('Unité de consommation', __FILE__),
            'cumulativedate' => __('Date du relevé', __FILE__),
            'cumulativedateutc' => __('Date UTC du relevé', __FILE__),
            'coursetable' => __('Table des programmes', __FILE__),
            'supportedoptions' => __('Options disponibles', __FILE__),
            'ismodelsettingpoweronoff' => __('Commande marche/arrêt autorisée', __FILE__),
            'remotecontrolenabled' => __('Contrôle à distance', __FILE__),
            'kidslock' => __('Sécurité enfants', __FILE__),
            'power' => __('Alimentation', __FILE__),
            'filterlife' => __('Durée de vie du filtre', __FILE__),
            'filterstatus' => __('État du filtre', __FILE__),
            'filterremind' => __('Rappel du filtre', __FILE__),
            'voltage' => __('Tension', __FILE__),
            'electriccurrent' => __('Intensité', __FILE__),
            'frequency' => __('Fréquence', __FILE__),
            'pressure' => __('Pression', __FILE__),
            'battery' => __('Batterie', __FILE__),
            'brightness' => __('Luminosité', __FILE__),
            'humidity' => __('Humidité', __FILE__),
            'temperature' => __('Température', __FILE__),
            'flowrate' => __('Débit', __FILE__),
            'waterconsumption' => __('Consommation d’eau', __FILE__),
            'weight' => __('Poids', __FILE__),
            'co2' => __('CO₂', __FILE__),
            'voc' => __('COV', __FILE__),
        );
        if (isset($phrases[$compact])) {
            return $phrases[$compact];
        }

        $words = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $identifier);
        $words = preg_split('/[^\pL\pN]+/u', $words, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array(
            'air' => __('air', __FILE__), 'alarm' => __('alerte', __FILE__), 'allow' => __('autorisation', __FILE__),
            'available' => __('disponible', __FILE__), 'base' => __('base', __FILE__), 'child' => __('enfants', __FILE__),
            'clean' => __('nettoyage', __FILE__), 'control' => __('contrôle', __FILE__), 'cooktop' => __('table de cuisson', __FILE__),
            'count' => __('nombre', __FILE__), 'cumulative' => __('cumulée', __FILE__), 'current' => __('actuelle', __FILE__),
            'cycle' => __('programme', __FILE__), 'date' => __('date', __FILE__), 'delay' => __('délai', __FILE__),
            'desired' => __('souhaitée', __FILE__), 'detergent' => __('lessive', __FILE__), 'device' => __('appareil', __FILE__),
            'dishwasher' => __('lave-vaisselle', __FILE__), 'door' => __('porte', __FILE__), 'dryer' => __('sèche-linge', __FILE__),
            'enabled' => __('activé', __FILE__), 'energy' => __('énergie', __FILE__), 'error' => __('erreur', __FILE__),
            'fan' => __('ventilation', __FILE__), 'filter' => __('filtre', __FILE__), 'fridge' => __('réfrigérateur', __FILE__),
            'hood' => __('hotte', __FILE__), 'kids' => __('enfants', __FILE__),
            'level' => __('niveau', __FILE__), 'lock' => __('verrouillage', __FILE__), 'log' => __('historique', __FILE__),
            'mode' => __('mode', __FILE__), 'open' => __('ouverture', __FILE__), 'option' => __('option', __FILE__),
            'oven' => __('four', __FILE__), 'power' => __('puissance', __FILE__), 'quality' => __('qualité', __FILE__),
            'refrigerator' => __('réfrigérateur', __FILE__), 'remaining' => __('restant', __FILE__),
            'remote' => __('distance', __FILE__), 'rinse' => __('rinçage', __FILE__), 'set' => __('réglage', __FILE__),
            'softener' => __('adoucissant', __FILE__), 'speed' => __('vitesse', __FILE__), 'spin' => __('essorage', __FILE__),
            'state' => __('état', __FILE__), 'status' => __('état', __FILE__), 'supported' => __('disponible', __FILE__),
            'target' => __('cible', __FILE__), 'temperature' => __('température', __FILE__), 'time' => __('temps', __FILE__),
            'total' => __('total', __FILE__),
            'type' => __('type', __FILE__), 'unit' => __('unité', __FILE__), 'update' => __('mise à jour', __FILE__),
            'usage' => __('utilisation', __FILE__), 'value' => __('valeur', __FILE__), 'washer' => __('lave-linge', __FILE__),
            'washing' => __('lavage', __FILE__), 'water' => __('eau', __FILE__),
        );
        $translated = array();
        foreach ($words as $word) {
            $key = strtolower($word);
            if (in_array($key, array('x', 'com', 'samsung', 'da', 'vs', 'st'), true) || ctype_digit($key)) {
                continue;
            }
            $translated[] = $tokens[$key] ?? $word;
        }
        return ucfirst(trim(implode(' ', $translated)));
    }

    private function optionName($prefix)
    {
        $names = array(
            'upperlamp' => __('Éclairage supérieur', __FILE__),
            'sound' => __('Son', __FILE__),
            'fastpreheat' => __('Préchauffage rapide', __FILE__),
            'naturalsteam' => __('Vapeur naturelle', __FILE__),
            'energysaving' => __('Économie d’énergie', __FILE__),
            'burneronalert' => __('Alerte foyer allumé', __FILE__),
            'spi' => __('Mode intelligent', __FILE__),
            'autoclean' => __('Nettoyage automatique', __FILE__),
            'airmonitoring' => __('Surveillance de l’air', __FILE__),
            'stormwashzone' => __('Zone de lavage intensif', __FILE__),
            'autodoorrelease' => __('Ouverture automatique de la porte', __FILE__),
            'bubblesoak' => __('Bubble Soak', __FILE__),
            'addwash' => __('Add Wash', __FILE__),
            'prewashsetting' => __('Prélavage', __FILE__),
            'intensivesetting' => __('Lavage intensif', __FILE__),
            'energykw' => __('Consommation du cycle', __FILE__),
        );
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $prefix));
        return $names[$key] ?? $this->translatedIdentifier($prefix);
    }

    private function subtype($href, $field, $value, $actions = array())
    {
        if ($this->isBinaryField($href, $field, $value) || is_bool($value)) {
            return 'binary';
        }
        foreach ($actions as $action) {
            if (
                ($action['key'] ?? '') === 'on'
                || ($action['key'] ?? '') === 'off'
            ) {
                return 'binary';
            }
        }
        return is_numeric($value) ? 'numeric' : 'string';
    }

    private function platform($href, $field, $value, $actions)
    {
        if (count($actions) > 0) {
            return (string) ($actions[0]['subtype'] ?? 'switch');
        }
        if (strpos($field, 'temperature') !== false) {
            return 'sensor';
        }
        return 'raw';
    }

    private function unit($href, $field, $representation = array(), $value = null)
    {
        if (preg_match('/unit$/i', (string) $field)) {
            return '';
        }
        if ($value !== null && !is_numeric($value)) {
            return '';
        }
        $explicit = $this->explicitUnit($field, $representation);
        if (preg_match('/cumulative(?:Power|Consumption)/i', $field)) {
            return in_array($explicit, array('Wh', 'kWh', 'MWh', 'J', ''), true) ? 'kWh' : $explicit;
        }
        if ($explicit !== '') {
            return $explicit;
        }
        if (stripos($field, 'supported') === false && preg_match('/temperature/i', $field)) {
            return '°C';
        }
        if (preg_match('/(?:humidity|percentage|percent|battery|position|brightness|dimmer)/i', $field)) {
            return '%';
        }
        if (preg_match('/voltage/i', $field)) {
            return 'V';
        }
        if (preg_match('/(?:electricCurrent|amperage)/i', $field)) {
            return 'A';
        }
        if (preg_match('/frequency/i', $field)) {
            return 'Hz';
        }
        if (preg_match('/pressure/i', $field)) {
            return 'hPa';
        }
        if (preg_match('/(?:pm1|pm2(?:\.5)?|pm10|dust)/i', $field)) {
            return 'µg/m³';
        }
        if (preg_match('/co2/i', $field)) {
            return 'ppm';
        }
        if (preg_match('/(?:voc|tvoc)/i', $field)) {
            return 'ppb';
        }
        if (preg_match('/(?:noise|soundLevel)/i', $field)) {
            return 'dB';
        }
        if (
            preg_match('/power$/i', $field)
            && strpos($href, '/power/') === false
            && $field !== 'x.com.samsung.da.power'
        ) {
            return 'W';
        }
        if (stripos($field, 'supported') === false && preg_match('/spinLevel/i', $field)) {
            return 'tr/min';
        }
        if (preg_match('/(?:rpm|rotationSpeed)/i', $field)) {
            return 'tr/min';
        }
        if (preg_match('/(?:drumCleanProposal|washingTimes)/i', $field)) {
            return __('lavages', __FILE__);
        }
        if (preg_match('/openTime$/i', $field)) {
            return 'ms';
        }
        if (preg_match('/(?:duration|remainingTime|elapsedTime)$/i', $field)) {
            return preg_match('/(?:milli|msec|Ms$)/i', $field) ? 'ms' : 's';
        }
        if (preg_match('/(?:waterConsumption|waterVolume|volume)$/i', $field)) {
            return 'L';
        }
        if (preg_match('/(?:flow|flowRate)$/i', $field)) {
            return 'L/min';
        }
        if (preg_match('/(?:weight|mass)$/i', $field)) {
            return 'kg';
        }
        return '';
    }

    private function explicitUnit($field, $representation)
    {
        if (!is_array($representation)) {
            return '';
        }
        $fieldKey = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $field));
        $fieldKey = preg_replace('/^xcomsamsungda/', '', $fieldKey);
        $candidateKeys = array('unit');
        if (strpos($fieldKey, 'instantaneouspower') !== false) {
            $candidateKeys[] = 'instantaneouspowerunit';
            $candidateKeys[] = 'powerunit';
        }
        if (strpos($fieldKey, 'cumulative') !== false) {
            $candidateKeys[] = 'cumulativeunit';
            $candidateKeys[] = 'cumulativepowerunit';
        }
        if (strpos($fieldKey, 'temperature') !== false) {
            $candidateKeys[] = 'temperatureunit';
        }
        $candidateKeys[] = $fieldKey . 'unit';
        foreach ($representation as $candidate => $unit) {
            if (!is_scalar($unit)) {
                continue;
            }
            $candidateKey = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $candidate));
            $candidateKey = preg_replace('/^xcomsamsungda/', '', $candidateKey);
            if (in_array($candidateKey, $candidateKeys, true)) {
                return $this->normalizeUnit($unit);
            }
        }
        return '';
    }

    private function normalizeUnit($unit)
    {
        $raw = trim((string) $unit);
        $key = strtolower(str_replace(array(' ', '_', '-', '°'), '', $raw));
        $units = array(
            'c' => '°C', 'celsius' => '°C', 'degc' => '°C',
            'f' => '°F', 'fahrenheit' => '°F', 'degf' => '°F',
            'percent' => '%', 'percentage' => '%', '%' => '%',
            'w' => 'W', 'watt' => 'W', 'watts' => 'W',
            'kw' => 'kW', 'kilowatt' => 'kW', 'kilowatts' => 'kW',
            'wh' => 'Wh', 'watthour' => 'Wh', 'watthours' => 'Wh',
            'kwh' => 'kWh', 'kilowatthour' => 'kWh', 'kilowatthours' => 'kWh',
            'mwh' => 'MWh', 'megawatthour' => 'MWh',
            'v' => 'V', 'volt' => 'V', 'volts' => 'V',
            'mv' => 'mV',
            'a' => 'A', 'amp' => 'A', 'amps' => 'A', 'ampere' => 'A',
            'ma' => 'mA', 'va' => 'VA', 'var' => 'VAr',
            'hz' => 'Hz', 'hertz' => 'Hz',
            'pa' => 'Pa', 'hpa' => 'hPa', 'bar' => 'bar',
            's' => 's', 'sec' => 's', 'second' => 's', 'seconds' => 's',
            'ms' => 'ms', 'millisecond' => 'ms', 'milliseconds' => 'ms',
            'min' => 'min', 'minute' => 'min', 'minutes' => 'min',
            'h' => 'h', 'hour' => 'h', 'hours' => 'h',
            'rpm' => 'tr/min', 'l' => 'L', 'liter' => 'L', 'litre' => 'L',
            'l/min' => 'L/min', 'kg' => 'kg', 'db' => 'dB',
            'dbm' => 'dBm', 'ppm' => 'ppm', 'ppb' => 'ppb',
            'j' => 'J', 'joule' => 'J', 'lux' => 'lux',
            'm3' => 'm³', 'm3/h' => 'm³/h',
            'ug/m3' => 'µg/m³', 'µg/m³' => 'µg/m³',
        );
        if (isset($units[$key])) {
            return $units[$key];
        }
        return preg_match('/^[\pL\d°%µμ\/³²·._-]{1,16}$/u', $raw) ? $raw : '';
    }

    private function isBinaryField($href, $field, $value)
    {
        $known = array(
            '/power/0|value',
            '/power/vs/0|x.com.samsung.da.power',
            '/kidslock/0|value',
            '/kidslock/vs/0|x.com.samsung.da.kidsLock',
            '/remotectrl/0|value',
            '/remotectrl/vs/0|x.com.samsung.da.remoteControlEnabled',
        );
        if (in_array($href . '|' . $field, $known, true) || is_bool($value)) {
            return true;
        }
        if (!is_scalar($value)) {
            return false;
        }
        $token = strtolower(trim((string) $value));
        if (!in_array($token, array('on', 'off', 'true', 'false', 'enable', 'enabled', 'disable', 'disabled'), true)) {
            return false;
        }
        return preg_match('/(?:power|lock|enabled|indicator|alarm|allow|control|bypass|proposal)$/i', $field) === 1;
    }

    private function category($href, $field)
    {
        return preg_match('/(?:diagnostic|error|alarm|firmware|version|serial|model)/i', $href . ' ' . $field)
            ? 'diagnostic'
            : '';
    }

    private function similarField($field, $candidate)
    {
        $normalize = function ($value) {
            return strtolower(preg_replace('/(?:supported|available|desired|current|modes?|values?)/i', '', (string) $value));
        };
        $left = $normalize($field);
        $right = $normalize($candidate);
        return $left === ''
            || $right === ''
            || strpos($left, $right) !== false
            || strpos($right, $left) !== false;
    }

    private function durationHours($value)
    {
        $parts = array_map('intval', explode(':', (string) $value));
        if (count($parts) === 3) {
            return $parts[0] + ($parts[1] / 60) + ($parts[2] / 3600);
        }
        if (count($parts) === 2) {
            return ($parts[0] / 60) + ($parts[1] / 3600);
        }
        return 0;
    }

    private function toBoolean($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }
        return in_array(strtolower(trim((string) $value)), array('1', 'true', 'on', 'enable', 'enabled', 'yes'), true);
    }

    private function endsWith($value, $suffix)
    {
        $suffixLength = strlen($suffix);
        return $suffixLength === 0 || substr($value, -$suffixLength) === $suffix;
    }

}
