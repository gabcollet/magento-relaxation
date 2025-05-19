<?php
namespace Gab\Dropshipping\Controller\Adminhtml\Import;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Gab\Dropshipping\Model\Api\Client;
use Gab\Dropshipping\Model\Config;
use Psr\Log\LoggerInterface;

class Search extends Action
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
     * Search products action
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
                    'products' => []
                ]);
            }

            $searchTerm = $this->getRequest()->getParam('term', '');
            $categories = $this->getRequest()->getParam('categories', []);
            $page = (int) $this->getRequest()->getParam('page', 1);
            $limit = (int) $this->getRequest()->getParam('limit', 20);

            $this->logger->debug('Search request', [
                'term' => $searchTerm,
                'categories' => $categories,
                'page' => $page,
                'limit' => $limit
            ]);

            $response = $this->apiClient->getProducts($page, $limit, $searchTerm, $categories);

            if (isset($response['data']) && isset($response['data']['list'])) {
                return $result->setData([
                    'success' => true,
                    'products' => $response['data']['list'],
                    'totalCount' => $response['data']['total'] ?? count($response['data']['list']),
                    'page' => $page,
                    'limit' => $limit
                ]);
            } else {
                $error = isset($response['error']) ? $response['error'] :
                    (isset($response['message']) ? $response['message'] : __('No products found or API returned unexpected data format.'));
                return $result->setData([
                    'success' => false,
                    'message' => $error,
                    'products' => []
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error searching products: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return $result->setData([
                'success' => false,
                'message' => __('An error occurred while searching products: %1', $e->getMessage()),
                'products' => []
            ]);
        }
    }
}
