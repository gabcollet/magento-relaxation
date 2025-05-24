<?php

namespace Gab\Dropshipping\Model\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Gab\Dropshipping\Model\Product\ImageHandler;
use Gab\Dropshipping\Model\Product\SimpleProductImporter;
use Gab\Dropshipping\Model\Product\AttributeManager;
use Psr\Log\LoggerInterface;

class ConfigurableProductImporter
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
     * @var SimpleProductImporter
     */
    protected $simpleProductImporter;

    /**
     * @var AttributeManager
     */
    protected $attributeManager;

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
     * @param SimpleProductImporter $simpleProductImporter
     * @param AttributeManager $attributeManager
     * @param ImageHandler $imageHandler
     * @param LoggerInterface $logger
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        ProductFactory             $productFactory,
        SimpleProductImporter      $simpleProductImporter,
        AttributeManager           $attributeManager,
        ImageHandler               $imageHandler,
        LoggerInterface            $logger
    )
    {
        $this->productRepository = $productRepository;
        $this->productFactory = $productFactory;
        $this->simpleProductImporter = $simpleProductImporter;
        $this->attributeManager = $attributeManager;
        $this->imageHandler = $imageHandler;
        $this->logger = $logger;
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
    public function import($productData, $variants, $pid, $markup, $stockQty, $categoryIds, $selectedAttributes = [])
    {
        try {
            // 1. Analyser les variantes pour déterminer les attributs configurables
            if (!empty($selectedAttributes)) {
                $attributeMap = $this->attributeManager->createSelectedConfigurableAttributes($selectedAttributes, $variants);
                $configAttributes = []; // Pas besoin pour les attributs sélectionnés
            } else {
                // Fallback à l'ancienne méthode
                $configAttributes = $this->attributeManager->detectConfigurableAttributes($variants);
                if (empty($configAttributes)) {
                    // Import en tant que produit simple
                    $simpleProduct = $this->simpleProductImporter->import($productData, $pid, $markup, $stockQty, $categoryIds);
                    return [
                        'success' => true,
                        'message' => __('No configurable attributes detected. Product imported as simple product.'),
                        'productId' => $simpleProduct->getId(),
                        'sku' => $simpleProduct->getSku()
                    ];
                }

                $attributeMap = [];
                foreach ($configAttributes as $code => $values) {
                    $attributeData = $this->attributeManager->createOrGetConfigurableAttribute($code, array_values($values));
                    $attributeMap[$attributeData['attribute_id']] = [
                        'code' => $code,
                        'options' => $attributeData['options'],
                        'indexed_options' => $this->attributeManager->indexAttributeOptionsByLabel($attributeData['options'])
                    ];
                }
            }

            // 3. Créer le produit parent configurable
            $parentProduct = $this->createParentProduct($productData, $pid, $markup, $categoryIds, $variants);

            // 4. Créer les produits enfants (variantes)
            $childProducts = $this->createChildProducts($parentProduct, $productData, $variants, $markup, $stockQty, $categoryIds, $configAttributes, $attributeMap);

            // 5. Associer les produits enfants au produit parent
            try {
                $this->associateProducts($parentProduct, $childProducts, array_keys($attributeMap));

                return [
                    'success' => true,
                    'message' => __('Configurable product has been imported successfully with %1 variants.', count($childProducts)),
                    'productId' => $parentProduct->getId(),
                    'sku' => $parentProduct->getSku()
                ];
            } catch (\Exception $e) {
                $this->logger->error('Erreur lors de l\'association des produits: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);

                return [
                    'success' => false,
                    'message' => __('Error associating products: %1', $e->getMessage()),
                    'productId' => $parentProduct->getId(),
                    'sku' => $parentProduct->getSku()
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Error importing configurable product: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'pid' => $pid,
                'variant_count' => count($variants)
            ]);

            return [
                'success' => false,
                'message' => __('Error importing configurable product: %1', $e->getMessage())
            ];
        }
    }

    /**
     * Create parent configurable product
     *
     * @param array $productData
     * @param string $pid
     * @param float $markup
     * @param array $categoryIds
     * @param array $variants
     * @return \Magento\Catalog\Api\Data\ProductInterface
     */
    protected function createParentProduct($productData, $pid, $markup, $categoryIds, $variants)
    {
        $parentProduct = $this->productFactory->create();

        $sku = 'CJ-' . $pid;
        $parentProduct->setSku($sku);
        $parentProduct->setName($productData['productNameEn'] ?? 'CJ Product');
        $parentProduct->setAttributeSetId(4); // ID de l'ensemble d'attributs par défaut
        $parentProduct->setStatus(Status::STATUS_ENABLED);
        $parentProduct->setVisibility(Visibility::VISIBILITY_BOTH);
        $parentProduct->setTypeId(Configurable::TYPE_CODE);

        // Calculer le prix le plus bas pour le produit parent
        $minPrice = PHP_FLOAT_MAX;
        foreach ($variants as $variant) {
            if (isset($variant['variantSellPrice']) && (float)$variant['variantSellPrice'] < $minPrice) {
                $minPrice = (float)$variant['variantSellPrice'];
            }
        }

        if ($minPrice === PHP_FLOAT_MAX) {
            $minPrice = isset($productData['sellPrice']) ? (float)$productData['sellPrice'] : 0;
        }

        $finalPrice = $minPrice * (1 + $markup);
        $parentProduct->setPrice($finalPrice);

        // Définir la description
        $parentProduct->setDescription($productData['description'] ?? '');
        $parentProduct->setShortDescription($productData['productNameEn'] ?? '');

        // Définir les catégories
        if (!empty($categoryIds)) {
            $parentProduct->setCategoryIds($categoryIds);
        } else {
            // Catégorie par défaut si aucune n'est spécifiée
            $parentProduct->setCategoryIds([2]);
        }

        // Définir le stock virtuel pour le produit parent
        $parentProduct->setStockData([
            'use_config_manage_stock' => 0,
            'manage_stock' => 0,
            'is_in_stock' => 1,
            'qty' => 0
        ]);

        // Définir les attributs personnalisés pour le dropshipping
        $parentProduct->setCustomAttribute('dropship_pid', $pid);
        $parentProduct->setCustomAttribute('dropship_source', 'CJ');

        // Ajouter des images si disponibles
        $this->imageHandler->addProductImages($parentProduct, $productData);

        // Désactiver l'extension de requête pour le produit configurable
        $parentProduct->setCanSaveConfigurableAttributes(false);

        // Enregistrer le produit parent
        return $this->productRepository->save($parentProduct);
    }

    /**
     * Create child products (variants)
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $parentProduct
     * @param array $productData
     * @param array $variants
     * @param float $markup
     * @param int $stockQty
     * @param array $categoryIds
     * @param array $configAttributes
     * @param array $attributeMap
     * @return array
     */
    protected function createChildProducts($parentProduct, $productData, $variants, $markup, $stockQty, $categoryIds, $configAttributes, $attributeMap)
    {
        $childProducts = [];
        $parentSku = $parentProduct->getSku();

        foreach ($variants as $variant) {
            $variantData = [];
            $variantData['name'] = $productData['productNameEn'] ?? 'CJ Product';

            $variantSku = $parentSku . '-' . $variant['vid'];
            $variantData['sku'] = $variantSku;

            $variantPrice = isset($variant['variantSellPrice']) ? (float)$variant['variantSellPrice'] : (float)$productData['sellPrice'];
            $variantFinalPrice = $variantPrice * (1 + $markup);
            $variantData['price'] = $variantFinalPrice;

            $variantAttributes = [];

            foreach ($configAttributes as $code => $values) {
                $matchingKey = null;
                foreach ($variant as $variantKey => $variantValue) {
                    if (strtolower($variantKey) === strtolower($code)) {
                        $matchingKey = $variantKey;
                        break;
                    }
                }

                if ($matchingKey !== null) {
                    $optionValue = $variant[$matchingKey];
                    $valueKey = is_numeric($optionValue) ? (string)$optionValue : $optionValue;
                    $valueKeyLower = strtolower($valueKey);

                    foreach ($attributeMap as $attributeId => $attrData) {
                        if (strtolower($attrData['code']) === strtolower($variantKey)) {
                            if (isset($attrData['indexed_options'][$valueKeyLower])) {
                                $variantAttributes[$code] = $attrData['indexed_options'][$valueKeyLower];
                            }
                            break;
                        }
                    }
                }
            }

            $variantStockQty = isset($variant['variantStock']) ? (int)$variant['variantStock'] : $stockQty;
            $variantData['stock_data'] = [
                'use_config_manage_stock' => 1,
                'manage_stock' => 1,
                'is_in_stock' => $variantStockQty > 0 ? 1 : 0,
                'qty' => $variantStockQty
            ];

            $childProduct = $this->simpleProductImporter->createVariant($variantData, $variantAttributes, $categoryIds);
            $childProduct->setCustomAttribute('dropship_pid', str_replace('CJ-', '', $parentSku));
            $childProduct->setCustomAttribute('dropship_source', 'CJ');
            $childProduct->setCustomAttribute('dropship_variant_id', $variant['vid']);

            $childProduct = $this->productRepository->save($childProduct);

            if (isset($variant['variantImage']) && !empty($variant['variantImage'])) {
                $this->imageHandler->addProductImageFromUrl($childProduct, $variant['variantImage']);
            } else {
                $this->imageHandler->addProductImages($childProduct, $productData);
            }

            $childProduct = $this->productRepository->save($childProduct);
            $childProducts[] = $childProduct;
        }

        return $childProducts;
    }

    /**
     * Associate child products with the parent configurable product
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $parentProduct
     * @param array $childProducts
     * @param array $attributeIds
     * @return void
     */
    protected function associateProducts($parentProduct, $childProducts, $attributeIds)
    {
        try {
            $childProductIds = [];
            foreach ($childProducts as $childProduct) {
                $childProductIds[] = $childProduct->getId();
            }

            $attributesData = $this->attributeManager->getConfigurableAttributesData($attributeIds);

            if (empty($attributesData)) {
                $this->logger->error('No configurable attributes data found');
                return;
            }

            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $configurableType = $objectManager->create(\Magento\ConfigurableProduct\Model\Product\Type\Configurable::class);

            $parentProduct->getTypeInstance()->setUsedProductAttributeIds($attributeIds, $parentProduct);

            $configurableAttributesData = $parentProduct->getTypeInstance()->getConfigurableAttributesAsArray($parentProduct);
            $parentProduct->setConfigurableAttributesData($configurableAttributesData);

            $parentProduct->setAssociatedProductIds($childProductIds);
            $parentProduct->setCanSaveConfigurableAttributes(true);

            // Not working https://github.com/magento/magento2/issues/33937
//            $this->productRepository->save($parentProduct);
            $parentProduct->save();

            $this->logger->info('Products associated successfully', [
                'parent_id' => $parentProduct->getId(),
                'child_count' => count($childProductIds)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error associating products: ' . $e->getMessage(), [
                'parent_id' => $parentProduct->getId(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
