<?php
namespace Gab\Dropshipping\Cron;

use Gab\Dropshipping\Model\Config;
use Gab\Dropshipping\Model\Api\Client;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Psr\Log\LoggerInterface;

class SyncInventory
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Client
     */
    protected $apiClient;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * @var StockRegistryInterface
     */
    protected $stockRegistry;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Config $config
     * @param Client $apiClient
     * @param ProductRepositoryInterface $productRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param StockRegistryInterface $stockRegistry
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        Client $apiClient,
        ProductRepositoryInterface $productRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        StockRegistryInterface $stockRegistry,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->apiClient = $apiClient;
        $this->productRepository = $productRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->stockRegistry = $stockRegistry;
        $this->logger = $logger;
    }

    /**
     * Execute cron job for syncing inventory
     *
     * @return void
     */
    public function execute()
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            $this->logger->info('Starting inventory sync with CJ Dropshipping');

            // Get dropshipping products from our catalog
            // We assume products imported from CJ have a custom attribute or SKU prefix
            // This is just an example - you would adapt based on your product identification method
            $skuPrefix = 'CJ-';
            $this->searchCriteriaBuilder->addFilter('sku', $skuPrefix . '%', 'like');
            $searchCriteria = $this->searchCriteriaBuilder->create();
            $products = $this->productRepository->getList($searchCriteria)->getItems();

            if (empty($products)) {
                $this->logger->info('No dropshipping products found to sync');
                return;
            }

            $this->logger->info(sprintf('Found %d dropshipping products to sync', count($products)));

            $batchSize = 20; // Process products in batches to avoid API rate limits
            $productBatches = array_chunk($products, $batchSize);

            foreach ($productBatches as $batch) {
                $this->processBatch($batch);
                // Add a small delay to avoid overwhelming the API
                sleep(2);
            }

            $this->logger->info('Inventory sync with CJ Dropshipping completed');
        } catch (\Exception $e) {
            $this->logger->error('Error during inventory sync: ' . $e->getMessage());
        }
    }

    /**
     * Process a batch of products
     *
     * @param array $products
     * @return void
     */
    protected function processBatch(array $products)
    {
        foreach ($products as $product) {
            try {
                // Extract the original CJ product ID from our SKU
                // Assuming format is CJ-{pid}
                $sku = $product->getSku();
                $pid = str_replace('CJ-', '', $sku);

                // Get current inventory from CJ
                $productData = $this->apiClient->getProductDetails($pid);

                if (isset($productData['data']['variants']) && !empty($productData['data']['variants'])) {
                    // Get the first variant or process all variants if needed
                    $variant = $productData['data']['variants'][0];

                    if (isset($variant['variantStock'])) {
                        $stockQty = (int)$variant['variantStock'];

                        // Update the stock in Magento
                        $stockItem = $this->stockRegistry->getStockItemBySku($sku);
                        $stockItem->setQty($stockQty);
                        $stockItem->setIsInStock($stockQty > 0);
                        $this->stockRegistry->updateStockItemBySku($sku, $stockItem);

                        $this->logger->info(sprintf('Updated stock for %s to %d', $sku, $stockQty));
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error(sprintf('Error updating stock for %s: %s', $product->getSku(), $e->getMessage()));
                continue;
            }
        }
    }
}
