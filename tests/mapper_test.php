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
    in_array('Operational state - remaining Time', $entityNames, true),
    'first duplicate keeps its readable name'
);
mapperCheck(
    in_array('Operational state - remaining Time (2)', $entityNames, true),
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
        'x.com.samsung.da.options' => array('Course_D0', 'BubbleSoak_Off'),
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
    $washerByName['Energyconsumption - cumulative Power']['unit'] === 'Wh'
        && $washerByName['Energyconsumption - instantaneous Power']['unit'] === 'W',
    'energy units'
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

echo "Mapper tests: OK" . PHP_EOL;
