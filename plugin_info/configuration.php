<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
?>

<div class="alert alert-info">
    <i class="fas fa-network-wired"></i>
    {{LocalThings communique directement avec les appareils Samsung compatibles sur le réseau local. Aucun compte SmartThings ni jeton cloud n’est requis.}}
</div>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    {{Un appareil Samsung n’accepte qu’un client DTLS local actif. Désactivez les autres intégrations LocalThings qui contrôlent les mêmes appareils.}}
</div>

<form class="form-horizontal">
    <fieldset>
        <legend><i class="fas fa-search-location"></i> {{Découverte et communication}}</legend>
        <div class="form-group">
            <label class="col-sm-4 control-label">
                {{Réseaux IPv4 à analyser}}
                <sup><i class="fas fa-question-circle" title="{{Un ou plusieurs réseaux CIDR séparés par une virgule, par exemple 192.168.1.0/24. Si le champ est vide, le sous-réseau /24 de l’adresse interne Jeedom est utilisé. La découverte est limitée à 1 024 adresses.}}"></i></sup>
            </label>
            <div class="col-sm-5">
                <input type="text" class="configKey form-control" data-l1key="discovery_networks" placeholder="192.168.1.0/24">
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-4 control-label">{{Intervalle de lecture}}</label>
            <div class="col-sm-2">
                <div class="input-group">
                    <input type="number" min="1" max="1440" step="1" class="configKey form-control" data-l1key="poll_interval" placeholder="5">
                    <span class="input-group-addon">{{min}}</span>
                </div>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-4 control-label">{{Transport DTLS}}</label>
            <div class="col-sm-6">
                <span id="localthings-transport-state" class="label label-default">{{Inconnu}}</span>
                <button type="button" id="bt_localthings_test_transport" class="btn btn-default btn-sm">
                    <i class="fas fa-stethoscope"></i> {{Tester}}
                </button>
                <p class="help-block">
                    {{Les requêtes CoAP et CBOR sont gérées en PHP. OpenSSL fournit uniquement le canal chiffré DTLS.}}
                </p>
            </div>
        </div>

        <legend><i class="fas fa-certificate"></i> {{Certificats DTLS}}</legend>
        <div class="form-group">
            <label class="col-sm-4 control-label">{{État}}</label>
            <div class="col-sm-7">
                <span id="localthings-certificate-state" class="label label-default">{{Inconnu}}</span>
                <span id="localthings-certificate-detail" class="help-block"></span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-4 control-label">{{Installation simplifiée}}</label>
            <div class="col-sm-7">
                <button type="button" id="bt_localthings_bootstrap_certificates" class="btn btn-warning">
                    <i class="fas fa-download"></i> {{Installer le bundle communautaire}}
                </button>
                <p class="help-block">
                    {{Télécharge le bundle public utilisé par le projet LocalThings, puis le stocke localement avec des permissions restrictives.}}
                </p>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-4 control-label">{{Installation manuelle}}</label>
            <div class="col-sm-7">
                <button type="button" id="bt_localthings_toggle_pem" class="btn btn-default">
                    <i class="fas fa-key"></i> {{Saisir un certificat et une clé}}
                </button>
            </div>
        </div>
        <div id="localthings-pem-panel" style="display:none;">
            <div class="form-group">
                <label class="col-sm-4 control-label">{{Chaîne de certificats PEM}}</label>
                <div class="col-sm-7">
                    <textarea id="in_localthings_certificate" class="form-control" rows="9" autocomplete="off" spellcheck="false"></textarea>
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-4 control-label">{{Clé privée PEM}}</label>
                <div class="col-sm-7">
                    <textarea id="in_localthings_private_key" class="form-control" rows="9" autocomplete="new-password" spellcheck="false"></textarea>
                    <p class="help-block">{{Ces champs ne sont pas enregistrés dans le navigateur ni dans la configuration Jeedom.}}</p>
                </div>
            </div>
            <div class="form-group">
                <div class="col-sm-7 col-sm-offset-4">
                    <button type="button" id="bt_localthings_install_certificates" class="btn btn-success">
                        <i class="fas fa-check"></i> {{Installer les certificats}}
                    </button>
                </div>
            </div>
        </div>
    </fieldset>
</form>

<?php include_file('desktop', 'configuration', 'js', 'localthings'); ?>
