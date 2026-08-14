<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}

function localthingsHealthEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$checks = localthings::health();
$eqLogics = localthings::byType('localthings');
$enabled = 0;
$online = 0;
foreach ($eqLogics as $eqLogic) {
    if (!$eqLogic->getIsEnable()) {
        continue;
    }
    $enabled++;
    $connected = $eqLogic->getCmd('info', 'connected');
    if (is_object($connected) && (int) $connected->execCmd() === 1) {
        $online++;
    }
}
?>
<div id="div_healthLocalthings">
    <div class="clearfix" style="margin-bottom:10px;">
        <button type="button" class="btn btn-default pull-right" id="bt_refreshHealthLocalthings">
            <i class="fas fa-sync"></i> {{Rafraîchir}}
        </button>
        <span class="label label-success" style="font-size:1em;"><?php echo (int) $online; ?> {{En ligne}}</span>
        <span class="label label-danger" style="font-size:1em;"><?php echo max(0, $enabled - $online); ?> {{Hors ligne}}</span>
        <span class="label label-info" style="font-size:1em;"><?php echo count($eqLogics); ?> {{Appareils}}</span>
    </div>

    <legend><i class="fas fa-heartbeat"></i> {{État du plugin}}</legend>
    <div class="table-responsive">
        <table class="table table-condensed table-bordered">
            <thead>
                <tr>
                    <th>{{Contrôle}}</th>
                    <th>{{État}}</th>
                    <th>{{Détail}}</th>
                    <th>{{Conseil}}</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($checks as $row) { ?>
                    <tr>
                        <td><?php echo localthingsHealthEscape($row['test']); ?></td>
                        <td>
                            <span class="label label-<?php echo !empty($row['state']) ? 'success' : 'danger'; ?>">
                                <?php echo !empty($row['state']) ? 'OK' : 'NOK'; ?>
                            </span>
                        </td>
                        <td><?php echo localthingsHealthEscape($row['result']); ?></td>
                        <td><?php echo localthingsHealthEscape($row['advice']); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <legend><i class="fas fa-microchip"></i> {{Équipements}}</legend>
    <div class="table-responsive">
        <table class="table table-condensed table-bordered table-striped" id="table_healthLocalthings">
            <thead>
                <tr>
                    <th>{{Nom}}</th>
                    <th>{{Type}}</th>
                    <th>{{Modèle}}</th>
                    <th>{{Adresse}}</th>
                    <th>{{État}}</th>
                    <th>{{Dernière communication}}</th>
                    <th>{{Dernière erreur}}</th>
                    <th>{{Actions}}</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eqLogics as $eqLogic) {
                    $connected = $eqLogic->getCmd('info', 'connected');
                    $isOnline = $eqLogic->getIsEnable()
                        && is_object($connected)
                        && (int) $connected->execCmd() === 1;
                    $host = (string) $eqLogic->getConfiguration('host', '');
                    $port = (int) $eqLogic->getConfiguration('port', 0);
                    $lastCommunication = (string) $eqLogic->getConfiguration('last_communication', '');
                    $lastError = (string) $eqLogic->getConfiguration('last_error', '');
                ?>
                    <tr<?php echo $eqLogic->getIsEnable() ? '' : ' style="opacity:.55;"'; ?>>
                        <td>
                            <a href="<?php echo localthingsHealthEscape($eqLogic->getLinkToConfiguration()); ?>">
                                <?php echo localthingsHealthEscape($eqLogic->getHumanName(true)); ?>
                            </a>
                        </td>
                        <td><?php echo localthingsHealthEscape($eqLogic->getConfiguration('device_type', '')); ?></td>
                        <td><?php echo localthingsHealthEscape($eqLogic->getConfiguration('model', '')); ?></td>
                        <td><code><?php echo localthingsHealthEscape($host . ($port > 0 ? ':' . $port : '')); ?></code></td>
                        <td>
                            <?php if (!$eqLogic->getIsEnable()) { ?>
                                <span class="label label-default">{{Désactivé}}</span>
                            <?php } elseif ($isOnline) { ?>
                                <span class="label label-success">{{En ligne}}</span>
                            <?php } else { ?>
                                <span class="label label-danger">{{Hors ligne}}</span>
                            <?php } ?>
                        </td>
                        <td><?php echo localthingsHealthEscape($lastCommunication ?: '—'); ?></td>
                        <td class="text-danger" style="max-width:280px;overflow-wrap:anywhere;">
                            <?php echo localthingsHealthEscape($lastError ?: '—'); ?>
                        </td>
                        <td>
                            <button type="button" class="btn btn-default btn-xs bt_testHealthCommunication"
                                    data-eqlogic_id="<?php echo (int) $eqLogic->getId(); ?>"
                                    title="{{Tester la communication}}">
                                <i class="fas fa-satellite-dish"></i>
                            </button>
                        </td>
                    </tr>
                <?php } ?>
                <?php if (count($eqLogics) === 0) { ?>
                    <tr><td colspan="8" class="text-center text-muted">{{Aucun équipement LocalThings}}</td></tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php include_file('desktop', 'health', 'js', 'localthings'); ?>
