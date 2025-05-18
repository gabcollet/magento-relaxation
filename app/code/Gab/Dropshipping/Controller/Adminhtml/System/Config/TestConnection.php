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

            // Vérifier que les paramètres nécessaires sont présents
            if (!isset($params['api_key']) || !isset($params['api_url'])) {
                return $result->setData([
                    'success' => false,
                    'message' => __('API Key or API URL is missing.')
                ]);
            }

            $apiKey = $params['api_key'];
            $apiUrl = rtrim($params['api_url'], '/');

            // Endpoint pour tester la connexion
            $endpoint = $apiUrl . '/product/categories';

            // Configurer les en-têtes de la requête
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('Authorization', 'Bearer ' . $apiKey);

            // Envoyer la requête
            $this->curl->get($endpoint);

            // Vérifier le code de statut HTTP
            $statusCode = $this->curl->getStatus();

            if ($statusCode == 200) {
                // La connexion a réussi
                $responseBody = $this->curl->getBody();
                $responseData = $this->json->unserialize($responseBody);

                // Vérifier la structure de la réponse
                if (isset($responseData['data']) && is_array($responseData['data'])) {
                    return $result->setData([
                        'success' => true,
                        'message' => __('API connection successful! Found %1 categories.', count($responseData['data']))
                    ]);
                } else {
                    return $result->setData([
                        'success' => true,
                        'message' => __('API connection successful, but could not parse categories.')
                    ]);
                }
            } else {
                // La connexion a échoué
                $responseBody = $this->curl->getBody();
                $error = __('API connection failed. Status code: %1', $statusCode);

                try {
                    $responseData = $this->json->unserialize($responseBody);
                    if (isset($responseData['message'])) {
                        $error .= '. ' . $responseData['message'];
                    }
                } catch (\Exception $e) {
                    // Ignorer les erreurs de parsing JSON
                }

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
