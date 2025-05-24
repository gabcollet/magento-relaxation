<?php
namespace Gab\Dropshipping\Model\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Helper\Product\Options\Factory as OptionsFactory;
use Psr\Log\LoggerInterface;

class ImportManager
{
    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var SimpleProductImporter
     */
    protected $simpleProductImporter;

    /**
     * @var ConfigurableProductImporter
     */
    protected $configurableProductImporter;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param ProductRepositoryInterface $productRepository
     * @param SimpleProductImporter $simpleProductImporter
     * @param ConfigurableProductImporter $configurableProductImporter
     * @param LoggerInterface $logger
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        SimpleProductImporter $simpleProductImporter,
        ConfigurableProductImporter $configurableProductImporter,
        LoggerInterface $logger
    ) {
        $this->productRepository = $productRepository;
        $this->simpleProductImporter = $simpleProductImporter;
        $this->configurableProductImporter = $configurableProductImporter;
        $this->logger = $logger;
    }

    /**
     * Check if product exists by SKU
     *
     * @param string $sku
     * @return bool
     */
    public function productExists($sku)
    {
        try {
            $this->productRepository->get($sku);
            return true;
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            return false;
        }
    }

    /**
     * Import simple product
     *
     * @param array $productData
     * @param string $pid
     * @param float $markup
     * @param int $stockQty
     * @param array $categoryIds
     * @return array
     */
    public function importSimpleProduct($productData, $pid, $markup, $stockQty, $categoryIds)
    {
        try {
            $product = $this->simpleProductImporter->import($productData, $pid, $markup, $stockQty, $categoryIds);

            return [
                'success' => true,
                'message' => __('Product has been imported successfully.'),
                'productId' => $product->getId(),
                'sku' => $product->getSku()
            ];
        } catch (\Exception $e) {
            $this->logger->error('Error in simple product import: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => __('Error importing simple product: %1', $e->getMessage())
            ];
        }
    }

    /**
     * Import configurable product
     *
     * @param array $productData
     * @param array $variants
     * @param string $pid
     * @param float $markup
     * @param int $stockQty
     * @param array $categoryIds
     * @return array
     */
    public function importConfigurableProduct($productData, $variants, $pid, $markup, $stockQty, $categoryIds, $selectedAttributes = [])
    {
        try {
            $product = $this->configurableProductImporter->import(
                $productData,
                $variants,
                $pid,
                $markup,
                $stockQty,
                $categoryIds,
                $selectedAttributes
            );

            return [
                'success' => true,
                'message' => __('Configurable product has been imported successfully.'),
                'productId' => $product['productId'] ?? null,
                'sku' => $product['sku'] ?? null
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => __('Error importing configurable product: %1', $e->getMessage())
            ];
        }
    }
}
