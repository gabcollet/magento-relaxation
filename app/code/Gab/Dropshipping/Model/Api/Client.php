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
     * @return array
     */
    public function getProducts($page = 1, $limit = 10, $searchTerm = null)
    {
        $limit = max(10, $limit);

        $params = [
            'pageNum' => $page,
            'pageSize' => $limit
        ];

        if ($searchTerm) {
            $params['productNameEn'] = $searchTerm;
        }

        return $this->sendRequest('GET', '/product/list', $params);
    }

    /**
     * Get product details
     *
     * @param string $pid
     * @return array
     */
    public function getProductDetails($pid)
    {
        return $this->sendRequest('GET', '/product/detail', [
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
        return $this->sendRequest('GET', '/product/variant', [
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
                // Limitation de requêtes, attendre et réessayer (pour les requêtes non d'authentification)
                if ($endpoint !== '/authentication/getAccessToken' && $retryCount < 2) {
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
}
