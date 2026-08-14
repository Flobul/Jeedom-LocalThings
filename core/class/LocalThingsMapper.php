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
            throw new InvalidArgumentException('Recette d’écriture LocalThings invalide');
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
                    throw new InvalidArgumentException('Valeur numérique invalide');
                }
                $bodyValue = (int) $value;
                break;
            case 'number':
                if (!is_numeric($value)) {
                    throw new InvalidArgumentException('Valeur numérique invalide');
                }
                $bodyValue = $value + 0;
                break;
            case 'delay_hours':
                if (!is_numeric($value)) {
                    throw new InvalidArgumentException('Durée invalide');
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
            throw new InvalidArgumentException('Champ d’écriture LocalThings absent');
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
                $this->tr('Choisir'),
                'select',
                $recipe,
                $this->labelOptions($field, $options)
            );
            return $actions;
        }

        $range = $this->rangeFor($field, $representation);
        if ($range !== null && is_numeric($value) && $this->isWritableNumberField($href, $field)) {
            $recipe['encoding'] = is_int($value) ? 'integer' : 'number';
            $action = $this->valueAction('set', $this->tr('Régler'), 'slider', $recipe);
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
        foreach (array('Run' => 'Démarrer', 'Pause' => 'Pause', 'Ready' => 'Arrêter') as $fixed => $name) {
            $recipe = array(
                'href' => $href,
                'field' => $stateField,
                'encoding' => 'fixed',
                'fixed' => $fixed,
            );
            $actions[] = $this->fixedAction(strtolower($fixed), $this->tr($name), $recipe);
        }
        $entities[] = array(
            'key' => $key,
            'name' => $this->tr('Commandes du cycle'),
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
            $action = $this->valueAction('set', $this->tr('Régler'), 'slider', $recipe);
            $action['min'] = 0;
            $action['max'] = 24;
            $action['step'] = 1;
            $action['unit'] = 'h';
            $entities[] = array(
                'key' => $delayKey,
                'name' => $this->tr('Départ différé'),
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
                    $entities[] = array(
                        'key' => $key,
                        'name' => $this->optionName($prefix),
                        'platform' => count($actions) > 0 ? 'switch' : 'sensor',
                        'type' => 'info',
                        'subtype' => count($actions) > 0 ? 'binary' : (is_numeric($value) ? 'numeric' : 'string'),
                        'unit' => $prefix === 'EnergyKW' ? 'Wh' : '',
                        'category' => '',
                        'value' => count($actions) > 0 ? ($value === 'On' ? 1 : 0) : $value,
                        'options' => array(),
                        'actions' => $actions,
                    );
                    $states[$key] = count($actions) > 0 ? ($value === 'On' ? 1 : 0) : $value;
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
            ? $this->tr('Nettoyage recommandé')
            : $this->tr('Aucun nettoyage nécessaire');
        $entities[] = array(
            'key' => $key,
            'name' => $this->tr('Nettoyage du tambour'),
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
                $this->tr('Choisir'),
                'select',
                $recipe,
                $options
            );
            $actions[0]['target'] = $key;
        }
        $entities[] = array(
            'key' => $key,
            'name' => $this->tr('Cycle'),
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
                'BA' => 'Vidange / essorage', 'D0' => 'Coton', 'D1' => 'eCoton',
                'D2' => 'Synthétiques', 'D3' => 'Délicat', 'D4' => 'Rinçage + essorage',
                'D5' => 'Nettoyage tambour', 'D6' => 'Draps', 'D7' => 'Imperméable',
                'D8' => 'Laine', 'D9' => 'Couleurs', 'DA' => 'Eco', 'DB' => 'Super rapide',
                'DC' => 'Express 15 min', '5B' => 'Coton', '5C' => 'Super rapide',
                '5D' => 'Eco', '5E' => 'Délicat', '5F' => 'Bébé coton',
                '60' => 'Imperméable', '61' => 'Couleurs', '63' => 'Nettoyage tambour',
                '64' => 'Rinçage + essorage', '65' => 'Laine', '66' => 'Draps',
                '67' => 'Synthétiques', '68' => 'eCoton', '6C' => 'Jeans',
            ),
            'Table_02' => array(
                '01' => 'Normal', '04' => 'Lavage rapide', '17' => 'Téléchargé',
                '1B' => 'Coton', '1C' => 'Eco 40-60', '1D' => 'Super rapide',
                '1E' => 'Express 15 min', '1F' => 'Intensif à froid',
                '20' => 'Anti-allergènes', '21' => 'Couleurs', '22' => 'Laine',
                '23' => 'Extérieur', '24' => 'Serviettes', '25' => 'Synthétiques',
                '26' => 'Délicat', '27' => 'Rinçage + essorage',
                '28' => 'Vidange / essorage', '29' => 'Nettoyage tambour+',
                '2A' => 'Jeans', '2B' => 'Lavage IA', '2D' => 'Lavage silencieux',
                '2E' => 'Bébé coton', '2F' => 'Sport', '30' => 'Journée nuageuse',
                '32' => 'Chemises', '33' => 'Draps', '34' => 'Mix',
                '36' => 'Lavage + séchage', '37' => 'Air Wash',
                '38' => 'Séchage coton', '39' => 'Séchage synthétiques',
                '3A' => 'Nettoyage tambour', '52' => 'Eco à froid',
                '53' => 'Intensif', '54' => 'Serviettes', '55' => 'Sport',
                '57' => 'Délicat', '5E' => 'Rinçage + essorage',
                '60' => 'Auto-nettoyage+', '65' => 'Couleurs', '66' => 'Jeans',
                '7C' => 'Blanc', '7D' => 'Draps / Imperméable',
                '7E' => 'Auto-nettoyage', '7F' => 'Laine / Délicat',
                '86' => 'Lavage en profondeur', '87' => 'Téléchargé',
                '8F' => 'Intensif à froid', '96' => 'Moins de microfibres',
            ),
            'Table_03' => array(
                '16' => 'Coton', '17' => 'Super rapide', '18' => 'Synthétiques',
                '19' => 'Délicat', '1A' => 'Laine', '1B' => 'Draps',
                '1C' => 'Chemises', '1D' => 'Serviettes', '1E' => 'Vêtements de sport',
                '1F' => 'Mix', '20' => 'Prêt à repasser', '21' => 'Anti-allergènes',
                '23' => 'Séchage rapide 35 min', '24' => 'Air froid',
                '25' => 'Air chaud', '26' => 'Air Wash', '27' => 'Minuterie',
            ),
        );
        $code = strtoupper((string) $code);
        return isset($tables[$table][$code]) ? $this->tr($tables[$table][$code]) : $code;
    }

    private function switchActions($recipe)
    {
        return array(
            $this->fixedAction('on', $this->tr('Allumer'), array_merge($recipe, array('fixed_input' => true)), true),
            $this->fixedAction('off', $this->tr('Éteindre'), array_merge($recipe, array('fixed_input' => false)), false),
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
            return $count . ' ' . $this->tr($count > 1 ? 'rinçages' : 'rinçage');
        }
        if (stripos($field, 'spinLevel') !== false && is_numeric($value)) {
            return $value . ' tr/min';
        }
        if (stripos($field, 'waterTemperature') !== false && is_numeric($value)) {
            return $value . ' °C';
        }
        $labels = array(
            'none' => 'Aucun',
            'auto' => 'Automatique',
            'automatic' => 'Automatique',
            'on' => 'Activé',
            'off' => 'Désactivé',
            'enabled' => 'Activé',
            'disabled' => 'Désactivé',
            'normal' => 'Normal',
            'eco' => 'Éco',
            'heat' => 'Chauffage',
            'fan' => 'Ventilation',
            'dry' => 'Déshumidification',
            'nospin' => 'Sans essorage',
            'rinsehold' => 'Arrêt cuve pleine',
            'extralow' => 'Très faible',
            'low' => 'Faible',
            'medium' => 'Moyen',
            'high' => 'Élevé',
            'extrahigh' => 'Très élevé',
            'delicate' => 'Délicat',
            'tapcold' => 'Eau froide',
            'cold' => 'Froid',
            'cool' => 'Frais',
            'mediumlow' => 'Froid à tiède',
            'semihot' => 'Tiède',
            'warm' => 'Chaud',
            'hot' => 'Très chaud',
            'extrahot' => 'Très chaud',
        );
        return isset($labels[$normalized]) ? $this->tr($labels[$normalized]) : (string) $value;
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

    private function translatedValue($value)
    {
        $labels = array(
            'allowed' => 'Autorisé',
            'notallowed' => 'Non autorisé',
            'supported' => 'Pris en charge',
            'notsupported' => 'Non pris en charge',
            'enable' => 'Activé',
            'enabled' => 'Activé',
            'disable' => 'Désactivé',
            'disabled' => 'Désactivé',
            'open' => 'Ouvert',
            'opened' => 'Ouvert',
            'close' => 'Fermé',
            'closed' => 'Fermé',
            'lock' => 'Verrouillé',
            'locked' => 'Verrouillé',
            'unlock' => 'Déverrouillé',
            'unlocked' => 'Déverrouillé',
            'ok' => 'OK',
        );
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', trim((string) $value)));
        return isset($labels[$key]) ? $this->tr($labels[$key]) : $value;
    }

    private function operationalStateLabel($value)
    {
        $labels = array(
            'run' => 'En cours',
            'pause' => 'En pause',
            'ready' => 'Prêt',
            'stop' => 'Arrêté',
            'finished' => 'Terminé',
            'complete' => 'Terminé',
        );
        $normalized = strtolower(trim((string) $value));
        return $this->tr($labels[$normalized] ?? (string) $value);
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
            '/power/0|value' => 'Alimentation',
            '/power/vs/0|x.com.samsung.da.power' => 'Alimentation',
            '/kidslock/0|value' => 'Sécurité enfants',
            '/kidslock/vs/0|x.com.samsung.da.kidsLock' => 'Sécurité enfants',
            '/remotectrl/0|value' => 'Contrôle à distance',
            '/remotectrl/vs/0|x.com.samsung.da.remoteControlEnabled' => 'Contrôle à distance',
            '/washer/vs/0|x.com.samsung.da.waterTemperature' => 'Température de lavage',
            '/washer/vs/0|x.com.samsung.da.spinLevel' => 'Vitesse d’essorage',
            '/washer/vs/0|x.com.samsung.da.rinseCycles' => 'Nombre de rinçages',
            '/washer/vs/0|x.com.samsung.da.supportedWaterTemperature' => 'Températures de lavage disponibles',
            '/washer/vs/0|x.com.samsung.da.supportedSpinLevel' => 'Vitesses d’essorage disponibles',
            '/washer/vs/0|x.com.samsung.da.supportedRinseCycles' => 'Nombres de rinçages disponibles',
            '/operational/state/vs/0|x.com.samsung.da.state' => 'État',
            '/operational/state/vs/0|x.com.samsung.da.remainingTime' => 'Temps restant',
            '/operational/state/vs/0|x.com.samsung.da.progressPercentage' => 'Progression',
            '/operational/state/0|state' => 'État',
            '/operational/state/0|remainingTime' => 'Temps restant',
            '/operational/state/0|progressPercentage' => 'Progression',
            '/energyconsumption/0|instantaneousPower' => 'Puissance instantanée',
            '/energyconsumption/0|cumulativePower' => 'Consommation cumulée',
            '/energyconsumption/0|cumulativeUnit' => 'Unité de consommation',
            '/energyconsumption/0|cumulativeDate' => 'Date du relevé',
            '/energyconsumption/0|cumulativeDateUTC' => 'Date UTC du relevé',
        );
        $knownKey = $href . '|' . $field;
        if (isset($known[$knownKey])) {
            return $this->tr($known[$knownKey]);
        }
        if (preg_match('/drumCleanProposal/i', $field)) {
            return $this->tr('Alerte après');
        }
        if (preg_match('/washingTimes/i', $field)) {
            return $this->tr('Lavages depuis le dernier nettoyage');
        }
        if (preg_match('/drumCleanLog/i', $field)) {
            return $this->tr('Historique des nettoyages tambour');
        }
        $fieldLabel = $this->translatedIdentifier($field);
        $resource = trim(preg_replace('#/(?:vs/)?\d+$#', '', $href), '/');
        $resourceLabel = $this->translatedIdentifier($resource);
        if ($fieldLabel === $resourceLabel) {
            return $fieldLabel;
        }
        if (in_array($fieldLabel, array($this->tr('Valeur'), $this->tr('État'), $this->tr('Mode')), true)) {
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
                $base = trim((string) ($entity['key'] ?? 'Information')) ?: 'Information';
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
            'information' => 'Informations de l’appareil',
            'washer' => 'Lave-linge',
            'dryer' => 'Sèche-linge',
            'dishwasher' => 'Lave-vaisselle',
            'refrigeration' => 'Réfrigérateur',
            'operationalstate' => 'Fonctionnement',
            'energyconsumption' => 'Consommation électrique',
            'wmstatistics' => 'Entretien',
            'wmsetinfo' => 'Configuration du lave-linge',
            'course' => 'Programme',
            'stwashercourse' => 'Catalogue des programmes du lave-linge',
            'stdryercourse' => 'Catalogue des programmes du sèche-linge',
            'value' => 'Valeur',
            'state' => 'État',
            'mode' => 'Mode',
            'wmstatus' => 'État du lave-linge',
            'wmconfig' => 'Configuration du lave-linge',
            'devicetype' => 'Type d’appareil',
            'updateallow' => 'Mise à jour autorisée',
            'laundryouttime' => 'Heure de fin du linge',
            'seamlesscontrol' => 'Contrôle continu',
            'kidslockbypass' => 'Contournement de la sécurité enfants',
            'detergentonce' => 'Dose unique de lessive',
            'detergentleft' => 'Lessive restante',
            'detergentbase' => 'Dose de base de lessive',
            'detergentalarm' => 'Alerte de lessive',
            'detergenttype' => 'Type de lessive',
            'detergenttotal' => 'Quantité totale de lessive',
            'softenerleft' => 'Adoucissant restant',
            'specialfunction' => 'Fonction spéciale',
            'laundryplannerusersettime' => 'Heure planifiée',
            'energylevelset' => 'Niveau d’énergie',
            'mostused' => 'Programme le plus utilisé',
            'usagesdb' => 'Base des utilisations',
            'timesync' => 'Synchronisation de l’heure',
            'drumcleanproposal' => 'Alerte après',
            'washingtimes' => 'Lavages depuis le dernier nettoyage',
            'drumcleanlog' => 'Historique des nettoyages tambour',
            'watertemperature' => 'Température de lavage',
            'supportedwatertemperature' => 'Températures de lavage disponibles',
            'spinlevel' => 'Vitesse d’essorage',
            'supportedspinlevel' => 'Vitesses d’essorage disponibles',
            'rinsecycles' => 'Nombre de rinçages',
            'supportedrinsecycles' => 'Nombres de rinçages disponibles',
            'drylevel' => 'Niveau de séchage',
            'remainingtime' => 'Temps restant',
            'progresstime' => 'Durée de progression',
            'progresspercentage' => 'Progression',
            'instantaneouspower' => 'Puissance instantanée',
            'instantaneouspowerunit' => 'Unité de puissance',
            'cumulativepower' => 'Consommation cumulée',
            'cumulativeunit' => 'Unité de consommation',
            'cumulativedate' => 'Date du relevé',
            'cumulativedateutc' => 'Date UTC du relevé',
            'coursetable' => 'Table des programmes',
            'supportedoptions' => 'Options disponibles',
            'ismodelsettingpoweronoff' => 'Commande marche/arrêt autorisée',
            'remotecontrolenabled' => 'Contrôle à distance',
            'kidslock' => 'Sécurité enfants',
            'power' => 'Alimentation',
            'filterlife' => 'Durée de vie du filtre',
            'filterstatus' => 'État du filtre',
            'filterremind' => 'Rappel du filtre',
            'voltage' => 'Tension',
            'electriccurrent' => 'Intensité',
            'frequency' => 'Fréquence',
            'pressure' => 'Pression',
            'battery' => 'Batterie',
            'brightness' => 'Luminosité',
            'humidity' => 'Humidité',
            'temperature' => 'Température',
            'flowrate' => 'Débit',
            'waterconsumption' => 'Consommation d’eau',
            'weight' => 'Poids',
            'co2' => 'CO₂',
            'voc' => 'COV',
        );
        if (isset($phrases[$compact])) {
            return $this->tr($phrases[$compact]);
        }

        $words = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $identifier);
        $words = preg_split('/[^\pL\pN]+/u', $words, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array(
            'air' => 'air', 'alarm' => 'alerte', 'allow' => 'autorisation',
            'available' => 'disponible', 'base' => 'base', 'child' => 'enfants',
            'clean' => 'nettoyage', 'control' => 'contrôle', 'cooktop' => 'table de cuisson',
            'count' => 'nombre', 'cumulative' => 'cumulée', 'current' => 'actuelle',
            'cycle' => 'programme', 'date' => 'date', 'delay' => 'délai',
            'desired' => 'souhaitée', 'detergent' => 'lessive', 'device' => 'appareil',
            'dishwasher' => 'lave-vaisselle', 'door' => 'porte', 'dryer' => 'sèche-linge',
            'enabled' => 'activé', 'energy' => 'énergie', 'error' => 'erreur',
            'fan' => 'ventilation', 'filter' => 'filtre', 'fridge' => 'réfrigérateur',
            'hood' => 'hotte', 'kids' => 'enfants',
            'level' => 'niveau', 'lock' => 'verrouillage', 'log' => 'historique',
            'mode' => 'mode', 'open' => 'ouverture', 'option' => 'option',
            'oven' => 'four', 'power' => 'puissance', 'quality' => 'qualité',
            'refrigerator' => 'réfrigérateur', 'remaining' => 'restant',
            'remote' => 'distance', 'rinse' => 'rinçage', 'set' => 'réglage',
            'softener' => 'adoucissant', 'speed' => 'vitesse', 'spin' => 'essorage',
            'state' => 'état', 'status' => 'état', 'supported' => 'disponible',
            'target' => 'cible', 'temperature' => 'température', 'time' => 'temps',
            'total' => 'total',
            'type' => 'type', 'unit' => 'unité', 'update' => 'mise à jour',
            'usage' => 'utilisation', 'value' => 'valeur', 'washer' => 'lave-linge',
            'washing' => 'lavage', 'water' => 'eau',
        );
        $translated = array();
        foreach ($words as $word) {
            $key = strtolower($word);
            if (in_array($key, array('x', 'com', 'samsung', 'da', 'vs', 'st'), true) || ctype_digit($key)) {
                continue;
            }
            $translated[] = isset($tokens[$key]) ? $this->tr($tokens[$key]) : $word;
        }
        return ucfirst(trim(implode(' ', $translated)));
    }

    private function optionName($prefix)
    {
        $names = array(
            'upperlamp' => 'Éclairage supérieur',
            'sound' => 'Son',
            'fastpreheat' => 'Préchauffage rapide',
            'naturalsteam' => 'Vapeur naturelle',
            'energysaving' => 'Économie d’énergie',
            'burneronalert' => 'Alerte foyer allumé',
            'spi' => 'Mode intelligent',
            'autoclean' => 'Nettoyage automatique',
            'airmonitoring' => 'Surveillance de l’air',
            'stormwashzone' => 'Zone de lavage intensif',
            'autodoorrelease' => 'Ouverture automatique de la porte',
            'bubblesoak' => 'Bubble Soak',
            'addwash' => 'Add Wash',
            'prewashsetting' => 'Prélavage',
            'intensivesetting' => 'Lavage intensif',
            'energykw' => 'Consommation du cycle',
        );
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $prefix));
        return isset($names[$key]) ? $this->tr($names[$key]) : $this->translatedIdentifier($prefix);
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
            return $this->tr('lavages');
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

    private function tr($text)
    {
        if (!function_exists('__')) {
            return $text;
        }
        $translated = __($text, __FILE__);
        return trim((string) $translated) === '' ? $text : $translated;
    }
}
