<?php

namespace Gab\Dropshipping\Model\Api;

use Gab\Dropshipping\Model\Config;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Cache\Frontend\Pool;
use Psr\Log\LoggerInterface;

class TokenManager
{
    const CACHE_KEY_PREFIX = 'cj_dropshipping_';
    const CACHE_KEY_ACCESS_TOKEN = self::CACHE_KEY_PREFIX . 'access_token';
    const CACHE_KEY_REFRESH_TOKEN = self::CACHE_KEY_PREFIX . 'refresh_token';
    const CACHE_KEY_TOKEN_EXPIRY = self::CACHE_KEY_PREFIX . 'token_expiry';
    const CACHE_KEY_LAST_ATTEMPT = self::CACHE_KEY_PREFIX . 'last_attempt';
    const CACHE_KEY_AUTH_ERROR = self::CACHE_KEY_PREFIX . 'auth_error';

    // Délai minimum entre les tentatives d'authentification (en secondes)
    const AUTH_THROTTLE_DELAY = 300; // 5 minutes

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var TypeListInterface
     */
    protected $cacheTypeList;

    /**
     * @var Pool
     */
    protected $cacheFrontendPool;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Config $config
     * @param Curl $curl
     * @param Json $json
     * @param TypeListInterface $cacheTypeList
     * @param Pool $cacheFrontendPool
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        Curl $curl,
        Json $json,
        TypeListInterface $cacheTypeList,
        Pool $cacheFrontendPool,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->curl = $curl;
        $this->json = $json;
        $this->cacheTypeList = $cacheTypeList;
        $this->cacheFrontendPool = $cacheFrontendPool;
        $this->logger = $logger;
    }

    /**
     * Get access token
     *
     * @return string|null
     */
    public function getAccessToken()
    {
        $cacheInstance = $this->getCache();

        // Vérifier s'il existe une erreur d'authentification récente
        $authError = $cacheInstance->load(self::CACHE_KEY_AUTH_ERROR);
        if ($authError) {
            // Si une erreur s'est produite récemment, vérifier si nous pouvons réessayer
            $lastAttempt = $cacheInstance->load(self::CACHE_KEY_LAST_ATTEMPT);
            if ($lastAttempt) {
                $timeSinceLastAttempt = time() - (int)$lastAttempt;
                if ($timeSinceLastAttempt < self::AUTH_THROTTLE_DELAY) {
                    $this->logger->debug('Authentification différée en raison d\'une erreur récente. Attente restante: ' .
                        (self::AUTH_THROTTLE_DELAY - $timeSinceLastAttempt) . ' secondes');
                    return null;
                }
            }
        }

        // Vérifier si un token existe et est valide
        $accessToken = $cacheInstance->load(self::CACHE_KEY_ACCESS_TOKEN);
        $tokenExpiry = $cacheInstance->load(self::CACHE_KEY_TOKEN_EXPIRY);

        // Si le token existe et n'est pas expiré, le retourner
        if ($accessToken && $tokenExpiry && time() < (int)$tokenExpiry) {
            return $accessToken;
        }

        // Si le token est expiré mais qu'un refresh token existe, essayer de rafraîchir
        $refreshToken = $cacheInstance->load(self::CACHE_KEY_REFRESH_TOKEN);
        if ($accessToken && $refreshToken) {
            try {
                $newToken = $this->refreshToken($refreshToken);
                if ($newToken) {
                    return $newToken;
                }
            } catch (\Exception $e) {
                $this->logger->error('Erreur lors du rafraîchissement du token: ' . $e->getMessage());
                // Continuer pour obtenir un nouveau token
            }
        }

        // Vérifier si nous pouvons demander un nouveau token
        if (!$this->canRequestNewToken()) {
            return null;
        }

        // Obtenir un nouveau token
        return $this->getNewToken();
    }

    /**
     * Vérifier si nous pouvons demander un nouveau token
     *
     * @return bool
     */
    protected function canRequestNewToken()
    {
        $cacheInstance = $this->getCache();
        $lastAttempt = $cacheInstance->load(self::CACHE_KEY_LAST_ATTEMPT);

        if ($lastAttempt) {
            $lastAttemptTime = (int)$lastAttempt;
            $timeSinceLastAttempt = time() - $lastAttemptTime;

            // Si moins de 5 minutes se sont écoulées depuis la dernière tentative
            if ($timeSinceLastAttempt < self::AUTH_THROTTLE_DELAY) {
                $this->logger->debug('Authentification limitée. Temps d\'attente restant: ' .
                    (self::AUTH_THROTTLE_DELAY - $timeSinceLastAttempt) . ' secondes');
                return false;
            }
        }

        // Enregistrer cette tentative
        $cacheInstance->save((string)time(), self::CACHE_KEY_LAST_ATTEMPT, [], 3600);
        return true;
    }

    /**
     * Obtenir un nouveau access token
     *
     * @return string|null
     */
    protected function getNewToken()
    {
        $apiUrl = rtrim($this->config->getApiUrl(), '/');
        $url = $apiUrl . '/authentication/getAccessToken';

        $email = $this->config->getEmail();
        $apiKey = $this->config->getApiKey();

        if (!$email || !$apiKey) {
            $this->logger->error('Email ou clé API manquante pour CJ Dropshipping');
            return null;
        }

        $this->curl->setHeaders([]);
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Accept', 'application/json');

        $data = [
            'email' => $email,
            'password' => $apiKey
        ];

        try {
            $this->logger->debug('Demande d\'un nouveau token d\'accès auprès de CJ Dropshipping', [
                'url' => $url,
                'email' => $email
            ]);

            $this->curl->post($url, $this->json->serialize($data));
            $response = $this->curl->getBody();
            $statusCode = $this->curl->getStatus();

            $this->logger->debug('Réponse du token', [
                'status_code' => $statusCode,
                'response' => $response
            ]);

            $parsedResponse = $this->json->unserialize($response);

            if (isset($parsedResponse['data']) && isset($parsedResponse['data']['accessToken'])) {
                // Sauvegarder les tokens dans le cache
                $accessToken = $parsedResponse['data']['accessToken'] ?? null;
                $refreshToken = $parsedResponse['data']['refreshToken'] ?? null;
                $expiresIn = 1296000; // 15 jours en secondes par défaut

                if (isset($parsedResponse['data']['accessTokenExpiryDate'])) {
                    // Calculer la durée à partir de la date d'expiration
                    $expiryDate = new \DateTime($parsedResponse['data']['accessTokenExpiryDate']);
                    $now = new \DateTime();
                    $interval = $now->diff($expiryDate);
                    $expiresIn = $interval->days * 86400 + $interval->h * 3600 + $interval->i * 60 + $interval->s;
                }

                if ($accessToken && $refreshToken) {
                    $this->saveTokens($accessToken, $refreshToken, $expiresIn);
                    $this->clearAuthError();
                    return $accessToken;
                }
            } else {
                $error = $parsedResponse['message'] ?? 'Erreur inconnue';
                $code = $parsedResponse['code'] ?? 0;

                // Sauvegarder l'erreur d'authentification
                $this->saveAuthError($error, $code);

                $this->logger->error('Échec de l\'obtention du token d\'accès: ' . $error);
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error('Exception lors de l\'obtention du token d\'accès: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            // Sauvegarder l'erreur d'authentification
            $this->saveAuthError($e->getMessage(), 0);

            return null;
        }
    }

    /**
     * Rafraîchir le token d'accès en utilisant le refresh token
     *
     * @param string $refreshToken
     * @return string|null
     * @throws \Exception
     */
    protected function refreshToken($refreshToken)
    {
        $apiUrl = rtrim($this->config->getApiUrl(), '/');
        $url = $apiUrl . '/authentication/refreshAccessToken';

        $this->curl->setHeaders([]);
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Accept', 'application/json');

        $data = [
            'refreshToken' => $refreshToken
        ];

        $this->logger->debug('Rafraîchissement du token d\'accès', [
            'url' => $url
        ]);

        try {
            $this->curl->post($url, $this->json->serialize($data));
            $response = $this->curl->getBody();
            $statusCode = $this->curl->getStatus();

            $this->logger->debug('Réponse du rafraîchissement du token', [
                'status_code' => $statusCode,
                'response' => $response
            ]);

            $parsedResponse = $this->json->unserialize($response);

            if (isset($parsedResponse['data']) && isset($parsedResponse['data']['accessToken'])) {
                // Sauvegarder les nouveaux tokens dans le cache
                $accessToken = $parsedResponse['data']['accessToken'] ?? null;
                $refreshToken = $parsedResponse['data']['refreshToken'] ?? null;
                $expiresIn = 1296000; // 15 jours en secondes par défaut

                if (isset($parsedResponse['data']['accessTokenExpiryDate'])) {
                    // Calculer la durée à partir de la date d'expiration
                    $expiryDate = new \DateTime($parsedResponse['data']['accessTokenExpiryDate']);
                    $now = new \DateTime();
                    $interval = $now->diff($expiryDate);
                    $expiresIn = $interval->days * 86400 + $interval->h * 3600 + $interval->i * 60 + $interval->s;
                }

                if ($accessToken && $refreshToken) {
                    $this->saveTokens($accessToken, $refreshToken, $expiresIn);
                    $this->clearAuthError();
                    return $accessToken;
                }
            } else {
                $error = $parsedResponse['message'] ?? 'Erreur inconnue';
                throw new \Exception('Échec du rafraîchissement du token: ' . $error);
            }

            return null;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Enregistrer les tokens dans le cache
     *
     * @param string $accessToken
     * @param string $refreshToken
     * @param int $expiresIn
     * @return void
     */
    protected function saveTokens($accessToken, $refreshToken, $expiresIn)
    {
        $cacheInstance = $this->getCache();

        // Calculer la date d'expiration (soustraire 1 heure pour avoir une marge de sécurité)
        $expiryTimestamp = time() + $expiresIn - 3600;

        // Sauvegarder les tokens et la date d'expiration dans le cache
        $cacheInstance->save($accessToken, self::CACHE_KEY_ACCESS_TOKEN, [], 86400); // 1 jour
        $cacheInstance->save($refreshToken, self::CACHE_KEY_REFRESH_TOKEN, [], 15552000); // 180 jours
        $cacheInstance->save((string)$expiryTimestamp, self::CACHE_KEY_TOKEN_EXPIRY, [], 86400); // 1 jour

        $this->logger->debug('Tokens API sauvegardés dans le cache', [
            'expiry' => date('Y-m-d H:i:s', $expiryTimestamp)
        ]);
    }

    /**
     * Enregistrer une erreur d'authentification
     *
     * @param string $errorMessage
     * @param int $errorCode
     * @return void
     */
    protected function saveAuthError($errorMessage, $errorCode)
    {
        $cacheInstance = $this->getCache();
        $errorData = [
            'message' => $errorMessage,
            'code' => $errorCode,
            'timestamp' => time()
        ];

        $cacheInstance->save($this->json->serialize($errorData), self::CACHE_KEY_AUTH_ERROR, [], 3600); // 1 heure
    }

    /**
     * Effacer l'erreur d'authentification
     *
     * @return void
     */
    protected function clearAuthError()
    {
        $cacheInstance = $this->getCache();
        $cacheInstance->remove(self::CACHE_KEY_AUTH_ERROR);
    }

    /**
     * Obtenir une instance de cache
     *
     * @return \Magento\Framework\App\Cache
     */
    protected function getCache()
    {
        return $this->cacheFrontendPool->get('config')->getBackend();
    }

    /**
     * Effacer le cache des tokens
     *
     * @return void
     */
    public function clearTokenCache()
    {
        $cacheInstance = $this->getCache();
        $cacheInstance->remove(self::CACHE_KEY_ACCESS_TOKEN);
        $cacheInstance->remove(self::CACHE_KEY_REFRESH_TOKEN);
        $cacheInstance->remove(self::CACHE_KEY_TOKEN_EXPIRY);
        $cacheInstance->remove(self::CACHE_KEY_AUTH_ERROR);
    }

    /**
     * Obtenir les informations sur le dernier délai d'authentification
     *
     * @return array
     */
    public function getAuthThrottleInfo()
    {
        $cacheInstance = $this->getCache();
        $lastAttempt = $cacheInstance->load(self::CACHE_KEY_LAST_ATTEMPT);
        $authError = $cacheInstance->load(self::CACHE_KEY_AUTH_ERROR);

        $info = [
            'canAuthenticate' => true,
            'waitTime' => 0,
            'errorMessage' => null
        ];

        if ($lastAttempt) {
            $lastAttemptTime = (int)$lastAttempt;
            $timeSinceLastAttempt = time() - $lastAttemptTime;

            if ($timeSinceLastAttempt < self::AUTH_THROTTLE_DELAY) {
                $info['canAuthenticate'] = false;
                $info['waitTime'] = self::AUTH_THROTTLE_DELAY - $timeSinceLastAttempt;
            }
        }

        if ($authError) {
            $errorData = $this->json->unserialize($authError);
            $info['errorMessage'] = $errorData['message'] ?? 'Erreur d\'authentification inconnue';
        }

        return $info;
    }
}
