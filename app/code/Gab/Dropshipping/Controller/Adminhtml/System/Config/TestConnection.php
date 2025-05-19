<?php
namespace Gab\Dropshipping\Controller\Adminhtml\System\Config;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

class TestConnection extends Action
{
    /**
     * Authorization level
     */
    const ADMIN_RESOURCE = 'Gab_Dropshipping::config';

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

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
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Curl $curl
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Curl $curl,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->curl = $curl;
        $this->json = $json;
        $this->logger = $logger;
        parent::__construct($context);
    }

    /**
     * Test connection
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            $params = $this->getRequest()->getParams();
            $this->logger->debug('Test connection params', $params);

            // Vérifier que les paramètres nécessaires sont présents
            if (!isset($params['api_key']) || !isset($params['api_url'])) {
                return $result->setData([
                    'success' => false,
                    'message' => __('API Key or API URL is missing.')
                ]);
            }

            $apiKey = $params['api_key'];
            $apiUrl = rtrim($params['api_url'], '/');

            // Endpoint pour tester la connexion - utilisez un endpoint simple qui existe
            $endpoint = $apiUrl . '/product/list';

            // Configurer les en-têtes de la requête
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('Authorization', 'Bearer ' . $apiKey);

            // Envoyer la requête
            $this->curl->get($endpoint);

            // Vérifier le code de statut HTTP
            $statusCode = $this->curl->getStatus();
            $this->logger->debug('API Response Status Code', ['status' => $statusCode]);

            if ($statusCode == 200) {
                // La connexion a réussi
                $responseBody = $this->curl->getBody();
                $this->logger->debug('API Response Body', ['body' => $responseBody]);

                return $result->setData([
                    'success' => true,
                    'message' => __('API connection successful! The endpoint responded with status code 200.')
                ]);
            } else {
                // La connexion a échoué
                $responseBody = $this->curl->getBody();
                $error = __('API connection failed. Status code: %1', $statusCode);
                $this->logger->debug('API Error Response', ['body' => $responseBody]);

                return $result->setData([
                    'success' => false,
                    'message' => $error
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error testing CJ Dropshipping API connection: ' . $e->getMessage());

            return $result->setData([
                'success' => false,
                'message' => __('An error occurred: %1', $e->getMessage())
            ]);
        }
    }
}
