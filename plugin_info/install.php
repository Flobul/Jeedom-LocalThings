<?php

function localthings_install()
{
    config::save('poll_interval', 5, 'localthings');
}

function localthings_update()
{
    $interval = (int) config::byKey('poll_interval', 'localthings', 5);
    $allowed = array(1, 2, 3, 4, 5, 10, 15, 20, 30, 45, 60, 120, 240, 360, 720, 1440);
    if (!in_array($interval, $allowed, true)) {
        $closest = 5;
        foreach ($allowed as $candidate) {
            if (abs($candidate - $interval) < abs($closest - $interval)) {
                $closest = $candidate;
            }
        }
        $interval = $closest;
    }
    config::save('poll_interval', $interval, 'localthings');
    config::remove('daemon_port', 'localthings');
}

function localthings_remove()
{
    // No long-running process is owned by the plugin.
}
