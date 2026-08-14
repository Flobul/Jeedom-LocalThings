<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$health = localthings::health();
?>
<div class="table-responsive">
    <table class="table table-condensed table-bordered">
        <thead>
            <tr>
                <th>{{Test}}</th>
                <th>{{Résultat}}</th>
                <th>{{Conseil}}</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($health as $row) { ?>
                <tr class="<?php echo !empty($row['state']) ? 'success' : 'danger'; ?>">
                    <td><?php echo htmlspecialchars((string) $row['test'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) $row['result'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) $row['advice'], ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
</div>
