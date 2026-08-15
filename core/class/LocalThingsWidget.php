<?php

/**
 * Describes the visual hierarchy of LocalThings equipment widgets.
 *
 * Rendering and command interactions deliberately remain delegated to Jeedom
 * through eqLogic::preToHtml(), cmd::toHtml(), getTemplate() and
 * template_replace(). This class only decides where an existing command is
 * displayed according to the semantic key discovered on the appliance.
 */
class LocalThingsWidget
{
    public static function profile($deviceType)
    {
        $profiles = array(
            'washer' => array(
                'label' => 'Lave-linge',
                'icon' => 'fas fa-tshirt',
                'accent' => '#2f80ed',
                'settings_title' => 'Programme et options',
                'features' => array('washer_cycle', 'washer_', 'course_', 'watertemperature', 'spinlevel', 'rinsecycles', 'bubblesoak', 'prewash', 'addwash', 'intensivesetting', 'delay_'),
            ),
            'dryer' => array(
                'label' => 'Sèche-linge',
                'icon' => 'fas fa-wind',
                'accent' => '#7b61ff',
                'settings_title' => 'Programme et options',
                'features' => array('dryer_cycle', 'dryer_', 'course_', 'drylevel', 'dryingtime', 'wrinkle', 'sanitize', 'delay_'),
            ),
            'dishwasher' => array(
                'label' => 'Lave-vaisselle',
                'icon' => 'fas fa-utensils',
                'accent' => '#00a8a8',
                'settings_title' => 'Programme et options',
                'features' => array('dishwasher', 'course_', 'stormwash', 'sanitize', 'autodoor', 'dry', 'delay_'),
            ),
            'air_dresser' => array(
                'label' => 'Armoire de soin',
                'icon' => 'fas fa-tshirt',
                'accent' => '#7768ae',
                'settings_title' => 'Programme et options',
                'features' => array('course_', 'mode_', 'steam', 'dry', 'wrinkle', 'sanitize', 'delay_'),
            ),
            'refrigerator' => array(
                'label' => 'Réfrigérateur',
                'icon' => 'fas fa-snowflake',
                'accent' => '#2684ff',
                'settings_title' => 'Réglages',
                'features' => array('refriger', 'icemaker', 'rapidfridge', 'rapidfreez', 'autofill', 'sabbath', 'temperature_desired', 'mode_'),
            ),
            'airconditioner' => array(
                'label' => 'Climatiseur',
                'icon' => 'fas fa-temperature-low',
                'accent' => '#00a6fb',
                'settings_title' => 'Réglages',
                'features' => array('power_', 'mode_', 'temperature_desired', 'wind_strength', 'airflow', 'fanspeed', 'oscillation', 'humidity_desired'),
            ),
            'air_purifier' => array(
                'label' => 'Purificateur d’air',
                'icon' => 'fas fa-wind',
                'accent' => '#16a085',
                'settings_title' => 'Réglages',
                'features' => array('power_', 'mode_', 'wind_strength', 'airflow', 'fanspeed', 'ailevel'),
            ),
            'dehumidifier' => array(
                'label' => 'Déshumidificateur',
                'icon' => 'fas fa-tint',
                'accent' => '#168aad',
                'settings_title' => 'Réglages',
                'features' => array('power_', 'mode_', 'fanspeed', 'humidity_desired', 'humidity_target'),
            ),
            'water_purifier' => array(
                'label' => 'Purificateur d’eau',
                'icon' => 'fas fa-tint',
                'accent' => '#0096c7',
                'settings_title' => 'Réglages',
                'features' => array('power_', 'mode_', 'water', 'temperature_desired', 'autofill'),
            ),
            'oven' => array(
                'label' => 'Four',
                'icon' => 'fas fa-fire-alt',
                'accent' => '#e76f51',
                'settings_title' => 'Cuisson',
                'features' => array('oven_', 'course_', 'mode_', 'temperature_desired', 'timer', 'preheat', 'steam', 'cavity'),
            ),
            'range' => array(
                'label' => 'Cuisinière',
                'icon' => 'fas fa-fire-alt',
                'accent' => '#e76f51',
                'settings_title' => 'Cuisson',
                'features' => array('oven_', 'cooktop', 'course_', 'mode_', 'temperature_desired', 'timer', 'preheat', 'steam', 'cavity'),
            ),
            'microwave' => array(
                'label' => 'Four micro-ondes',
                'icon' => 'fas fa-wave-square',
                'accent' => '#f4a261',
                'settings_title' => 'Cuisson',
                'features' => array('microwave', 'course_', 'mode_', 'timer', 'powerlevel', 'temperature_desired'),
            ),
            'cooktop' => array(
                'label' => 'Table de cuisson',
                'icon' => 'fas fa-fire',
                'accent' => '#ef476f',
                'settings_title' => 'Cuisson',
                'features' => array('cooktop', 'mode_', 'specialzone', 'burner', 'powerlevel', 'temperature_desired'),
            ),
            'induction_cooktop' => array(
                'label' => 'Table à induction',
                'icon' => 'fas fa-fire',
                'accent' => '#ef476f',
                'settings_title' => 'Cuisson',
                'features' => array('cooktop', 'mode_', 'specialzone', 'burner', 'powerlevel', 'temperature_desired'),
            ),
            'range_hood' => array(
                'label' => 'Hotte',
                'icon' => 'fas fa-fan',
                'accent' => '#6c757d',
                'settings_title' => 'Réglages',
                'features' => array('power_', 'hood_', 'fanspeed', 'upperlamp', 'sound'),
            ),
            'vacuum_station' => array(
                'label' => 'Station d’aspirateur',
                'icon' => 'fas fa-broom',
                'accent' => '#5f6f52',
                'settings_title' => 'Nettoyage',
                'features' => array('power_', 'mode_', 'clean', 'station', 'dust', 'empty'),
            ),
        );

        $deviceType = strtolower(trim((string) $deviceType));
        if (isset($profiles[$deviceType])) {
            return array_merge(array('type' => $deviceType), $profiles[$deviceType]);
        }
        return array(
            'type' => 'unknown',
            'label' => 'Appareil Samsung',
            'icon' => 'fas fa-microchip',
            'accent' => '#6c7a89',
            'settings_title' => 'Commandes',
            'features' => array('power_', 'mode_', 'temperature_desired', 'course_', 'cycle', 'delay_'),
        );
    }

    public static function group($deviceType, $entityKey, $commandType, $subType = '', $category = '', $name = '')
    {
        $identity = strtolower(
            (string) $entityKey . ' ' . (string) $name
        );
        $commandType = strtolower((string) $commandType);
        $category = strtolower((string) $category);
        $profile = self::profile($deviceType);

        if ($entityKey === '__refresh' || strpos($identity, 'rafraîchir') !== false || strpos($identity, 'refresh') !== false) {
            return 'refresh';
        }
        if ($entityKey === '__connected') {
            return 'hidden';
        }
        if ($commandType !== 'action' && self::isTechnicalDetail($identity)) {
            return 'hidden';
        }
        $maintenanceRole = self::maintenanceRole($entityKey, $name);
        if (
            $maintenanceRole !== ''
            && (
                $profile['type'] !== 'washer'
                || in_array(
                    $maintenanceRole,
                    array('drum_clean_status', 'drum_clean_threshold', 'washing_count', 'alarm'),
                    true
                )
            )
        ) {
            return 'maintenance';
        }

        if ($commandType === 'action') {
            if (self::containsAny($identity, $profile['features'])) {
                return 'settings';
            }
            if (
                preg_match('/(?:operational_controls|(?:^|\s|_)power_|\brun\b|démarrer|\bpause\b|arrêter|\bstop\b)/', $identity)
                || $profile['type'] === 'unknown'
            ) {
                return 'controls';
            }
            return self::isRelevantDetail($profile['type'], $identity, $commandType, $category)
                ? 'details'
                : 'hidden';
        }
        if (self::energyRole($entityKey, $name) !== '') {
            return 'energy';
        }
        if (self::statusSlot($entityKey, $name) !== '') {
            return 'status';
        }
        return self::isRelevantDetail($profile['type'], $identity, $commandType, $category)
            ? 'details'
            : 'hidden';
    }

    /**
     * Keeps the Information page intentional: it contains useful secondary
     * states, not every raw field exposed by the appliance firmware.
     */
    public static function isRelevantDetail($deviceType, $identity, $commandType = 'info', $category = '')
    {
        $deviceType = self::profile($deviceType)['type'];
        $identity = strtolower((string) $identity);
        $commandType = strtolower((string) $commandType);

        if (self::isTechnicalDetail($identity)) {
            return false;
        }
        $role = self::detailRole($identity);
        if ($commandType === 'action') {
            return in_array($role, array('child_lock', 'remote_control', 'filter'), true);
        }
        return $role !== '' && ($role !== 'tank' || $deviceType !== 'washer');
    }

    public static function detailRole($entityKey, $name = '')
    {
        $identity = strtolower((string) $entityKey . ' ' . (string) $name);
        if (preg_match('/(?:kids?lock|child[ _-]?lock)/', $identity)) {
            return strpos($identity, 'bypass') === false ? 'child_lock' : '';
        }
        if (preg_match('/remote(?:ctrl|control)/', $identity)) {
            return 'remote_control';
        }
        if (preg_match('/filter.*(?:life|status|usage|remind)/', $identity)) {
            return 'filter';
        }
        if (preg_match('/detergent.*alarm/', $identity)) {
            return 'detergent_alert';
        }
        if (preg_match('/softener.*alarm/', $identity)) {
            return 'softener_alert';
        }
        if (preg_match('/detergent.*(?:left|level)/', $identity)) {
            return 'detergent';
        }
        if (preg_match('/softener.*(?:left|level)/', $identity)) {
            return 'softener';
        }
        if (preg_match('/(?:error|fault|warning|leak)/', $identity)) {
            return 'alert';
        }
        if (preg_match('/(?:tank|reservoir|réservoir).*(?:level|status|empty|full)/', $identity)) {
            return 'tank';
        }
        return '';
    }

    /**
     * Adds Jeedom's native history hooks to the command widget returned by
     * cmd::toHtml(), without wrapping it in a second .cmd element.
     */
    public static function historizedCommandHtml($html, $commandId)
    {
        $count = 0;
        $html = preg_replace_callback(
            '/class=(["\'])([^"\']*\bcmd\b[^"\']*)\1/i',
            function ($matches) {
                $classes = preg_split('/\s+/', trim($matches[2]));
                $classes = array_values(array_filter($classes, function ($class) {
                    return !in_array(strtolower($class), array('cmd', 'history', 'cursor'), true);
                }));
                array_unshift($classes, 'cmd', 'history', 'cursor');
                return 'class=' . $matches[1] . implode(' ', $classes) . $matches[1];
            },
            (string) $html,
            1,
            $count
        );
        if ($count === 0) {
            return (string) $html;
        }
        return preg_replace_callback(
            '/<([a-z][a-z0-9:-]*)([^>]*\bclass=(["\'])cmd history cursor(?:\s[^"\']*)?\3[^>]*)>/i',
            function ($matches) use ($commandId) {
                $attributes = $matches[2];
                if (preg_match('/\bdata-cmd_id\s*=/', $attributes) !== 1) {
                    $attributes .= ' data-cmd_id="' . (int) $commandId . '"';
                }
                return '<' . $matches[1] . $attributes . '>';
            },
            $html,
            1
        );
    }

    public static function isPercentageUnit($unit)
    {
        $unit = strtolower(trim((string) $unit));
        return in_array($unit, array('%', 'percent', 'percentage', 'pourcentage'), true);
    }

    public static function percentageValue($value)
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }
        if (!is_numeric($value)) {
            return 0.0;
        }
        return max(0.0, min(100.0, (float) $value));
    }

    public static function isOperatingState($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return false;
        }
        $value = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
        $value = strtr($value, array(
            'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ç' => 'c', 'é' => 'e',
            'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
            'ö' => 'o', 'ô' => 'o', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        ));
        if (preg_match('/(?:^|\b)(?:ready|pret|idle|pause|paused|stop|stopped|arrete|finished|termine|complete|completed|off)(?:\b|$)/', $value)) {
            return false;
        }
        return preg_match(
            '/(?:^|\b)(?:run|running|en cours|en curso|lauft|working|washing|lavage|rinse|rincage|spin|essorage|drying|sechage|heating|chauffage|cooking|cuisson|cleaning|nettoyage|cooling|refroidissement|purifying|dehumidifying|operation)(?:\b|$)/',
            $value
        ) === 1;
    }

    private static function isTechnicalDetail($identity)
    {
        return preg_match(
            '/(?:supported|available|edit[ _-]?course|course.*table|washercourse|dryercourse|modelnum|serialnum|description|firmware|softwareversion|manufacturer|increment|dateutc|timestamp|device[ _-]?type|update.*allow|laundry.*out.*time|seamless.*control|usages.*db|most.*used|energy.*level.*set|special.*function|detergent.*(?:once|base|type)|drum.*clean.*log|time.*sync|(?:power|consumption).*(?:unit|date)|rawvalue)/',
            (string) $identity
        ) === 1;
    }

    public static function maintenanceRole($entityKey, $name = '')
    {
        $identity = strtolower((string) $entityKey . ' ' . (string) $name);
        if (strpos($identity, 'maintenance_drum_clean_status') !== false) {
            return 'drum_clean_status';
        }
        if (preg_match('/(?:drumclean|drum[ _-]clean).*proposal/', $identity)) {
            return 'drum_clean_threshold';
        }
        if (preg_match('/washingtimes|washing[ _-]times|lavages depuis/', $identity)) {
            return 'washing_count';
        }
        if (preg_match('/filter.*(?:life|status|remind|usage)/', $identity)) {
            return 'filter';
        }
        if (preg_match('/detergent.*(?:left|level)/', $identity)) {
            return 'detergent';
        }
        if (preg_match('/softener.*(?:left|level)/', $identity)) {
            return 'softener';
        }
        if (preg_match('/(?:^|[_\s\/-])alarms?(?:[_\s\/-]|$)/', $identity)) {
            return 'alarm';
        }
        if (preg_match('/(?:descal|selfclean|autoclean|tubclean)/', $identity)) {
            return 'cleaning';
        }
        return '';
    }

    public static function energyRole($entityKey, $name = '')
    {
        $identity = strtolower((string) $entityKey . ' ' . (string) $name);
        if (preg_match('/(?:power|consumption).*(?:unit|date|utc|timestamp)/', $identity)) {
            return '';
        }
        if (strpos($identity, 'instantaneouspower') !== false) {
            return 'current_power';
        }
        if (strpos($identity, 'cumulativepower') !== false || strpos($identity, 'cumulativeconsumption') !== false) {
            return 'total_energy';
        }
        if (strpos($identity, 'energykw') !== false) {
            return 'cycle_energy';
        }
        return '';
    }

    public static function presentation($deviceType, $entityKey, $commandType, $group, $name = '')
    {
        $deviceType = self::profile($deviceType)['type'];
        $identity = strtolower((string) $entityKey . ' ' . (string) $name);
        $presentation = array('label' => '', 'icon' => '', 'asset' => '');

        if (strpos($identity, 'washer_cycle') !== false) {
            return array('label' => 'Programme', 'icon' => '', 'asset' => 'washerCycle.svg');
        }
        if (strpos($identity, 'dryer_cycle') !== false) {
            return array('label' => 'Programme', 'icon' => '', 'asset' => 'dryerCycle.svg');
        }
        if (
            strpos($identity, 'course_') !== false
            && $group === 'settings'
            && preg_match('/(?:option_course|\bcycle\b|\bprogramme\b)/', $identity)
        ) {
            if (in_array($deviceType, array('oven', 'range', 'microwave'), true)) {
                return array('label' => 'Programme', 'icon' => '', 'asset' => 'ovenMode.svg');
            }
            $icon = $deviceType === 'dishwasher' ? 'fas fa-utensils' : 'fas fa-tshirt';
            return array('label' => 'Programme', 'icon' => $icon, 'asset' => '');
        }
        if (strpos($identity, 'watertemperature') !== false) {
            return array('label' => 'Température', 'icon' => '', 'asset' => 'temperature.svg');
        }
        if (strpos($identity, 'rinsecycles') !== false) {
            return array('label' => 'Rinçages', 'icon' => '', 'asset' => 'rinseCycles.svg');
        }
        if (strpos($identity, 'spinlevel') !== false) {
            return array('label' => 'Essorage', 'icon' => '', 'asset' => 'spinLevel.svg');
        }
        if (strpos($identity, 'bubblesoak') !== false) {
            return array('label' => 'Bubble Soak', 'icon' => '', 'asset' => 'washerBubbleSoak.svg');
        }
        if (strpos($identity, 'addwash') !== false) {
            return array('label' => 'Add Wash', 'icon' => 'fas fa-plus-circle', 'asset' => '');
        }
        if (strpos($identity, 'delay_') !== false) {
            return array('label' => 'Départ différé', 'icon' => 'fas fa-clock', 'asset' => '');
        }
        if (strpos($identity, 'temperature_desired') !== false || strpos($identity, 'setpoint') !== false) {
            return array('label' => 'Température', 'icon' => '', 'asset' => 'temperature.svg');
        }
        if (strpos($identity, 'drylevel') !== false) {
            return array('label' => 'Niveau de séchage', 'icon' => 'fas fa-layer-group', 'asset' => '');
        }
        if (preg_match('/(?:fanspeed|wind_strength|airflow)/', $identity)) {
            return array('label' => 'Ventilation', 'icon' => '', 'asset' => 'fanMode.svg');
        }
        if (preg_match('/(?:humidity_desired|humidity_target)/', $identity)) {
            return array('label' => 'Humidité souhaitée', 'icon' => 'fas fa-tint', 'asset' => '');
        }
        if (preg_match('/(?:dryingtime|timer)/', $identity)) {
            return array('label' => 'Durée', 'icon' => 'fas fa-clock', 'asset' => '');
        }
        if (strpos($identity, 'mode_') !== false && $group === 'settings') {
            if (in_array($deviceType, array('oven', 'range', 'microwave'), true)) {
                return array('label' => 'Mode', 'icon' => '', 'asset' => 'ovenMode.svg');
            }
            return array('label' => 'Mode', 'icon' => 'fas fa-cog', 'asset' => '');
        }
        if (strpos($identity, 'icemaker') !== false) {
            return array('label' => 'Glaçons', 'icon' => 'fas fa-cube', 'asset' => '');
        }
        if (strpos($identity, 'rapidfreez') !== false) {
            return array('label' => 'Congélation rapide', 'icon' => 'fas fa-snowflake', 'asset' => '');
        }
        if (strpos($identity, 'rapidfridge') !== false) {
            return array('label' => 'Refroidissement rapide', 'icon' => 'fas fa-temperature-low', 'asset' => '');
        }
        if (preg_match('/(?:upperlamp|displaylight)/', $identity)) {
            return array('label' => 'Éclairage', 'icon' => 'fas fa-lightbulb', 'asset' => '');
        }
        if (preg_match('/(?:specialzone|burner|powerlevel)/', $identity)) {
            return array('label' => 'Puissance', 'icon' => 'fas fa-fire', 'asset' => '');
        }

        $statusSlot = self::statusSlot($entityKey, $name);
        $statusPresentations = array(
            'state' => array('label' => 'État', 'icon' => 'fas fa-info-circle', 'asset' => ''),
            'remaining' => array('label' => 'Temps restant', 'icon' => 'fas fa-hourglass-half', 'asset' => ''),
            'progress' => array('label' => 'Progression', 'icon' => 'fas fa-tasks', 'asset' => ''),
            'power' => array('label' => 'Alimentation', 'icon' => 'fas fa-power-off', 'asset' => ''),
            'temperature' => array('label' => 'Température', 'icon' => 'fas fa-thermometer-half', 'asset' => ''),
            'door' => array('label' => 'Porte', 'icon' => 'fas fa-door-open', 'asset' => ''),
            'humidity' => array('label' => 'Humidité', 'icon' => 'fas fa-tint', 'asset' => ''),
            'quality' => array('label' => 'Qualité de l’air', 'icon' => 'fas fa-wind', 'asset' => ''),
            'level' => array('label' => 'Niveau', 'icon' => 'fas fa-water', 'asset' => ''),
        );
        if ($group === 'status' && isset($statusPresentations[$statusSlot])) {
            return $statusPresentations[$statusSlot];
        }

        $maintenancePresentations = array(
            'drum_clean_status' => array('label' => 'Nettoyage du tambour', 'icon' => 'fas fa-bell', 'asset' => ''),
            'drum_clean_threshold' => array('label' => 'Alerte après', 'icon' => 'fas fa-bullseye', 'asset' => ''),
            'washing_count' => array('label' => 'Depuis le dernier nettoyage', 'icon' => 'fas fa-redo', 'asset' => ''),
            'filter' => array('label' => 'État du filtre', 'icon' => 'fas fa-filter', 'asset' => ''),
            'detergent' => array('label' => 'Lessive', 'icon' => 'fas fa-soap', 'asset' => ''),
            'softener' => array('label' => 'Adoucissant', 'icon' => 'fas fa-tint', 'asset' => ''),
            'alarm' => array('label' => 'État des alarmes', 'icon' => 'fas fa-exclamation-triangle', 'asset' => ''),
            'cleaning' => array('label' => 'Nettoyage', 'icon' => 'fas fa-magic', 'asset' => ''),
        );
        $maintenanceRole = self::maintenanceRole($entityKey, $name);
        if ($group === 'maintenance' && isset($maintenancePresentations[$maintenanceRole])) {
            return $maintenancePresentations[$maintenanceRole];
        }

        $energyPresentations = array(
            'current_power' => array('label' => 'Puissance actuelle', 'icon' => 'fas fa-bolt', 'asset' => ''),
            'total_energy' => array('label' => 'Consommation totale', 'icon' => 'fas fa-chart-line', 'asset' => ''),
            'cycle_energy' => array('label' => 'Consommation du cycle', 'icon' => 'fas fa-leaf', 'asset' => ''),
        );
        $energyRole = self::energyRole($entityKey, $name);
        if ($group === 'energy' && isset($energyPresentations[$energyRole])) {
            return $energyPresentations[$energyRole];
        }

        if ($group === 'details') {
            $detailPresentations = array(
                'child_lock' => array('label' => 'Sécurité enfants', 'icon' => 'fas fa-lock', 'asset' => ''),
                'remote_control' => array('label' => 'Contrôle à distance', 'icon' => 'fas fa-wifi', 'asset' => ''),
                'filter' => array('label' => 'État du filtre', 'icon' => 'fas fa-filter', 'asset' => ''),
                'detergent' => array('label' => 'Lessive restante', 'icon' => 'fas fa-soap', 'asset' => ''),
                'softener' => array('label' => 'Adoucissant restant', 'icon' => 'fas fa-tint', 'asset' => ''),
                'detergent_alert' => array('label' => 'Alerte de lessive', 'icon' => 'fas fa-bell', 'asset' => ''),
                'softener_alert' => array('label' => 'Alerte d’adoucissant', 'icon' => 'fas fa-bell', 'asset' => ''),
                'alert' => array('label' => 'Alerte', 'icon' => 'fas fa-exclamation-triangle', 'asset' => ''),
                'tank' => array('label' => 'Réservoir', 'icon' => 'fas fa-water', 'asset' => ''),
            );
            $detailRole = self::detailRole($entityKey, $name);
            if (isset($detailPresentations[$detailRole])) {
                return $detailPresentations[$detailRole];
            }
        }

        if ($group === 'settings') {
            $presentation['label'] = trim((string) preg_replace('/\s+-\s+(?:Choisir|Régler|Allumer|Éteindre)$/u', '', $name));
            $presentation['icon'] = 'fas fa-sliders-h';
        } elseif ($group === 'controls') {
            if (preg_match('/démarrer|\brun\b/', $identity)) {
                $presentation['icon'] = 'fas fa-play';
            } elseif (strpos($identity, 'pause') !== false) {
                $presentation['icon'] = 'fas fa-pause';
            } elseif (preg_match('/arrêter|\bstop\b|\bready\b/', $identity)) {
                $presentation['icon'] = 'fas fa-stop';
            } elseif (strpos($identity, 'power_') !== false) {
                $presentation['icon'] = 'fas fa-power-off';
            }
        }
        return $presentation;
    }

    /**
     * Returns a stable slot used to keep only one useful value of each kind
     * on the main page. Other values remain available on the information page.
     */
    public static function statusSlot($entityKey, $name = '')
    {
        $identity = strtolower((string) $entityKey . ' ' . (string) $name);
        if (preg_match('/(?:remainingtime|remaining[ _-]time|temps restant|completiontime)/', $identity)) {
            return 'remaining';
        }
        if (preg_match('/(?:progresspercentage|progress[ _-]percentage|progression)/', $identity)) {
            return 'progress';
        }
        if (preg_match('/(?:operational_controls|operational.*state|machine.*state|job.*state|cavitystatus)/', $identity)) {
            return 'state';
        }
        if (preg_match('/(?:^|\s|_)power_(?:0_value|vs_0_power)|\balimentation\b/', $identity)) {
            return 'power';
        }
        if (preg_match('/(?:door.*state|contact.*state|état.*porte)/', $identity)) {
            return 'door';
        }
        if (preg_match('/(?:temperature_current|current.*temperature|roomtemperature|température actuelle)/', $identity)) {
            return 'temperature';
        }
        if (preg_match('/(?:humidity_current|current.*humidity|humidité actuelle)/', $identity)) {
            return 'humidity';
        }
        if (preg_match('/(?:airquality|air[ _-]quality|pm1|pm2|pm10|dustlevel)/', $identity)) {
            return 'quality';
        }
        if (preg_match('/(?:water.*level|tank.*level|niveau.*(?:eau|réservoir))/', $identity)) {
            return 'level';
        }
        return '';
    }

    public static function statusPriority($deviceType, $entityKey, $name = '')
    {
        $slot = self::statusSlot($entityKey, $name);
        $deviceType = self::profile($deviceType)['type'];
        $identity = strtolower((string) $entityKey . ' ' . (string) $name);
        $priorities = array(
            'state' => 10,
            'remaining' => 20,
            'progress' => 30,
            'power' => 35,
            'temperature' => 40,
            'door' => 50,
            'humidity' => 60,
            'quality' => 70,
            'level' => 80,
        );
        if ($deviceType === 'refrigerator') {
            $priorities['temperature'] = 10;
            $priorities['door'] = 20;
            $priorities['power'] = 30;
            $priorities['state'] = 40;
        } elseif (in_array($deviceType, array('airconditioner', 'dehumidifier'), true)) {
            $priorities['temperature'] = 10;
            $priorities['humidity'] = 20;
            $priorities['power'] = 30;
            $priorities['state'] = 40;
        } elseif ($deviceType === 'air_purifier') {
            $priorities['quality'] = 10;
            $priorities['humidity'] = 20;
            $priorities['power'] = 30;
            $priorities['state'] = 40;
        }
        $priority = $priorities[$slot] ?? 100;
        if (strpos($identity, 'operational_state_vs_0') !== false) {
            $priority -= 2;
        } elseif (strpos($identity, 'progress_time_set') !== false) {
            $priority += 5;
        }
        return $priority;
    }

    public static function supportedTypes()
    {
        return array(
            'washer', 'dryer', 'dishwasher', 'air_dresser', 'refrigerator',
            'airconditioner', 'air_purifier', 'dehumidifier', 'water_purifier',
            'oven', 'range', 'microwave', 'cooktop', 'induction_cooktop',
            'range_hood', 'vacuum_station',
        );
    }

    public static function priority($deviceType, $entityKey, $name = '')
    {
        $identity = strtolower((string) $entityKey . ' ' . (string) $name);
        $priorities = array(
            4 => array('alarms_vs', 'alarme'),
            5 => array('maintenance_drum_clean_status'),
            6 => array('drumcleanproposal', 'drum_clean_proposal'),
            7 => array('washingtimes', 'washing_times'),
            8 => array('instantaneouspower'),
            9 => array('cumulativepower'),
            10 => array('washer_cycle', 'dryer_cycle', 'mode_'),
            20 => array('watertemperature', 'temperature_desired', 'drylevel'),
            30 => array('rinsecycles', 'fanspeed', 'wind_strength', 'airflow'),
            40 => array('spinlevel', 'humidity_desired', 'powerlevel'),
            50 => array('addwash', 'bubblesoak', 'prewash', 'wrinkle', 'sanitize'),
            70 => array('operational_controls', 'run', 'démarrer'),
            71 => array('pause'),
            72 => array('ready', 'arrêter'),
            80 => array('delay_'),
            90 => array('power_'),
        );
        foreach ($priorities as $priority => $patterns) {
            if (self::containsAny($identity, $patterns)) {
                return $priority;
            }
        }
        $profile = self::profile($deviceType);
        return self::containsAny($identity, $profile['features']) ? 60 : 100;
    }

    private static function containsAny($identity, $patterns)
    {
        foreach ((array) $patterns as $pattern) {
            if ($pattern !== '' && strpos($identity, strtolower($pattern)) !== false) {
                return true;
            }
        }
        return false;
    }

}
