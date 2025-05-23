<?php
namespace Gab\Dropshipping\Model\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Gab\Dropshipping\Model\Product\ImageHandler;
use Psr\Log\LoggerInterface;

class SimpleProductImporter
{
    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var ProductFactory
     */
    protected $productFactory;

    /**
     * @var StockRegistryInterface
     */
    protected $stockRegistry;

    /**
     * @var ImageHandler
     */
    protected $imageHandler;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param ProductRepositoryInterface $productRepository
     * @param ProductFactory $productFactory
     * @param StockRegistryInterface $stockRegistry
     * @param ImageHandler $imageHandler
     * @param LoggerInterface $logger
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        ProductFactory $productFactory,
        StockRegistryInterface $stockRegistry,
        ImageHandler $imageHandler,
        LoggerInterface $logger
    ) {
        $this->productRepository = $productRepository;
        $this->productFactory = $productFactory;
        $this->stockRegistry = $stockRegistry;
        $this->imageHandler = $imageHandler;
        $this->logger = $logger;
    }

    /**
     * Import simple product
     *
     * @param array $productData
     * @param string $pid
     * @param float $markup
     * @param int $stockQty
     * @param array $categoryIds
     * @return \Magento\Catalog\Api\Data\ProductInterface
     */
    public function import($productData, $pid, $markup, $stockQty, $categoryIds)
    {
        // Créer le nouveau produit
        $product = $this->productFactory->create();

        $sku = 'CJ-' . $pid;

        // Définir les attributs de base
        $product->setSku($sku);
        $product->setName($productData['productNameEn'] ?? 'CJ Product');
        $product->setAttributeSetId(4); // ID de l'ensemble d'attributs par défaut
        $product->setStatus(Status::STATUS_ENABLED);
        $product->setVisibility(Visibility::VISIBILITY_BOTH);
        $product->setTypeId(ProductType::TYPE_SIMPLE);

        // Calculer le prix avec la marge configurée
        $price = isset($productData['sellPrice']) ? (float)$productData['sellPrice'] : 0;
        $finalPrice = $price * (1 + $markup);

        $product->setPrice($finalPrice);

        // Si le produit a un prix coûtant, l'enregistrer dans un attribut personnalisé
        if (isset($productData['sellPrice'])) {
            $product->setCustomAttribute('cost', $productData['sellPrice']);
        }

        // Définir la description
        $product->setDescription($productData['description'] ?? '');
        $product->setShortDescription($productData['productNameEn'] ?? '');

        // Définir les catégories
        if (!empty($categoryIds)) {
            $product->setCategoryIds($categoryIds);
        } else {
            // Catégorie par défaut si aucune n'est spécifiée
            $product->setCategoryIds([2]);
        }

        // Définir le stock
        $product->setStockData([
            'use_config_manage_stock' => 1,
            'manage_stock' => 1,
            'is_in_stock' => $stockQty > 0 ? 1 : 0,
            'qty' => $stockQty
        ]);

        // Définir les attributs personnalisés pour le dropshipping
        $product->setCustomAttribute('dropship_pid', $pid);
        $product->setCustomAttribute('dropship_source', 'CJ');

        // Enregistrer les dimensions et le poids si disponibles
        if (isset($productData['packageLength']) && isset($productData['packageWidth']) && isset($productData['packageHeight'])) {
            $product->setWeight($productData['packageWeight'] ?? 0);
            $product->setCustomAttribute('ts_dimensions_length', $productData['packageLength']);
            $product->setCustomAttribute('ts_dimensions_width', $productData['packageWidth']);
            $product->setCustomAttribute('ts_dimensions_height', $productData['packageHeight']);
        }

        // Ajouter des images si disponibles
        $this->imageHandler->addProductImages($product, $productData);

        // Enregistrer le produit
        $product = $this->productRepository->save($product);

        // Mettre à jour le stock (nécessaire dans certains cas pour Magento 2.3+)
        $stockItem = $this->stockRegistry->getStockItem($product->getId());
        $stockItem->setQty($stockQty);
        $stockItem->setIsInStock($stockQty > 0);
        $this->stockRegistry->updateStockItemBySku($sku, $stockItem);

        return $product;
    }

    /**
     * Create simple variant product
     *
     * @param array $productData
     * @param array $attributes
     * @param array $categoryIds
     * @return \Magento\Catalog\Api\Data\ProductInterface
     */
    public function createVariant($productData, $attributes, $categoryIds)
    {
        $product = $this->productFactory->create();

        $product->setSku($productData['sku']);
        $product->setName($productData['name']);
        $product->setPrice($productData['price']);
        $product->setAttributeSetId(4); // ID de l'ensemble d'attributs par défaut
        $product->setStatus(Status::STATUS_ENABLED);
        $product->setVisibility(Visibility::VISIBILITY_NOT_VISIBLE);
        $product->setTypeId(ProductType::TYPE_SIMPLE);
        $product->setWebsiteIds([1]); // ID du site web par défaut

        // Définir les catégories
        if (!empty($categoryIds)) {
            $product->setCategoryIds($categoryIds);
        } else {
            // Catégorie par défaut si aucune n'est spécifiée
            $product->setCategoryIds([2]);
        }

        // Définir le stock
        $product->setStockData($productData['stock_data']);

        // Définir les attributs configurables
        foreach ($attributes as $attributeCode => $optionId) {
            $product->setData($attributeCode, $optionId);
        }

        return $this->productRepository->save($product);
    }
}
