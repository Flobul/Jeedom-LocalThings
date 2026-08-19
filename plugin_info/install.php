<?php

/**
 * Crée ou normalise la tâche Jeedom utilisée comme démon de rafraîchissement.
 *
 * @return void
 */
function localthings_configure_poll_cron()
{
    $cron = cron::byClassAndFunction('localthings', 'poll');
    if (!is_object($cron)) {
        $cron = new cron();
    }
    $cron->setClass('localthings');
    $cron->setFunction('poll');
    $cron->setEnable(1);
    $cron->setDeamon(1);
    $cron->setDeamonSleepTime(10);
    $cron->setSchedule('* * * * *');
    $cron->setTimeout(1440);
    $cron->save();
}

/**
 * Ramène une ancienne valeur d'intervalle vers un choix pris en charge.
 *
 * @param mixed $value Valeur à normaliser.
 * @param string $fallback Valeur de repli autorisée.
 * @return string
 */
function localthings_normalize_poll_interval($value, $fallback = '5')
{
    $allowed = array('10s', '20s', '30s', '1', '2', '3', '4', '5', '10', '15', '20', '30', '45', '60', '120', '240', '360', '720', '1440');
    $value = strtolower(trim((string) $value));
    if (in_array($value, $allowed, true)) {
        return $value;
    }

    $fallback = strtolower(trim((string) $fallback));
    if (!in_array($fallback, $allowed, true)) {
        $fallback = '5';
    }
    if (!is_numeric($value)) {
        return $fallback;
    }

    $intervalMinutes = (int) $value;
    $closest = is_numeric($fallback) ? (int) $fallback : 5;
    foreach (array(1, 2, 3, 4, 5, 10, 15, 20, 30, 45, 60, 120, 240, 360, 720, 1440) as $candidate) {
        if (abs($candidate - $intervalMinutes) < abs($closest - $intervalMinutes)) {
            $closest = $candidate;
        }
    }
    return (string) $closest;
}

/**
 * Initialise la configuration lors de l'installation du plugin.
 *
 * @return void
 */
function localthings_install()
{
    config::save('poll_interval_online', '1', 'localthings');
    config::save('poll_interval_offline', '5', 'localthings');
    config::remove('poll_interval', 'localthings');
    localthings_configure_poll_cron();
}

/**
 * Migre la configuration et recrée la tâche lors d'une mise à jour.
 *
 * @return void
 */
function localthings_update()
{
    $legacy = localthings_normalize_poll_interval(
        config::byKey('poll_interval', 'localthings', 5),
        '5'
    );
    $online = trim((string) config::byKey('poll_interval_online', 'localthings', ''));
    $offline = trim((string) config::byKey('poll_interval_offline', 'localthings', ''));

    config::save(
        'poll_interval_online',
        localthings_normalize_poll_interval($online === '' ? $legacy : $online, $legacy),
        'localthings'
    );
    config::save(
        'poll_interval_offline',
        localthings_normalize_poll_interval($offline === '' ? '5' : $offline, '5'),
        'localthings'
    );
    config::remove('poll_interval', 'localthings');
    config::remove('daemon_port', 'localthings');
    localthings_configure_poll_cron();
}

/**
 * Supprime la tâche de rafraîchissement lors de la désinstallation.
 *
 * @return void
 */
function localthings_remove()
{
    $cron = cron::byClassAndFunction('localthings', 'poll');
    if (is_object($cron)) {
        $cron->remove();
    }
}
