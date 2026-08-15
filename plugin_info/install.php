<?php

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

function localthings_install()
{
    config::save('poll_interval', 5, 'localthings');
    localthings_configure_poll_cron();
}

function localthings_update()
{
    $interval = trim((string) config::byKey('poll_interval', 'localthings', 2));
    $allowed = array('10s', '20s', '30s', '1', '2', '3', '4', '5', '10', '15', '20', '30', '45', '60', '120', '240', '360', '720', '1440');
    if (!in_array($interval, $allowed, true)) {
        $intervalMinutes = (int) $interval;
        $closest = 5;
        foreach (array(1, 2, 3, 4, 5, 10, 15, 20, 30, 45, 60, 120, 240, 360, 720, 1440) as $candidate) {
            if (abs($candidate - $intervalMinutes) < abs($closest - $intervalMinutes)) {
                $closest = $candidate;
            }
        }
        $interval = (string) $closest;
    }
    config::save('poll_interval', $interval, 'localthings');
    config::remove('daemon_port', 'localthings');
    localthings_configure_poll_cron();
}

function localthings_remove()
{
    $cron = cron::byClassAndFunction('localthings', 'poll');
    if (is_object($cron)) {
        $cron->remove();
    }
}
