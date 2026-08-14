<?php

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    require_once dirname(__FILE__) . '/../class/localthings.class.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    ajax::init();
    $action = (string) init('action');

    switch ($action) {
        case 'scan':
            ajax::success(localthings::synchronize());
            break;
        case 'scanStatus':
            ajax::success(localthings::scanStatus());
            break;
        case 'probe':
            ajax::success(localthings::probeHost((string) init('host')));
            break;
        case 'refresh':
            $eqLogic = localthings::byId((int) init('id'));
            if (!is_object($eqLogic)) {
                throw new Exception(__('Équipement LocalThings introuvable', __FILE__));
            }
            ajax::success($eqLogic->refresh());
            break;
        case 'testCommunication':
            $eqLogic = localthings::byId((int) init('id'));
            if (!is_object($eqLogic)) {
                throw new Exception(__('Équipement LocalThings introuvable', __FILE__));
            }
            ajax::success($eqLogic->testCommunication());
            break;
        case 'transportStatus':
            ajax::success(localthings::transportStatus());
            break;
        case 'certificateStatus':
            ajax::success(localthings::certificateStatus());
            break;
        case 'bootstrapCertificates':
            ajax::success(localthings::bootstrapCertificates());
            break;
        case 'installCertificates':
            ajax::success(
                localthings::installCertificates(
                    (string) init('certificate'),
                    (string) init('private_key')
                )
            );
            break;
        default:
            throw new Exception(__('Aucune méthode correspondante : ', __FILE__) . $action);
    }
} catch (Exception $exception) {
    ajax::error(displayException($exception), $exception->getCode());
}
