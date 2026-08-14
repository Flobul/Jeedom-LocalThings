<?php

function localthings_install()
{
    config::save('poll_interval', 5, 'localthings');
}

function localthings_update()
{
    $interval = (int) config::byKey('poll_interval', 'localthings', 5);
    if ($interval > 60) {
        config::save('poll_interval', max(1, (int) round($interval / 60)), 'localthings');
    }
    config::remove('daemon_port', 'localthings');
}

function localthings_remove()
{
    // No long-running process is owned by the plugin.
}
