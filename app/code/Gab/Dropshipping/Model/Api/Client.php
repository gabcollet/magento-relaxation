<?php

namespace Gab\Dropshipping\Model\Api;

use Gab\Dropshipping\Model\Config;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

class Client
{
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
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var TokenManager
     */
    protected $tokenManager;

    /**
     * @param Config $config
     * @param Curl $curl
     * @param Json $json
     * @param LoggerInterface $logger
     * @param TokenManager $tokenManager
     */
    public function __construct(
        Config          $config,
        Curl            $curl,
        Json            $json,
        LoggerInterface $logger,
        TokenManager    $tokenManager
    )
    {
        $this->config = $config;
        $this->curl = $curl;
        $this->json = $json;
        $this->logger = $logger;
        $this->tokenManager = $tokenManager;
    }

    /**
     * Get products from CJ Dropshipping
     *
     * @param int $page
     * @param int $limit
     * @param string|null $searchTerm
     * @param array $categories
     * @return array
     */
    public function getProducts($page = 1, $limit = 10, $searchTerm = null, $categories = [])
    {
        $limit = max(10, $limit); // Minimum page size for CJ API is 10

        // Générer une clé de cache basée sur les paramètres de recherche
        $cacheKey = 'cj_products_' . md5($page . '_' . $limit . '_' . $searchTerm . '_' . implode(',', $categories));
        $cache = $this->getCacheInstance();
        $cachedResult = $cache->load($cacheKey);

        if ($cachedResult) {
            return $this->json->unserialize($cachedResult);
        }

        $params = [
            'pageNum' => $page,
            'pageSize' => $limit
        ];

        if ($searchTerm) {
            $params['productNameEn'] = $searchTerm;
        }

        if (!empty($categories)) {
            $params['categoryId'] = implode(',', $categories);
        }

        $response = $this->sendRequest('GET', '/product/list', $params);

        // Mettre en cache le résultat pour 5 minutes (300 secondes)
        if (isset($response['data'])) {
            $cache->save($this->json->serialize($response), $cacheKey, [], 300);
        }

        return $response;
    }

    /**
     * Get product details
     *
     * @param string $pid
     * @return array
     */
    public function getProductDetails($pid)
    {
        return $this->sendRequest('GET', '/product/query', [
            'pid' => $pid
        ]);
    }

    /**
     * Get product variants
     *
     * @param string $pid
     * @return array
     */
    public function getProductVariants($pid)
    {
        return $this->sendRequest('GET', '/product/variant/query', [
            'pid' => $pid
        ]);
    }

    /**
     * Create order in CJ Dropshipping
     *
     * @param array $orderData
     * @return array
     */
    public function createOrder($orderData)
    {
        return $this->sendRequest('POST', '/shopping/order/create', [], $orderData);
    }

    /**
     * Get order details
     *
     * @param string $orderId
     * @return array
     */
    public function getOrderDetails($orderId)
    {
        return $this->sendRequest('GET', '/shopping/order/detail', [
            'orderId' => $orderId
        ]);
    }

    /**
     * Get tracking information
     *
     * @param string $orderId
     * @return array
     */
    public function getTrackingInfo($orderId)
    {
        return $this->sendRequest('GET', '/logistics/tracking', [
            'orderId' => $orderId
        ]);
    }

    /**
     * Send request to API
     *
     * @param string $method
     * @param string $endpoint
     * @param array $params
     * @param array $data
     * @param int $retryCount
     * @return array
     */
    protected function sendRequest($method, $endpoint, $params = [], $data = [], $retryCount = 0)
    {
        if (!$this->config->isEnabled()) {
            return ['error' => 'Module is disabled'];
        }

        // Maximum de tentatives
        if ($retryCount > 2) {
            return ['error' => 'Nombre maximum de tentatives atteint'];
        }

        $apiUrl = rtrim($this->config->getApiUrl(), '/');
        $url = $apiUrl . $endpoint;

        // Ajouter les paramètres de requête si présents
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        // Réinitialiser les en-têtes pour éviter les doublons
        $this->curl->setHeaders([]);

        // Définir les en-têtes
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Accept', 'application/json');

        // Obtenir et ajouter le token d'accès
        $accessToken = $this->tokenManager->getAccessToken();
        if (!$accessToken) {
            // Si nous ne pouvons pas obtenir de token, vérifier les informations de throttling
            $throttleInfo = $this->tokenManager->getAuthThrottleInfo();
            if (!$throttleInfo['canAuthenticate']) {
                return [
                    'error' => 'Limitation des requêtes API: veuillez attendre ' .
                        $throttleInfo['waitTime'] . ' secondes avant de réessayer.',
                    'canRetry' => false
                ];
            }

            return [
                'error' => 'Impossible d\'obtenir un token d\'accès. Veuillez vérifier votre clé API et votre email.',
                'canRetry' => false
            ];
        }

        $this->curl->addHeader('CJ-Access-Token', $accessToken);

        try {
            // Journaliser la requête pour le débogage
            $this->logger->debug('CJ Dropshipping API Request', [
                'url' => $url,
                'method' => $method,
                'params' => $params
            ]);

            // Envoyer la requête
            if ($method === 'GET') {
                $this->curl->get($url);
            } elseif ($method === 'POST') {
                $this->curl->post($url, $this->json->serialize($data));
            } elseif ($method === 'DELETE') {
                $this->curl->delete($url);
            }

            // Obtenir la réponse
            $response = $this->curl->getBody();
            $statusCode = $this->curl->getStatus();

            // Journaliser la réponse pour le débogage
            $this->logger->debug('CJ Dropshipping API Response', [
                'url' => $url,
                'method' => $method,
                'status_code' => $statusCode,
                'response' => $response
            ]);

            $parsedResponse = $this->json->unserialize($response);

            // Vérifier si le token a expiré
            if (isset($parsedResponse['code']) && in_array($parsedResponse['code'], [401, 1600300, 1600400])) {
                // Token expiré ou invalide, effacer le cache et essayer à nouveau
                $this->tokenManager->clearTokenCache();
                return $this->sendRequest($method, $endpoint, $params, $data, $retryCount + 1);
            }

            // Vérifier si nous avons atteint la limite de requêtes
            if (isset($parsedResponse['code']) && $parsedResponse['code'] == 1600200) {
                // Pour les requêtes d'authentification, appliquer la règle de limitation de 5 minutes
                if ($endpoint === '/authentication/getAccessToken') {
                    $this->tokenManager->registerAuthAttempt();
                    return [
                        'error' => 'Limitation des requêtes API: veuillez attendre 300 secondes avant de réessayer.',
                        'canRetry' => false
                    ];
                }

                // Pour les requêtes de recherche ou autres, appliquer une règle de limitation plus courte
                if (strpos($endpoint, '/product') === 0) {
                    $this->registerSearchAttempt(); // Créez cette méthode
                    $waitTime = $this->getSearchWaitTime(); // Créez cette méthode

                    if ($waitTime > 0) {
                        return [
                            'error' => 'Limitation des requêtes API pour la recherche: veuillez attendre ' . $waitTime . ' secondes avant de réessayer.',
                            'canRetry' => false
                        ];
                    }

                    // Si le temps d'attente est écoulé ou si nous pouvons réessayer
                    if ($retryCount < 2) {
                        sleep(5); // Attendre 5 secondes
                        return $this->sendRequest($method, $endpoint, $params, $data, $retryCount + 1);
                    }
                }

                // Pour les autres types de requêtes
                if ($retryCount < 2) {
                    sleep(5); // Attendre 5 secondes
                    return $this->sendRequest($method, $endpoint, $params, $data, $retryCount + 1);
                }
            }

            return $parsedResponse;
        } catch (\Exception $e) {
            $this->logger->error('CJ Dropshipping API Error: ' . $e->getMessage(), [
                'url' => $url,
                'method' => $method,
                'exception' => $e->getTraceAsString()
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Enregistrer une tentative de recherche
     *
     * @return void
     */
    protected function registerSearchAttempt()
    {
        $cache = $this->getCacheInstance();
        $cache->save((string)time(), 'cj_dropshipping_last_search_attempt', [], 3600);
    }

    /**
     * Obtenir le temps d'attente pour la recherche
     *
     * @return int
     */
    protected function getSearchWaitTime()
    {
        $cache = $this->getCacheInstance();
        $lastAttempt = $cache->load('cj_dropshipping_last_search_attempt');

        if (!$lastAttempt) {
            return 0;
        }

        $lastAttemptTime = (int)$lastAttempt;
        $timeSinceLastAttempt = time() - $lastAttemptTime;
        $waitTime = 60 - $timeSinceLastAttempt; // Limitation de 1 minute pour les recherches

        return $waitTime > 0 ? $waitTime : 0;
    }

    /**
     * Obtenir l'instance de cache
     *
     * @return \Magento\Framework\App\Cache
     */
    protected function getCacheInstance()
    {
        // Utilisez le même mécanisme de cache que TokenManager
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $cacheFrontendPool = $objectManager->get(\Magento\Framework\App\Cache\Frontend\Pool::class);
        return $cacheFrontendPool->get('config')->getBackend();
    }
}
