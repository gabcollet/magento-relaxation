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
     * @var string|null
     */
    protected $accessToken = null;

    /**
     * @param Config $config
     * @param Curl $curl
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config          $config,
        Curl            $curl,
        Json            $json,
        LoggerInterface $logger
    )
    {
        $this->config = $config;
        $this->curl = $curl;
        $this->json = $json;
        $this->logger = $logger;
    }

    /**
     * Get products from CJ Dropshipping
     *
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function getProducts($page = 1, $limit = 20)
    {
        return $this->sendRequest('GET', '/product/list', [
            'pageNum' => $page,
            'pageSize' => $limit
        ]);
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
     * @return array
     */
    protected function sendRequest($method, $endpoint, $params = [], $data = [])
    {
        if (!$this->config->isEnabled()) {
            return ['error' => 'Module is disabled'];
        }

        $apiUrl = rtrim($this->config->getApiUrl(), '/');
        $url = $apiUrl . $endpoint;

        // Add query parameters if present
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        // Set headers
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Accept', 'application/json');

        // Add authentication
        $apiKey = $this->config->getApiKey();
        if ($apiKey) {
            $this->curl->addHeader('Authorization', 'Bearer ' . $apiKey);
        }

        try {
            // Send request
            if ($method === 'GET') {
                $this->curl->get($url);
            } elseif ($method === 'POST') {
                $this->curl->post($url, $this->json->serialize($data));
            } elseif ($method === 'PUT') {
                $this->curl->put($url, $this->json->serialize($data));
            } elseif ($method === 'DELETE') {
                $this->curl->delete($url);
            }

            // Get response
            $response = $this->curl->getBody();

            // Log for debugging
            $this->logger->debug('CJ Dropshipping API Response', [
                'url' => $url,
                'method' => $method,
                'response' => $response
            ]);

            return $this->json->unserialize($response);
        } catch (\Exception $e) {
            $this->logger->error('CJ Dropshipping API Error: ' . $e->getMessage(), [
                'url' => $url,
                'method' => $method,
                'exception' => $e
            ]);

            return ['error' => $e->getMessage()];
        }
    }
}
