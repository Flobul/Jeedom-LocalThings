<?php
if (!isConnect('admin')) {
    throw new Exception(__('401 - Accès non autorisé', __FILE__));
}
$plugin = plugin::byId('localthings');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>
<link rel="stylesheet" href="core/php/getResource.php?file=/plugins/localthings/desktop/css/localthings.css">

<div class="row row-overflow" id="div_localthings">
    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i><br>
                <span>{{Configuration}}</span>
            </div>
            <div class="cursor logoSecondary" id="bt_healthLocalthings">
                <i class="fas fa-medkit"></i><br>
                <span>{{Santé}}</span>
            </div>
            <div class="cursor logoPrimary" id="bt_scanLocalthings">
                <i class="fas fa-satellite-dish"></i><br>
                <span>{{Découvrir}}</span>
            </div>
        </div>

        <div class="localthings-discovery-bar">
            <div class="input-group">
                <input id="in_localthings_host" type="text" inputmode="decimal" class="form-control roundedLeft" placeholder="{{Adresse IPv4 de l’appareil}}">
                <span class="input-group-btn">
                    <button type="button" id="bt_probeLocalthings" class="btn btn-primary roundedRight">
                        <i class="fas fa-plus"></i> {{Ajouter par IP}}
                    </button>
                </span>
            </div>
            <div id="localthings-scan-progress" class="progress" style="display:none;">
                <div class="progress-bar progress-bar-striped active" role="progressbar" style="width:0%"></div>
            </div>
        </div>

        <legend><i class="fas fa-mobile-alt"></i> {{Mes appareils}}</legend>
        <div class="input-group" style="margin:5px;">
            <input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">
            <span class="input-group-btn">
                <button type="button" id="bt_resetSearch" class="btn btn-default roundedRight" title="{{Réinitialiser}}">
                    <i class="fas fa-times"></i>
                </button>
            </span>
        </div>
        <div class="eqLogicThumbnailContainer" id="localthings-device-list">
            <?php foreach ($eqLogics as $eqLogic) {
                $opacity = $eqLogic->getIsEnable() ? '' : 'disableCard';
            ?>
                <div class="eqLogicDisplayCard cursor <?php echo $opacity; ?>" data-eqLogic_id="<?php echo $eqLogic->getId(); ?>">
                    <img src="<?php echo $eqLogic->getImage(); ?>" alt="">
                    <br>
                    <span class="name"><?php echo $eqLogic->getHumanName(true, true); ?></span>
                </div>
            <?php } ?>
        </div>
        <?php if (count($eqLogics) === 0) { ?>
            <div class="alert alert-info text-center" id="localthings-empty-state">
                {{Aucun appareil local découvert. Configurez les certificats puis lancez une découverte.}}
            </div>
        <?php } ?>
    </div>

    <div class="col-xs-12 eqLogic" style="display:none;">
        <div class="input-group pull-right" style="display:inline-flex;">
            <span class="input-group-btn">
                <button type="button" class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure">
                    <i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
                </button>
                <button type="button" class="btn btn-sm btn-warning" id="bt_refreshLocalthings">
                    <i class="fas fa-sync"></i> {{Actualiser}}
                </button>
                <button type="button" class="btn btn-sm btn-success eqLogicAction" data-action="save">
                    <i class="fas fa-check-circle"></i> {{Sauvegarder}}
                </button>
                <button type="button" class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove">
                    <i class="fas fa-minus-circle"></i> {{Supprimer}}
                </button>
            </span>
        </div>
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation">
                <a href="#" class="eqLogicAction" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a>
            </li>
            <li role="presentation" class="active">
                <a href="#eqlogictab" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Équipement}}</a>
            </li>
            <li role="presentation">
                <a href="#commandtab" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a>
            </li>
        </ul>

        <div class="tab-content">
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <form class="form-horizontal">
                    <fieldset>
                        <div class="col-lg-6">
                            <legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Nom}}</label>
                                <div class="col-sm-7">
                                    <input type="hidden" class="eqLogicAttr" data-l1key="id">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="name">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Objet parent}}</label>
                                <div class="col-sm-7">
                                    <select class="eqLogicAttr form-control" data-l1key="object_id">
                                        <option value="">{{Aucun}}</option>
                                        <?php foreach (jeeObject::buildTree(null, false) as $object) {
                                            echo '<option value="' . $object->getId() . '">'
                                                . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber'))
                                                . htmlspecialchars($object->getName(), ENT_QUOTES, 'UTF-8')
                                                . '</option>';
                                        } ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Catégorie}}</label>
                                <div class="col-sm-8">
                                    <?php foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
                                        echo '<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="'
                                            . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">'
                                            . htmlspecialchars($value['name'], ENT_QUOTES, 'UTF-8') . '</label>';
                                    } ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Options}}</label>
                                <div class="col-sm-7">
                                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable">{{Activer}}</label>
                                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible">{{Visible}}</label>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">
                                    {{Contrôle sans Smart Control}}
                                    <sup><i class="fas fa-question-circle" title="{{Contourne le verrou de sécurité local pour les appareils qui acceptent certaines commandes même lorsque Smart Control est désactivé. À utiliser uniquement si nécessaire.}}"></i></sup>
                                </label>
                                <div class="col-sm-7">
                                    <label class="checkbox-inline">
                                        <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="bypass_remote_control">
                                        {{Autoriser}}
                                    </label>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">
                                    {{Widget}}
                                    <sup><i class="fas fa-question-circle" title="{{Le widget LocalThings organise les commandes natives Jeedom selon le type de l’appareil.}}"></i></sup>
                                </label>
                                <div class="col-sm-7">
                                    <select class="eqLogicAttr form-control" data-l1key="display" data-l2key="widgetTmpl">
                                        <option value="0">{{Widget du core Jeedom}}</option>
                                        <option value="1">{{Widget LocalThings}}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <legend><i class="fas fa-info-circle"></i> {{Informations locales}}</legend>
                            <?php
                            $fields = array(
                                'device_id' => __('Identifiant', __FILE__),
                                'host' => __('Adresse IP', __FILE__),
                                'port' => __('Port DTLS', __FILE__),
                                'manufacturer' => __('Fabricant', __FILE__),
                                'model' => __('Modèle', __FILE__),
                                'device_type' => __('Type', __FILE__),
                                'last_communication' => __('Dernière communication', __FILE__),
                                'last_error' => __('Dernière erreur', __FILE__),
                            );
                            foreach ($fields as $key => $label) { ?>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label"><?php echo $label; ?></label>
                                    <div class="col-sm-8">
                                        <span class="eqLogicAttr localthings-value" data-l1key="configuration" data-l2key="<?php echo $key; ?>"></span>
                                    </div>
                                </div>
                            <?php } ?>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Communication}}</label>
                                <div class="col-sm-8">
                                    <button type="button" class="btn btn-default" id="bt_testCommunicationLocalthings">
                                        <i class="fas fa-satellite-dish"></i> {{Tester la communication}}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </fieldset>
                </form>
            </div>
            <div role="tabpanel" class="tab-pane" id="commandtab">
                <div class="alert alert-info">
                    {{Les commandes sont générées depuis les capacités réellement annoncées par l’appareil. Les commandes personnalisées ne sont pas supprimées lors d’une resynchronisation.}}
                </div>
                <div class="table-responsive">
                    <table id="table_cmd" class="table table-bordered table-condensed">
                        <thead>
                            <tr>
                                <th class="hidden-xs">ID</th>
                                <th>{{Nom}}</th>
                                <th>{{Type}}</th>
                                <th>{{Options}}</th>
                                <th>{{État}}</th>
                                <th>{{Actions}}</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include_file('desktop', 'localthings', 'js', 'localthings'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
