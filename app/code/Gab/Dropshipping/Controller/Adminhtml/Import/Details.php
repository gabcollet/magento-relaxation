<?php
namespace Gab\Dropshipping\Controller\Adminhtml\Import;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Gab\Dropshipping\Model\Api\Client;
use Gab\Dropshipping\Model\Config;
use Psr\Log\LoggerInterface;

class Details extends Action
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
     * Get product details action
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
                    'product' => null
                ]);
            }

            $pid = $this->getRequest()->getParam('pid');

            if (!$pid) {
                return $result->setData([
                    'success' => false,
                    'message' => __('Product ID is required.'),
                    'product' => null
                ]);
            }

            $this->logger->debug('Get product details request', [
                'pid' => $pid
            ]);

            $response = $this->apiClient->getProductDetails($pid);

            if (isset($response['data'])) {
                return $result->setData([
                    'success' => true,
                    'product' => $response['data']
                ]);
            } else {
                $error = isset($response['error']) ? $response['error'] :
                    (isset($response['message']) ? $response['message'] : __('Could not retrieve product details.'));
                return $result->setData([
                    'success' => false,
                    'message' => $error,
                    'product' => null
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error getting product details: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return $result->setData([
                'success' => false,
                'message' => __('An error occurred while retrieving product details: %1', $e->getMessage()),
                'product' => null
            ]);
        }
    }
}
