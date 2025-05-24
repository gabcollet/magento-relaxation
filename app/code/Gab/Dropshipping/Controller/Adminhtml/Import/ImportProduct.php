<?php
namespace Gab\Dropshipping\Controller\Adminhtml\Import;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Gab\Dropshipping\Model\Api\Client;
use Gab\Dropshipping\Model\Config;
use Gab\Dropshipping\Model\Product\ImportManagerFactory;
use Psr\Log\LoggerInterface;

class ImportProduct extends Action
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
     * @var ImportManagerFactory
     */
    protected $importManagerFactory;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Client $apiClient
     * @param Config $config
     * @param ImportManagerFactory $importManagerFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Client $apiClient,
        Config $config,
        ImportManagerFactory $importManagerFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->apiClient = $apiClient;
        $this->config = $config;
        $this->importManagerFactory = $importManagerFactory;
        $this->logger = $logger;
    }

    /**
     * Import product action
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
                    'message' => __('The Dropshipping module is disabled. Please enable it in configuration.')
                ]);
            }

            $pid = $this->getRequest()->getParam('pid');
            $importType = $this->getRequest()->getParam('import_type', 'simple');
            $markupPercentage = $this->getRequest()->getParam('markup_percentage', null);
            $stockQuantity = $this->getRequest()->getParam('stock_quantity', null);
            $categoryIds = $this->getRequest()->getParam('category_ids', []);
            $selectedAttributes = $this->getRequest()->getParam('selected_attributes', []);

            if (empty($selectedAttributes)) {
                return $result->setData([
                    'success' => false,
                    'message' => __('No configurable attributes selected.')
                ]);
            }

            if (!$pid) {
                return $result->setData([
                    'success' => false,
                    'message' => __('Product ID is required.')
                ]);
            }

            $response = $this->apiClient->getProductDetails($pid);

            if (!isset($response['data'])) {
                $error = isset($response['error']) ? $response['error'] : __('Could not retrieve product details.');
                return $result->setData([
                    'success' => false,
                    'message' => $error
                ]);
            }

            $productData = $response['data'];

            $importManager = $this->importManagerFactory->create();

            if ($importManager->productExists('CJ-' . $pid)) {
                return $result->setData([
                    'success' => false,
                    'message' => __('Product with SKU %1 already exists.', 'CJ-' . $pid)
                ]);
            }

            $markup = ($markupPercentage !== null) ? (float)$markupPercentage / 100 : $this->config->getMarkupPercentage() / 100;
            $stockQty = ($stockQuantity !== null) ? (int)$stockQuantity : $this->config->getDefaultStock();

            if ($importType === 'simple') {
                $importResult = $importManager->importSimpleProduct($productData, $pid, $markup, $stockQty, $categoryIds);
            } else {
                $selectedAttributes = $this->getRequest()->getParam('selected_attributes', []);

                if (empty($selectedAttributes)) {
                    return $result->setData([
                        'success' => false,
                        'message' => __('No configurable attributes selected.')
                    ]);
                }

                $variantsResponse = $this->apiClient->getProductVariants($pid);
                if (!isset($variantsResponse['data']) || empty($variantsResponse['data'])) {
                    return $result->setData([
                        'success' => false,
                        'message' => __('No variants found.')
                    ]);
                }

                $variants = $variantsResponse['data'];
                $importResult = $importManager->importConfigurableProduct(
                    $productData,
                    $variants,
                    $pid,
                    $markup,
                    $stockQty,
                    $categoryIds,
                    $selectedAttributes
                );
            }

            return $result->setData($importResult);

        } catch (\Exception $e) {
            $this->logger->error('Error importing product: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return $result->setData([
                'success' => false,
                'message' => __('An error occurred while importing the product: %1', $e->getMessage())
            ]);
        }
    }
}
