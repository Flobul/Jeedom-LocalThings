<?php

/** Étudie le parcours Samsung après unknown_ca, sans écrire de ressource OCF. */
class LocalThingsOcfOnboardingDiagnostic
{
    public static function inspect(LocalThingsSession $session, $host, callable $logger, $readSnapshot = null)
    {
        $logger('info', '[OCF sans certificat] début ; identité cliente absente ; vérification CA serveur active ; GET uniquement');
        $connected = false;
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
            $result = array('connected' => true, 'security' => $security, 'provisioning' => $provisioning);
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
            return array('connected' => $connected, 'error' => self::failureKind($error));
        } finally {
            $session->close();
        }
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
