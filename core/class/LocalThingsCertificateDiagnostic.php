<?php

/**
 * Compare les profils de certificat client acceptés par un appareil.
 *
 * Le but est de distinguer deux échecs que les logs confondent : un appareil
 * qui refuse toute identité cliente, et un appareil qui en accepte une mais
 * rejette le profil AC14K_M du plugin. Chaque profil est essayé dans une session
 * DTLS distincte, puis une ressource protégée est lue. Le journal ne contient
 * ni certificat, ni clé, ni UUID : uniquement le profil, la suite négociée, le
 * code CoAP et la classe d'échec.
 *
 * Aucune écriture OCF n'est émise : ce diagnostic est strictement en lecture.
 */
class LocalThingsCertificateDiagnostic
{
    /**
     * Profils essayés, dans l'ordre. `none` est la référence : elle établit
     * si l'appareil accepte une session sans identité cliente.
     */
    public const PROFILES = array(
        'ac14k_m' => 'Certificat signé AC14K_M (profil actuel)',
        'self_signed' => 'Certificat autosigné (profil SmartThings-Local)',
        'none' => 'Aucune identité cliente (référence)',
    );

    /**
     * Suites essayées pour un profil donné.
     *
     * ECDSA/RSA décrit la signature du serveur, pas celle du certificat client.
     * Les deux suites permettent de tester les deux familles de serveurs.
     */
    private const CIPHERS = array(
        'ECDHE-ECDSA-AES128-GCM-SHA256:@SECLEVEL=0',
        'ECDHE-RSA-AES128-GCM-SHA256:@SECLEVEL=0',
    );

    private const HANDSHAKE_TIMEOUT = 6.0;
    private const PROBE_PATHS = array('/oic/sec/acl', '/device/0');
    // La session réémet jusqu'à trois fois : le budget couvre une retransmission
    // complète, sinon un appareil lent serait classé « aucune réponse ».
    private const PROBE_TIMEOUT = 12.0;

    private $openssl;
    private $certificateStore;
    private $rootCaPath;
    private $logger;
    private $sourcePortResolver;

    /**
     * @param string $openssl Chemin du binaire OpenSSL.
     * @param LocalThingsCertificateStore $certificateStore Magasin de certificats.
     * @param string $rootCaPath Autorité racine utilisée pour vérifier le serveur.
     * @param callable|null $logger Journaliseur facultatif.
     * @param callable|null $sourcePortResolver Résolveur de port source, injecté par les tests.
     */
    public function __construct(
        $openssl,
        LocalThingsCertificateStore $certificateStore,
        $rootCaPath,
        $logger = null,
        $sourcePortResolver = null
    ) {
        $this->openssl = (string) $openssl;
        $this->certificateStore = $certificateStore;
        $this->rootCaPath = (string) $rootCaPath;
        $this->logger = is_callable($logger) ? $logger : null;
        $this->sourcePortResolver = is_callable($sourcePortResolver) ? $sourcePortResolver : null;
    }

    /**
     * Compare tous les profils sur un endpoint DTLS.
     *
     * @param string $host Adresse IPv4 validée.
     * @param int $port Port DTLS.
     * @param string[]|null $only Restreint l'essai à ces profils. La production
     *        ne fournit rien et laisse tous les profils s'enchaîner.
     * @return array<int,array<string,mixed>> Un résultat par couple profil/suite.
     */
    public function compare($host, $port, ?array $only = null)
    {
        $host = $this->validateHost($host);
        $port = (int) $port;
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException(__('Port LocalThings invalide', __FILE__));
        }
        $this->log('info', sprintf(
            __('[Certificat comparatif] Début sur %1$s:%2$d ; lecture seule', __FILE__),
            $host,
            $port
        ));
        $profiles = $only === null ? array_keys(self::PROFILES) : array_values($only);
        foreach ($profiles as $profile) {
            if (!isset(self::PROFILES[$profile])) {
                throw new InvalidArgumentException(__('Profil de certificat inconnu', __FILE__) . $profile);
            }
        }
        $results = array();
        foreach ($profiles as $profile) {
            foreach (self::CIPHERS as $index => $cipher) {
                if ($profile === 'none' && $index > 0) {
                    // Sans identité cliente, la suite n'est pas une variable
                    // contrôlable par le plugin : un seul essai suffit.
                    break;
                }
                if (class_exists('LocalThingsDiscovery', false)) {
                    LocalThingsDiscovery::checkpoint(true);
                }
                $results[] = $this->attempt($host, $port, $profile, $cipher);
                // Un profil qui lit une ressource protégée a répondu : inutile
                // de épuiser les suites restantes pour cette identité.
                if ($results[count($results) - 1]['authorized']) {
                    break;
                }
            }
        }
        $this->log('info', '[Certificat comparatif] ' . $this->summary($results));
        return $results;
    }

    /**
     * Tente un profil et une suite, puis lit une ressource protégée.
     *
     * @param string $host Adresse IPv4 validée.
     * @param int $port Port DTLS.
     * @param string $profile Identifiant de profil.
     * @param string $cipher Suite offered.
     * @return array<string,mixed> Résultat synthétique.
     */
    private function attempt($host, $port, $profile, $cipher)
    {
        $label = self::PROFILES[$profile] ?? $profile;
        $shortCipher = strtok($cipher, ':');
        $result = array(
            'profile' => $profile,
            'label' => $label,
            'cipher' => $shortCipher,
            'handshake' => 'not_tested',
            'suite' => '',
            'read' => 'non tenté',
            'authorized' => false,
        );
        $this->log('info', '[Certificat comparatif] essai : ' . $label . ' ; suite=' . $shortCipher);
        $transport = null;
        try {
            list($certificatePath, $chainPath, $keyPath) = $this->identity($host, $profile);
            $transport = new LocalThingsDtlsClient(
                $this->openssl,
                $host,
                $port,
                $this->sourcePort($host),
                $certificatePath,
                $chainPath,
                $keyPath,
                $this->rootCaPath,
                function ($level, $message) { $this->log($level, $message); },
                // La référence `none` teste une session sans identité : c'est le
                // mode serveur seul, qui interdit toute présentation de feuille.
                $profile === 'none',
                array('cipher' => $cipher, 'allowEmptyChain' => $profile === 'self_signed')
            );
            $transport->connect(self::HANDSHAKE_TIMEOUT);
            $result['handshake'] = 'accepted';
            $result['suite'] = $this->negotiatedSuite($transport);
            $this->log('info', '[Certificat comparatif] ' . $label
                . ' : handshake accepté' . ($result['suite'] !== '' ? ' (' . $result['suite'] . ')' : ''));
            $read = $this->readProtected($transport);
            $result['read'] = $read['outcome'];
            $result['authorized'] = $read['authorized'];
            $this->log('info', '[Certificat comparatif] ' . $label
                . ' : lecture protégée ' . $read['outcome']
                . ($read['authorized'] ? ' ; profil accepté par cet appareil' : ''));
        } catch (LocalThingsDiscoveryCancelled $error) {
            throw $error;
        } catch (Throwable $error) {
            // Une erreur de préparation n'est pas un refus de l'appareil : elle
            // doit rester distincte, sinon un profil serait éliminé à tort.
            $preparation = $error instanceof InvalidArgumentException;
            $result['handshake'] = $preparation
                ? 'non exécuté'
                : (self::isCertificateRejection($error) ? 'refused (unknown_ca)' : 'failed');
            $this->log('info', '[Certificat comparatif] ' . $label . ' : ' . $result['handshake']
                . ' ; ' . self::failureKind($error));
        } finally {
            if ($transport !== null) {
                $transport->close();
            }
        }
        return $result;
    }

    /**
     * Construit l'identité cliente demandée par un profil.
     *
     * @param string $host Adresse IPv4 validée.
     * @param string $profile Identifiant de profil.
     * @return array{0:string,1:string,2:string} Certificat, chaîne et clé.
     */
    private function identity($host, $profile)
    {
        if ($profile === 'none') {
            // Une session sans identité ne présente ni feuille ni clé.
            return array('', '', '');
        }
        return $this->certificateStore->mintLeafForProfile('host:' . $host, $profile);
    }

    /**
     * Lit une ressource protégée pour éprouver l'autorisation.
     *
     * Seule une réponse 2.05 confirme la lecture de la ressource testée.
     * Un refus CoAP ne prouve pas que l'identité cliente est reconnue.
     * Les métadonnées publiques ne servent pas de preuve d'autorisation.
     *
     * @param LocalThingsDtlsClient $transport Transport déjà connecté.
     * @return array{outcome:string,authorized:bool}
     */
    private function readProtected($transport)
    {
        $session = new LocalThingsSession($transport);
        $outcome = 'aucune réponse exploitable';
        foreach (self::PROBE_PATHS as $path) {
            try {
                list($code) = $session->get(explode('/', ltrim($path, '/')), self::PROBE_TIMEOUT);
            } catch (LocalThingsDiscoveryCancelled $error) {
                throw $error;
            } catch (Throwable $error) {
                $this->log('info', '[Certificat comparatif] lecture ' . $path . ' : ' . self::failureKind($error));
                continue;
            }
            if ($code === 69) {
                return array('outcome' => 'autorisé (' . $path . ' 2.05)', 'authorized' => true);
            }
            if ($code === 132) {
                // Ressource absente : essayer l'autre ressource protégée.
                $outcome = 'ressource absente (' . $path . ' 4.04)';
                continue;
            }
            $this->log('info', '[Certificat comparatif] lecture ' . $path . ' -> '
                . LocalThingsCoap::formatCode($code));
            return array(
                'outcome' => 'refusée (' . $path . ' ' . LocalThingsCoap::formatCode($code) . ')',
                'authorized' => false
            );
        }
        return array('outcome' => $outcome, 'authorized' => false);
    }

    /**
     * Relit la suite réellement négociée, sans exposer le reste de la trace.
     *
     * @param LocalThingsDtlsClient $transport Transport connecté.
     * @return string
     */
    private function negotiatedSuite($transport)
    {
        $summary = $transport->errorSummary();
        if (preg_match('/Ciphersuite:\s*(\S+)/i', $summary, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * Résume les résultats sans valeur ni identifiant.
     *
     * @param array<int,array<string,mixed>> $results Résultats par essai.
     * @return string
     */
    private function summary(array $results)
    {
        $accepted = array();
        foreach ($results as $result) {
            if (!empty($result['authorized'])) {
                $accepted[] = ($result['label'] ?? $result['profile']) . ' (' . $result['cipher'] . ')';
            }
        }
        if (count($accepted) === 0) {
            return 'aucune lecture protégée autorisée ; consulter séparément les résultats de connexion et de lecture.';
        }
        return 'profils avec lecture protégée autorisée : ' . implode(' | ', $accepted) . '.';
    }

    /**
     * Distingue un refus de certificat d'un autre échec de négociation.
     *
     * @param Throwable $error Erreur capturée.
     * @return bool
     */
    private static function isCertificateRejection(Throwable $error)
    {
        if ($error instanceof LocalThingsClientCertificateRejected) {
            return true;
        }
        $message = $error->getMessage();
        return stripos($message, 'unknown_ca') !== false || stripos($message, 'unknown ca') !== false;
    }

    /**
     * Classe l'échec sans recopier de trace OpenSSL ni d'identité.
     *
     * @param Throwable $error Erreur capturée.
     * @return string
     */
    private static function failureKind(Throwable $error)
    {
        return LocalThingsOcfOnboardingDiagnostic::failureKind($error);
    }

    /**
     * Valide une adresse IPv4.
     *
     * @param string $host Adresse à valider.
     * @return string
     */
    private function validateHost($host)
    {
        $host = trim((string) $host);
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException(__('Adresse IPv4 invalide', __FILE__));
        }
        return $host;
    }

    /**
     * Choisit un port source stable, comme le client de découverte.
     *
     * @param string $host Adresse IPv4 validée.
     * @return int
     */
    private function sourcePort($host)
    {
        if ($this->sourcePortResolver !== null) {
            return (int) call_user_func($this->sourcePortResolver, $host);
        }
        $long = ip2long($host);
        $offset = $long === false ? hexdec(substr(hash('sha256', $host), 0, 4)) : $long & 0xFFFF;
        return 40000 + ($offset % 20000);
    }

    /**
     * Transmet un message au journaliseur injecté.
     *
     * @param string $level Niveau de journalisation.
     * @param string $message Message à écrire.
     * @return void
     */
    private function log($level, $message)
    {
        if ($this->logger === null) {
            return;
        }
        $message = preg_replace('/[\r\n]+/', ' ', (string) $message);
        call_user_func($this->logger, (string) $level, substr($message, 0, 1800));
    }
}
