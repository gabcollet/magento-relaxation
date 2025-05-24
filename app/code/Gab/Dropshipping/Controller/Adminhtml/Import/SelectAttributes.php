<?php

namespace Gab\Dropshipping\Controller\Adminhtml\Import;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Gab\Dropshipping\Model\Api\Client;
use Gab\Dropshipping\Model\Config;
use Gab\Dropshipping\Model\Product\AttributeManager;
use Psr\Log\LoggerInterface;

class SelectAttributes extends Action
{
    const ADMIN_RESOURCE = 'Gab_Dropshipping::import';

    protected $resultJsonFactory;
    protected $apiClient;
    protected $config;
    protected $attributeManager;
    protected $logger;

    public function __construct(
        Context          $context,
        JsonFactory      $resultJsonFactory,
        Client           $apiClient,
        Config           $config,
        AttributeManager $attributeManager,
        LoggerInterface  $logger
    )
    {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->apiClient = $apiClient;
        $this->config = $config;
        $this->attributeManager = $attributeManager;
        $this->logger = $logger;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            $pid = $this->getRequest()->getParam('pid');

            if (!$pid) {
                return $result->setData([
                    'success' => false,
                    'message' => __('Product ID is required.')
                ]);
            }

            // Get variants from API
            $variantsResponse = $this->apiClient->getProductVariants($pid);

            if (!isset($variantsResponse['data']) || empty($variantsResponse['data'])) {
                return $result->setData([
                    'success' => false,
                    'message' => __('No variants found for this product.')
                ]);
            }

            $variants = $variantsResponse['data'];
            $selectableAttributes = $this->attributeManager->getVariantAttributesForSelection($variants);

            return $result->setData([
                'success' => true,
                'attributes' => $selectableAttributes
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error getting selectable attributes: ' . $e->getMessage());

            return $result->setData([
                'success' => false,
                'message' => __('An error occurred while retrieving variant attributes.')
            ]);
        }
    }
}
