<?php
namespace Gab\Dropshipping\Controller\Adminhtml\Import;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Gab\Dropshipping\Model\Api\Client;
use Gab\Dropshipping\Model\Config;
use Psr\Log\LoggerInterface;

class GetVariants extends Action
{
    const ADMIN_RESOURCE = 'Gab_Dropshipping::import';

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var Client
     */
    protected $apiClient;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Client $apiClient
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Client $apiClient,
        Config $config,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->apiClient = $apiClient;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Get product variants action
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            if (!$this->config->isEnabled()) {
                return $result->setData([
                    'success' => false,
                    'message' => __('The Dropshipping module is disabled. Please enable it in configuration.'),
                    'variants' => []
                ]);
            }

            $pid = $this->getRequest()->getParam('pid');

            if (!$pid) {
                return $result->setData([
                    'success' => false,
                    'message' => __('Product ID is required.'),
                    'variants' => []
                ]);
            }

            $this->logger->debug('Get product variants request', [
                'pid' => $pid
            ]);

            // Appel à l'API CJ Dropshipping pour récupérer les variantes du produit
            $response = $this->apiClient->getProductVariants($pid);

            if (isset($response['data']) && is_array($response['data'])) {
                return $result->setData([
                    'success' => true,
                    'variants' => $response['data']
                ]);
            } else {
                $error = isset($response['error']) ? $response['error'] : __('Could not retrieve product variants.');
                return $result->setData([
                    'success' => false,
                    'message' => $error,
                    'variants' => []
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error getting product variants: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return $result->setData([
                'success' => false,
                'message' => __('An error occurred while retrieving product variants: %1', $e->getMessage()),
                'variants' => []
            ]);
        }
    }
}
