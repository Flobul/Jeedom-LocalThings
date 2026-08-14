<?php

require_once __DIR__ . '/../core/class/LocalThingsMapper.php';

function mapperCheck($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
        exit(1);
    }
}

$resources = array(
    '/information/vs/0' => array(
        'x.com.samsung.da.modelNum' => 'TP1X_DA-AC-RAC-01001',
        'x.com.samsung.da.description' => 'Samsung AC',
        'x.com.samsung.da.serialNum' => 'SERIAL',
    ),
    '/power/vs/0' => array(
        'x.com.samsung.da.power' => 'On',
    ),
    '/mode/vs/0' => array(
        'x.com.samsung.da.modes' => array('Cool'),
        'x.com.samsung.da.supportedModes' => array('Cool', 'Dry', 'Fan'),
    ),
    '/temperature/desired/0' => array(
        'temperature' => 22,
        'range' => array(16, 30),
        'increment' => 1,
    ),
    '/operational/state/vs/0' => array(
        'x.com.samsung.da.state' => 'Ready',
        'x.com.samsung.da.delayStartTime' => '02:30:00',
    ),
);

$mapper = new LocalThingsMapper();
mapperCheck(
    $mapper->deviceType($resources, array()) === 'airconditioner',
    'device type'
);
$mapped = $mapper->map($resources);
mapperCheck(count($mapped['entities']) > 5, 'entity count');
$mappedByName = array();
foreach ($mapped['entities'] as $entity) {
    $mappedByName[$entity['name']] = $entity;
}
mapperCheck($mappedByName['État']['value'] === 'Prêt', 'readable operational state');

$actionCount = 0;
$recipes = array();
foreach ($mapped['entities'] as $entity) {
    foreach ($entity['actions'] as $action) {
        $actionCount++;
        $recipes[] = $action['extra']['recipe'];
    }
}
mapperCheck($actionCount >= 8, 'action count');

$powerRecipe = null;
foreach ($recipes as $recipe) {
    if (($recipe['href'] ?? '') === '/power/vs/0') {
        $powerRecipe = $recipe;
        break;
    }
}
mapperCheck(is_array($powerRecipe), 'power recipe');
$write = $mapper->buildWrite($powerRecipe, false, $resources);
mapperCheck(
    $write['body'] === array('x.com.samsung.da.power' => 'Off'),
    'power write'
);
mapperCheck(
    !$mapper->remoteControlEnabled(array('/remotectrl/0' => array('value' => 'false'))),
    'textual false remote-control state'
);
mapperCheck(
    $mapper->remoteControlEnabled(array('/remotectrl/0' => array('value' => 'On'))),
    'textual true remote-control state'
);

$duplicateNameResources = array(
    '/operational/state/0' => array(
        'remainingTime' => '00:42:00',
    ),
    '/operational/state/vs/0' => array(
        'x.com.samsung.da.remainingTime' => '00:42:00',
    ),
);
$duplicateNameMap = $mapper->map($duplicateNameResources);
$entityNames = array_column($duplicateNameMap['entities'], 'name');
mapperCheck(
    count($entityNames) === count(array_unique($entityNames)),
    'duplicate command names are disambiguated'
);
mapperCheck(
    in_array('Temps restant', $entityNames, true),
    'first duplicate keeps its readable name'
);
mapperCheck(
    in_array('Temps restant (2)', $entityNames, true),
    'second duplicate gets a stable suffix'
);

$laundryResources = array(
    '/power/0' => array('value' => true),
    '/power/vs/0' => array('x.com.samsung.da.power' => 'On'),
    '/kidslock/0' => array('value' => false),
    '/kidslock/vs/0' => array('x.com.samsung.da.kidsLock' => 'Ready'),
    '/wm/setinfo/vs/0' => array(
        'x.com.samsung.da.isModelSettingPowerOnOff' => 'false',
    ),
);
$laundryMap = $mapper->map($laundryResources);
$actionsByName = array();
foreach ($laundryMap['entities'] as $entity) {
    $actionsByName[$entity['name']] = count($entity['actions']);
}
mapperCheck(
    ($actionsByName['Alimentation'] ?? -1) === 0,
    'power action hidden when firmware declares power control unsupported'
);
mapperCheck(
    !isset($actionsByName['Power - power']) && !isset($actionsByName['Kidslock - kids Lock']),
    'vendor fallback entities hidden when generic OCF resources exist'
);
mapperCheck(
    ($actionsByName['Sécurité enfants'] ?? 0) === 2,
    'generic OCF kids-lock remains writable'
);

$washerResources = array(
    '/washer/vs/0' => array(
        'x.com.samsung.da.waterTemperature' => '40',
        'x.com.samsung.da.supportedWaterTemperature' => array('Cold', '20', '40', '60'),
        'x.com.samsung.da.spinLevel' => '1400',
        'x.com.samsung.da.supportedSpinLevel' => array('RinseHold', 'NoSpin', '800', '1400'),
        'x.com.samsung.da.rinseCycles' => '2',
        'x.com.samsung.da.supportedRinseCycles' => array('0', '1', '2', '3'),
    ),
    '/course/vs/0' => array(
        'x.com.samsung.da.options' => array('Course_D0', 'BubbleSoak_Off', 'AddWash_On', 'EnergyKW_396'),
    ),
    '/wm/editcourse/vs/0' => array(
        'x.com.samsung.da.editCourseList' => 'EditCourseList_D0D2D4D5',
    ),
    '/st/washercourse/vs/0' => array(
        'x.com.samsung.da.st.courseTable' => 'Table_00',
    ),
    '/energyconsumption/0' => array(
        'cumulativePower' => 849000,
        'instantaneousPower' => -500,
    ),
    '/wm/statistics/vs/0' => array(
        'x.com.samsung.da.drumCleanProposal' => 40,
        'x.com.samsung.da.washingTimes' => 80,
        'x.com.samsung.da.drumCleanLog' => '2026-01-12T20:12:00',
    ),
);
$washerMap = $mapper->map($washerResources);
$washerByName = array();
foreach ($washerMap['entities'] as $entity) {
    $washerByName[$entity['name']] = $entity;
}

foreach (array('Température de lavage', 'Vitesse d’essorage', 'Nombre de rinçages') as $name) {
    mapperCheck(isset($washerByName[$name]), $name . ' entity');
    mapperCheck(
        count($washerByName[$name]['actions']) === 1
            && $washerByName[$name]['actions'][0]['subtype'] === 'select',
        $name . ' select action'
    );
}
mapperCheck($washerByName['Température de lavage']['unit'] === '°C', 'washer temperature unit');
mapperCheck($washerByName['Vitesse d’essorage']['unit'] === 'tr/min', 'washer spin unit');
mapperCheck(
    $washerByName['Températures de lavage disponibles']['unit'] === '',
    'supported temperature list has no scalar unit'
);
mapperCheck(isset($washerByName['Cycle']), 'washer cycle entity');
mapperCheck(
    isset($washerByName['Add Wash'])
        && count($washerByName['Add Wash']['actions']) === 2
        && $washerByName['Add Wash']['value'] === 1,
    'Add Wash switch entity'
);
mapperCheck(
    isset($washerByName['Consommation du cycle'])
        && $washerByName['Consommation du cycle']['value'] === '396'
        && $washerByName['Consommation du cycle']['unit'] === 'Wh',
    'cycle energy has a readable unit'
);
mapperCheck($washerByName['Cycle']['value'] === 'D0', 'washer current cycle code');
mapperCheck(
    $washerByName['Cycle']['actions'][0]['options'][0] === array('value' => 'D0', 'label' => 'Coton'),
    'Table_00 cycle label'
);
$cycleRecipe = $washerByName['Cycle']['actions'][0]['extra']['recipe'];
$cycleWrite = $mapper->buildWrite($cycleRecipe, 'D4', $washerResources);
mapperCheck(
    $cycleWrite['body'] === array('x.com.samsung.da.options' => array('Course_D4')),
    'cycle write payload'
);
$spinRecipe = $washerByName['Vitesse d’essorage']['actions'][0]['extra']['recipe'];
$spinWrite = $mapper->buildWrite($spinRecipe, '800', $washerResources);
mapperCheck(
    $spinWrite['body'] === array('x.com.samsung.da.spinLevel' => '800'),
    'spin-level write payload'
);
mapperCheck(
    $washerByName['Consommation cumulée']['unit'] === 'kWh'
        && $washerByName['Consommation cumulée']['value'] === 849.0
        && $washerByName['Puissance instantanée']['unit'] === 'W'
        && $washerByName['Puissance instantanée']['value'] === 0.0,
    'readable energy values and units'
);
mapperCheck(
    $washerByName['Nettoyage du tambour']['value'] === 'Nettoyage recommandé'
        && $washerByName['Alerte après']['unit'] === 'lavages'
        && $washerByName['Lavages depuis le dernier nettoyage']['unit'] === 'lavages',
    'interpreted drum-clean maintenance summary'
);

$fallbackMap = $mapper->map(array(
    '/power/vs/0' => array('x.com.samsung.da.power' => 'Off'),
));
mapperCheck(
    $fallbackMap['entities'][0]['subtype'] === 'binary'
        && $fallbackMap['entities'][0]['value'] === 0
        && $fallbackMap['entities'][0]['unit'] === '',
    'vendor power fallback normalized as a binary state'
);

$unitMap = $mapper->map(array(
    '/energyconsumption/0' => array(
        'cumulativePower' => 12.5,
        'cumulativeUnit' => 'kWh',
        'instantaneousPower' => 450,
        'instantaneousPowerUnit' => 'watt',
    ),
    '/electrical/0' => array(
        'voltage' => 230,
        'electricCurrent' => 1.8,
        'frequency' => 50,
    ),
    '/environment/0' => array(
        'humidity' => 46,
        'pressure' => 1013,
        'co2' => 720,
    ),
));
$unitByName = array();
foreach ($unitMap['entities'] as $entity) {
    $unitByName[$entity['name']] = $entity;
}
mapperCheck(
    $unitByName['Consommation cumulée']['value'] === 12.5
        && $unitByName['Consommation cumulée']['unit'] === 'kWh'
        && $unitByName['Puissance instantanée']['unit'] === 'W',
    'explicit energy units are normalized without double conversion'
);
mapperCheck(
    $unitByName['Tension']['unit'] === 'V'
        && $unitByName['Intensité']['unit'] === 'A'
        && $unitByName['Fréquence']['unit'] === 'Hz'
        && $unitByName['Humidité']['unit'] === '%'
        && $unitByName['Pression']['unit'] === 'hPa'
        && $unitByName['CO₂']['unit'] === 'ppm',
    'common numeric information units are inferred using Jeedom conventions'
);

$translationMap = $mapper->map(array(
    '/wm/status/vs/0' => array(
        'x.com.samsung.da.deviceType' => 167,
        'x.com.samsung.da.updateAllow' => 'NotAllowed',
        'x.com.samsung.da.laundryOutTime' => 0,
        'x.com.samsung.da.seamlessControl' => 'Disable',
        'x.com.samsung.da.kidsLockBypass' => 'On',
        'x.com.samsung.da.detergentOnce' => 1,
        'x.com.samsung.da.detergentLeft' => 0,
        'x.com.samsung.da.detergentBase' => 5,
        'x.com.samsung.da.detergentAlarm' => 'On',
        'x.com.samsung.da.detergentType' => 0,
        'x.com.samsung.da.detergentTotal' => 65,
        'x.com.samsung.da.specialFunction' => 5,
        'x.com.samsung.da.laundryPlannerUserSetTime' => 0,
        'x.com.samsung.da.energyLevelSet' => 1,
        'x.com.samsung.da.mostUsed' => 'D0',
        'x.com.samsung.da.usagesDb' => 'ok',
        'x.com.samsung.da.timeSync' => 'NotSupported',
    ),
));
$translatedNames = array_column($translationMap['entities'], 'name');
$translatedValues = array_column($translationMap['entities'], 'value', 'name');
foreach (
    array(
        'Type d’appareil', 'Mise à jour autorisée', 'Heure de fin du linge',
        'Contrôle continu', 'Contournement de la sécurité enfants',
        'Dose unique de lessive', 'Lessive restante', 'Dose de base de lessive',
        'Alerte de lessive', 'Type de lessive', 'Quantité totale de lessive',
        'Fonction spéciale', 'Heure planifiée', 'Niveau d’énergie',
        'Programme le plus utilisé', 'Base des utilisations',
        'Synchronisation de l’heure',
    ) as $translatedName
) {
    mapperCheck(in_array($translatedName, $translatedNames, true), 'translated command name ' . $translatedName);
}
mapperCheck(
    preg_match(
        '/(?:deviceType|updateAllow|laundryOutTime|seamlessControl|kidsLock|detergent|specialFunction|mostUsed|usagesDb|timeSync)/i',
        implode(' ', $translatedNames)
    ) === 0,
    'raw Samsung field names do not leak into generated command names'
);
mapperCheck(
    $translatedValues['Mise à jour autorisée'] === 'Non autorisé'
        && $translatedValues['Synchronisation de l’heure'] === 'Non pris en charge'
        && $translatedValues['Base des utilisations'] === 'OK',
    'common firmware values are translated into readable states'
);

$requiredCommandTranslations = array(
    'Activé', 'Désactivé', 'Alimentation', 'Consommation du cycle',
    'Contrôle à distance', 'Lessive restante', 'Mise à jour autorisée',
    'Non autorisé', 'Non pris en charge', 'Sécurité enfants',
    'Température de lavage', 'Vitesse d’essorage',
);
foreach (array('en_US', 'de_DE', 'es_ES') as $locale) {
    $translations = json_decode(
        file_get_contents(__DIR__ . '/../core/i18n/' . $locale . '.json'),
        true
    );
    $mapperTranslations = $translations['plugins/localthings/core/class/LocalThingsMapper.php'] ?? array();
    foreach ($requiredCommandTranslations as $translationKey) {
        mapperCheck(
            isset($mapperTranslations[$translationKey])
                && trim((string) $mapperTranslations[$translationKey]) !== '',
            $locale . ' command translation ' . $translationKey
        );
    }
}

$mapperSource = file_get_contents(dirname(__FILE__) . '/../core/class/LocalThingsMapper.php');
preg_match_all('/(?:->tr|__)\(\s*[\'\"]([^\'\"]+)[\'\"]/u', $mapperSource, $translationMatches);
foreach (array('en_US', 'de_DE', 'es_ES') as $locale) {
    $catalog = json_decode(
        file_get_contents(dirname(__FILE__) . '/../core/i18n/' . $locale . '.json'),
        true
    );
    $mapperTranslations = $catalog['plugins/localthings/core/class/LocalThingsMapper.php'] ?? array();
    foreach (array_unique($translationMatches[1]) as $label) {
        mapperCheck(
            isset($mapperTranslations[$label]) && trim((string) $mapperTranslations[$label]) !== '',
            $locale . ' translates mapper label: ' . $label
        );
    }
}

echo "Mapper tests: OK" . PHP_EOL;
