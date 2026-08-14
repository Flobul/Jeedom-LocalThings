<?php

require_once __DIR__ . '/../core/class/LocalThingsWidget.php';

function widgetCheck($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

foreach (LocalThingsWidget::supportedTypes() as $deviceType) {
    $profile = LocalThingsWidget::profile($deviceType);
    widgetCheck($profile['type'] === $deviceType, 'missing widget profile for ' . $deviceType);
    widgetCheck($profile['icon'] !== '' && $profile['accent'] !== '', 'incomplete widget profile for ' . $deviceType);
}

widgetCheck(
    LocalThingsWidget::group('washer', 'washer_cycle_abcd', 'action', 'select') === 'settings',
    'washer cycle belongs to settings'
);
widgetCheck(
    LocalThingsWidget::group('washer', 'washer_vs_0_x_com_samsung_da_waterTemperature', 'action', 'select') === 'settings',
    'washer temperature belongs to settings'
);
widgetCheck(
    LocalThingsWidget::group('washer', 'course_vs_0_option_AddWash', 'action', 'other') === 'settings',
    'Add Wash action belongs to washer settings'
);
widgetCheck(
    LocalThingsWidget::group('washer', 'course_vs_0_option_AddWash', 'info', 'binary') === 'hidden',
    'Add Wash raw state is not duplicated on the information page'
);
widgetCheck(
    LocalThingsWidget::group('washer', 'washer_vs_0_x_com_samsung_da_supportedSpinLevel', 'info', 'string') === 'hidden',
    'supported values stay out of the widget'
);
widgetCheck(
    LocalThingsWidget::group('airconditioner', 'temperature_desired_0_temperature', 'action', 'slider') === 'settings',
    'air-conditioner setpoint belongs to settings'
);
widgetCheck(
    LocalThingsWidget::group('refrigerator', 'temperature_current_0_temperature', 'info', 'numeric') === 'status',
    'refrigerator current temperature belongs to status'
);
widgetCheck(
    LocalThingsWidget::group('washer', 'wm_status_drumCleanProposal', 'info', 'binary') === 'maintenance',
    'drum-clean information belongs to maintenance'
);
widgetCheck(
    LocalThingsWidget::group('washer', 'wm_status_detergentLeft', 'info', 'numeric') === 'details',
    'washer maintenance stays limited to drum-clean indicators'
);
widgetCheck(
    LocalThingsWidget::group('washer', 'energyconsumption_0_cumulativePower', 'info', 'numeric') === 'energy',
    'energy information belongs to consumption'
);
widgetCheck(
    LocalThingsWidget::group('washer', 'energyconsumption_0_cumulativeUnit', 'info', 'string') === 'hidden',
    'technical energy unit stays out of the widget'
);
widgetCheck(
    LocalThingsWidget::group('washer', 'energyconsumption_0_instantaneousPowerUnit', 'info', 'string') === 'hidden',
    'instantaneous-power unit stays out of the widget'
);
widgetCheck(
    LocalThingsWidget::group('washer', 'st_washercourse_st_courseTable', 'info', 'string') === 'hidden'
        && LocalThingsWidget::group('washer', 'device_type', 'info', 'numeric') === 'hidden',
    'firmware tables and device metadata stay out of the widget'
);
widgetCheck(
    LocalThingsWidget::group('washer', 'wm_status_detergentLeft', 'info', 'numeric') === 'details'
        && LocalThingsWidget::group('washer', 'wm_status_detergentBase', 'info', 'numeric') === 'hidden',
    'only useful detergent information is retained'
);
widgetCheck(
    LocalThingsWidget::group('washer', 'kidslock_0_value', 'action', 'other') === 'details',
    'secondary controls stay off the main page'
);
widgetCheck(
    LocalThingsWidget::group('unknown', '__refresh', 'action', 'other') === 'refresh',
    'refresh has its dedicated placement'
);
widgetCheck(
    LocalThingsWidget::group('washer', '__connected', 'info', 'binary') === 'hidden',
    'connection state is only represented by the health icon'
);
widgetCheck(
    LocalThingsWidget::priority('washer', 'washer_cycle_abcd')
        < LocalThingsWidget::priority('washer', 'washer_vs_0_x_com_samsung_da_waterTemperature'),
    'washer cycle is displayed before its options'
);
widgetCheck(
    LocalThingsWidget::priority('washer', 'washer_vs_0_x_com_samsung_da_waterTemperature')
        < LocalThingsWidget::priority('washer', 'course_vs_0_option_AddWash'),
    'washer temperature is displayed before optional switches'
);
widgetCheck(
    LocalThingsWidget::statusSlot('operational_state_vs_0_remainingTime') === 'remaining',
    'remaining time has a compact status slot'
);
widgetCheck(
    LocalThingsWidget::statusPriority('washer', 'operational_state_vs_0_remainingTime')
        < LocalThingsWidget::statusPriority('washer', 'operational_state_0_remainingTime'),
    'vendor operational status wins duplicate selection'
);
widgetCheck(
    LocalThingsWidget::maintenanceRole('maintenance_drum_clean_status') === 'drum_clean_status'
        && LocalThingsWidget::maintenanceRole('wm_statistics_drumCleanProposal') === 'drum_clean_threshold'
        && LocalThingsWidget::maintenanceRole('wm_statistics_washingTimes') === 'washing_count',
    'washer maintenance indicators are interpreted'
);
widgetCheck(
    LocalThingsWidget::detailRole('kidslock_0_value') === 'child_lock'
        && LocalThingsWidget::detailRole('wm_status_detergentLeft') === 'detergent'
        && LocalThingsWidget::detailRole('kidsLockBypass') === '',
    'useful details have semantic roles while technical variants do not'
);
widgetCheck(
    LocalThingsWidget::energyRole('energyconsumption_0_instantaneousPower') === 'current_power'
        && LocalThingsWidget::energyRole('energyconsumption_0_cumulativePower') === 'total_energy',
    'energy indicators are interpreted'
);
$cyclePresentation = LocalThingsWidget::presentation(
    'washer',
    'washer_cycle_abcd',
    'action',
    'settings',
    'Cycle'
);
widgetCheck(
    $cyclePresentation['label'] === 'Programme' && $cyclePresentation['asset'] === 'washerCycle.svg',
    'washer cycle uses its visual presentation'
);
$bubbleSoakPresentation = LocalThingsWidget::presentation(
    'washer',
    'course_vs_0_option_BubbleSoak',
    'action',
    'settings',
    'Bubble Soak'
);
widgetCheck(
    $bubbleSoakPresentation['label'] === 'Bubble Soak'
        && $bubbleSoakPresentation['asset'] === 'washerBubbleSoak.svg',
    'Bubble Soak keeps its dedicated presentation'
);
$addWashPresentation = LocalThingsWidget::presentation(
    'washer',
    'course_vs_0_option_AddWash',
    'action',
    'settings',
    'Add Wash'
);
widgetCheck(
    $addWashPresentation['label'] === 'Add Wash'
        && $addWashPresentation['icon'] === 'fas fa-plus-circle',
    'Add Wash keeps its dedicated presentation'
);
$detailPresentation = LocalThingsWidget::presentation(
    'washer',
    'wm_status_detergentLeft',
    'info',
    'details',
    'Detergent Left'
);
widgetCheck(
    $detailPresentation['label'] === 'Lessive restante'
        && $detailPresentation['icon'] === 'fas fa-soap',
    'useful secondary information receives a readable presentation'
);
$historyHtml = LocalThingsWidget::historizedCommandHtml(
    '<div class="cmd cmd-widget" data-cmd_id="42"><span class="value">12</span></div>',
    42
);
widgetCheck(
    strpos($historyHtml, 'class="cmd history cursor cmd-widget"') !== false
        && substr_count($historyHtml, 'data-cmd_id="42"') === 1,
    'history classes decorate the native command without duplicating its identifier'
);
$historyHtmlWithoutId = LocalThingsWidget::historizedCommandHtml(
    '<span class="cmd cmd-widget">12</span>',
    84
);
widgetCheck(
    strpos($historyHtmlWithoutId, 'class="cmd history cursor cmd-widget"') !== false
        && strpos($historyHtmlWithoutId, 'data-cmd_id="84"') !== false,
    'history decoration adds a missing Jeedom command identifier'
);
$dryerPresentation = LocalThingsWidget::presentation(
    'dryer',
    'dryer_cycle_abcd',
    'action',
    'settings',
    'Cycle'
);
widgetCheck($dryerPresentation['asset'] === 'dryerCycle.svg', 'dryer uses its visual presentation');

$requiredPlaceholders = array(
    '#refresh_id#', '#refresh_display#', '#health_id#', '#device_type#', '#status_commands#',
    '#settings_commands#', '#control_commands#', '#maintenance_commands#',
    '#energy_commands#', '#detail_commands#',
);
foreach (array('dashboard', 'mobile') as $version) {
    $templatePath = __DIR__ . '/../core/template/' . $version . '/localthings.device.template.html';
    $template = file_get_contents($templatePath);
    widgetCheck(is_string($template) && $template !== '', $version . ' template exists');
    foreach ($requiredPlaceholders as $placeholder) {
        widgetCheck(strpos($template, $placeholder) !== false, $version . ' template contains ' . $placeholder);
    }
    widgetCheck(substr_count($template, 'jeedom.cmd.execute') >= 2, $version . ' actions use Jeedom command API');
    widgetCheck(strpos($template, '.localthings-toggle-input') !== false, $version . ' binds On/Off toggle buttons');
    widgetCheck(strpos($template, 'jeedom.cmd.addUpdateFunction') !== false, $version . ' health follows core updates');
    widgetCheck(strpos($template, 'class="widget-name"') !== false, $version . ' uses Jeedom widget-name');
    widgetCheck(strpos($template, 'localthings-widget-header') === false, $version . ' has no custom header');
    widgetCheck(strpos($template, 'data-toggle="tab"') !== false, $version . ' uses core tab navigation');
    widgetCheck(strpos($template, 'data-target="#localthings-') !== false, $version . ' tabs target their page');
    widgetCheck(strpos($template, 'href="#localthings-') === false, $version . ' tabs cannot scroll the document');
    widgetCheck(strpos($template, '<button type="button"') !== false, $version . ' tabs are non-navigating buttons');
    widgetCheck(strpos($template, '{{Informations utiles}}') !== false, $version . ' labels the curated information page');
    widgetCheck(strpos($template, '{{Toutes les informations}}') === false, $version . ' no longer presents a raw information dump');
}

foreach (
    array(
        'washerCycle.svg', 'temperature.svg', 'rinseCycles.svg', 'spinLevel.svg',
        'washerBubbleSoak.svg', 'dryerCycle.svg',
        'fanMode.svg', 'ovenMode.svg',
    ) as $asset
) {
    widgetCheck(is_file(__DIR__ . '/../core/template/img/' . $asset), 'washer visual asset ' . $asset);
}

$equipmentClass = file_get_contents(__DIR__ . '/../core/class/localthings.class.php');
foreach (array('preToHtml(', '->toHtml(', 'getTemplate(', 'template_replace(') as $coreMethod) {
    widgetCheck(strpos($equipmentClass, $coreMethod) !== false, 'core rendering method ' . $coreMethod);
}
widgetCheck(
    strpos($equipmentClass, 'historizedCommandHtml') !== false
        && strpos($equipmentClass, 'getIsHistorized()') !== false,
    'historized numeric information uses Jeedom history classes'
);
widgetCheck(
    strpos($equipmentClass, '$actionEntityKeys') !== false
        && strpos($equipmentClass, 'deduplicateWidgetCommands') !== false,
    'widget rendering removes command and semantic duplicates'
);
widgetCheck(
    strpos($equipmentClass, 'localthings-widget-switch') !== false
        && strpos($equipmentClass, 'data-on-cmd_id') !== false
        && strpos($equipmentClass, 'data-off-cmd_id') !== false,
    'paired On/Off actions render as a single toggle'
);

$ajaxClass = file_get_contents(__DIR__ . '/../core/ajax/localthings.ajax.php');
$equipmentPage = file_get_contents(__DIR__ . '/../desktop/php/localthings.php');
$healthPage = file_get_contents(__DIR__ . '/../desktop/modal/health.php');
widgetCheck(
    strpos($ajaxClass, "case 'testCommunication':") !== false
        && strpos($equipmentClass, 'function testCommunication()') !== false
        && strpos($equipmentPage, 'bt_testCommunicationLocalthings') !== false,
    'equipment page exposes a complete communication test'
);
widgetCheck(
    strpos($healthPage, 'État du plugin') !== false
        && strpos($healthPage, 'table_healthLocalthings') !== false
        && strpos($healthPage, 'bt_testHealthCommunication') !== false,
    'health modal follows the common Jeedom plugin layout'
);

$configurationPage = file_get_contents(__DIR__ . '/../plugin_info/configuration.php');
preg_match_all('/<option value="(\d+)">/', $configurationPage, $pollIntervalMatches);
widgetCheck(
    strpos($configurationPage, '{{Intervalle de rafraîchissement}}') !== false
        && strpos($configurationPage, '<select class="configKey form-control" data-l1key="poll_interval">') !== false,
    'refresh interval uses a translated select field'
);
widgetCheck(
    array_map('intval', $pollIntervalMatches[1])
        === array(1, 2, 3, 4, 5, 10, 15, 20, 30, 45, 60, 120, 240, 360, 720, 1440),
    'refresh interval exposes only the supported minute values'
);

echo 'Widget tests: OK' . PHP_EOL;
