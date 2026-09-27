<?php

/** Étudie le parcours Samsung après unknown_ca, sans écrire de ressource OCF. */
class LocalThingsOcfOnboardingDiagnostic
{
    public static function inspect(LocalThingsSession $session, $host, callable $logger, $readSnapshot = null)
    {
        $logger('info', '[OCF sans certificat] début ; identité cliente absente ; vérification CA serveur active ; GET uniquement');
        $connected = false;
        $result = array('connected' => false);
        try {
            LocalThingsDiscovery::checkpoint(true);
            $session->connect(5.0);
            $connected = true;
            $logger('info', '[OCF sans certificat] handshake réussi ; chaîne serveur vérifiée ; accès aux ressources à vérifier');
            $reader = new LocalThingsOcfDiagnostic($host, 5683, $session);
            // Ce logger remplace uniquement le préfixe, sans exposer les URI dynamiques.
            $secureLogger = function ($level, $message) use ($logger) {
                $logger($level, str_replace(array('[OCF public]', '[OCF provisioning]'),
                    array('[OCF sans certificat]', '[OCF sans certificat/provisioning]'), $message));
            };
            $reader->discoverPorts($secureLogger);
            $security = $reader->inspect($secureLogger);
            $provisioning = $reader->inspectProvisioning($secureLogger);
            $doxm = $security['/oic/sec/doxm'] ?? array();
            $pstat = $security['/oic/sec/pstat'] ?? array();
            $state = 'état de sécurité incomplet';
            if (isset($doxm['owned'], $pstat['isop'])) {
                if ($doxm['owned'] === true) {
                    $state = 'appareil déjà associé ; lecture des états à vérifier sans réassociation';
                } elseif ($doxm['owned'] === false && $pstat['isop'] === false) {
                    $state = 'appareil non associé et non opérationnel ; méthode OTM et autorisation à vérifier';
                } else {
                    $state = 'état OCF non reconnu pour une association';
                }
            }
            $logger('info', '[OCF sans certificat] bilan : ' . $state
                . ' ; ressources de provisioning lues=' . count(array_filter($provisioning))
                . ' ; aucun reset, transfert de propriété ou OwnerPSK effectué');
            $result = array('connected' => true, 'security' => $security, 'provisioning' => $provisioning,
                'access_hint' => self::accessHint($security));
            $logger('info', '[OCF autorisation] ' . $result['access_hint']);
            if (is_callable($readSnapshot)) {
                $logger('info', '[OCF sans certificat] lecture des états métier avant création en lecture seule');
                $result['snapshot'] = $readSnapshot($session);
            }
            return $result;
        } catch (LocalThingsDiscoveryCancelled $error) {
            throw $error;
        } catch (Throwable $error) {
            $logger('info', '[OCF sans certificat] échec ; étape=' . ($connected ? 'lecture OCF' : 'handshake')
                . ' ; cause=' . self::failureKind($error));
            $result['connected'] = $connected;
            $result['error'] = self::failureKind($error);
            return $result;
        } finally {
            $session->close();
        }
    }

    /** La présence d'une ressource de provisioning ne constitue pas une autorisation. */
    public static function accessHint(array $security)
    {
        $doxm = $security['/oic/sec/doxm'] ?? array();
        $pstat = $security['/oic/sec/pstat'] ?? array();
        if (($doxm['owned'] ?? null) === true) {
            return 'Appareil déjà associé : la connexion sans certificat ne garantit pas l’accès aux états. '
                . 'Si les lectures sont refusées, une identité autorisée par cet appareil est nécessaire. '
                . 'Le parcours OwnerPSK documenté exige une autorisation d’association adaptée au modèle ; '
                . 'aucune réassociation automatique.';
        }
        if (($doxm['owned'] ?? null) === false && ($pstat['isop'] ?? null) === false
            && in_array($doxm['oxmsel'] ?? null, array(2, 65282), true)) {
            return 'État compatible avec une fenêtre constructeur OTM : autorisation et prise en charge du modèle '
                . 'restent à confirmer avant toute installation d’OwnerPSK. Lecture uniquement.';
        }
        return 'État d’association incomplet ou non reconnu ; accès aux états à vérifier par lecture.';
    }

    /** Classifier l'erreur sans recopier un certificat, une identité ou une trace OpenSSL. */
    public static function failureKind(Throwable $error)
    {
        $message = $error->getMessage();
        if (stripos($message, 'certificate verify failed') !== false
            || stripos($message, 'verify error') !== false) {
            return 'certificat serveur non vérifié';
        }
        if (preg_match('/(?:alert number |SSL alert number )(\d{1,3})/i', $message, $matches)) {
            return 'alerte DTLS ' . (int) $matches[1];
        }
        if (stripos($message, 'unknown_ca') !== false || stripos($message, 'unknown ca') !== false) {
            return 'alerte DTLS 48';
        }
        if (stripos($message, 'handshake failure') !== false) { return 'alerte handshake_failure'; }
        if (stripos($message, 'délai') !== false || stripos($message, 'timeout') !== false) {
            return 'délai dépassé';
        }
        return 'connexion ou lecture refusée ; détail privé non journalisé';
    }
}
