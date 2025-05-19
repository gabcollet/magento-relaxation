<?php
namespace Gab\Dropshipping\Controller\Adminhtml\Import;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Gab\Dropshipping\Model\Api\Client;
use Gab\Dropshipping\Model\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Helper\Product\Options\Factory as OptionsFactory;
use Magento\Catalog\Api\AttributeSetRepositoryInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Eav\AttributeFactory;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Filesystem\Io\File;
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
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var ProductFactory
     */
    protected $productFactory;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var StockRegistryInterface
     */
    protected $stockRegistry;

    /**
     * @var OptionsFactory
     */
    protected $optionsFactory;

    /**
     * @var AttributeRepositoryInterface
     */
    protected $attributeRepository;

    /**
     * @var AttributeFactory
     */
    protected $attributeFactory;

    /**
     * @var AttributeSetRepositoryInterface
     */
    protected $attributeSetRepository;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var File
     */
    protected $file;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Client $apiClient
     * @param Config $config
     * @param ProductRepositoryInterface $productRepository
     * @param ProductFactory $productFactory
     * @param StoreManagerInterface $storeManager
     * @param StockRegistryInterface $stockRegistry
     * @param OptionsFactory $optionsFactory
     * @param AttributeRepositoryInterface $attributeRepository
     * @param AttributeFactory $attributeFactory
     * @param AttributeSetRepositoryInterface $attributeSetRepository
     * @param Filesystem $filesystem
     * @param Curl $curl
     * @param File $file
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Client $apiClient,
        Config $config,
        ProductRepositoryInterface $productRepository,
        ProductFactory $productFactory,
        StoreManagerInterface $storeManager,
        StockRegistryInterface $stockRegistry,
        OptionsFactory $optionsFactory,
        AttributeRepositoryInterface $attributeRepository,
        AttributeFactory $attributeFactory,
        AttributeSetRepositoryInterface $attributeSetRepository,
        Filesystem $filesystem,
        Curl $curl,
        File $file,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->apiClient = $apiClient;
        $this->config = $config;
        $this->productRepository = $productRepository;
        $this->productFactory = $productFactory;
        $this->storeManager = $storeManager;
        $this->stockRegistry = $stockRegistry;
        $this->optionsFactory = $optionsFactory;
        $this->attributeRepository = $attributeRepository;
        $this->attributeFactory = $attributeFactory;
        $this->attributeSetRepository = $attributeSetRepository;
        $this->filesystem = $filesystem;
        $this->curl = $curl;
        $this->file = $file;
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

            if (!$pid) {
                return $result->setData([
                    'success' => false,
                    'message' => __('Product ID is required.')
                ]);
            }

            $this->logger->debug('Import product request', [
                'pid' => $pid,
                'import_type' => $importType,
                'markup_percentage' => $markupPercentage,
                'stock_quantity' => $stockQuantity,
                'category_ids' => $categoryIds
            ]);

            // Récupérer les détails du produit depuis l'API CJ Dropshipping
            $response = $this->apiClient->getProductDetails($pid);

            if (!isset($response['data'])) {
                $error = isset($response['error']) ? $response['error'] : __('Could not retrieve product details.');
                return $result->setData([
                    'success' => false,
                    'message' => $error
                ]);
            }

            $productData = $response['data'];

            // Vérifier si le produit existe déjà
            $sku = 'CJ-' . $pid;
            $existingProduct = null;

            try {
                $existingProduct = $this->productRepository->get($sku);
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                // Le produit n'existe pas, c'est normal
            }

            if ($existingProduct) {
                return $result->setData([
                    'success' => false,
                    'message' => __('Product with SKU %1 already exists.', $sku)
                ]);
            }

            // Utiliser les valeurs du formulaire ou les valeurs par défaut de la configuration
            $markup = ($markupPercentage !== null) ? (float)$markupPercentage / 100 : $this->config->getMarkupPercentage() / 100;
            $stockQty = ($stockQuantity !== null) ? (int)$stockQuantity : $this->config->getDefaultStock();

            if ($importType === 'simple') {
                // Importer comme produit simple
                $product = $this->importSimpleProduct($productData, $pid, $markup, $stockQty, $categoryIds);

                return $result->setData([
                    'success' => true,
                    'message' => __('Product has been imported successfully.'),
                    'productId' => $product->getId(),
                    'sku' => $product->getSku()
                ]);
            } else {
                // Importer comme produit configurable
                // D'abord, récupérer les variantes
                $variantsResponse = $this->apiClient->getProductVariants($pid);

                if (!isset($variantsResponse['data']) || empty($variantsResponse['data'])) {
                    return $result->setData([
                        'success' => false,
                        'message' => __('No variants found for this product. Cannot create configurable product.')
                    ]);
                }
                $variants = $variantsResponse['data'];

                $configResult = $this->importConfigurableProduct($productData, $variants, $pid, $markup, $stockQty, $categoryIds);

                return $result->setData([
                    'success' => $configResult['success'],
                    'message' => $configResult['message'],
                    'productId' => $configResult['productId'] ?? null,
                    'sku' => $configResult['sku'] ?? null
                ]);
            }
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

    /**
     * Import a simple product
     *
     * @param array $productData
     * @param string $pid
     * @param float $markup
     * @param int $stockQty
     * @param array $categoryIds
     * @return \Magento\Catalog\Api\Data\ProductInterface
     */
    protected function importSimpleProduct($productData, $pid, $markup, $stockQty, $categoryIds)
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
        $this->addProductImages($product, $productData);

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
     * Import a configurable product with variants
     *
     * @param array $productData
     * @param array $variants
     * @param string $pid
     * @param float $markup
     * @param int $stockQty
     * @param array $categoryIds
     * @return array
     */
    protected function importConfigurableProduct($productData, $variants, $pid, $markup, $stockQty, $categoryIds)
    {
        try {
            // 1. Analyser les variantes pour déterminer les attributs configurables
            $configAttributes = $this->detectConfigurableAttributes($variants);

            if (empty($configAttributes)) {
                // Si aucun attribut configurable n'est détecté, créer un produit simple
                $simpleProduct = $this->importSimpleProduct($productData, $pid, $markup, $stockQty, $categoryIds);

                return [
                    'success' => true,
                    'message' => __('No configurable attributes detected. Product imported as simple product.'),
                    'productId' => $simpleProduct->getId(),
                    'sku' => $simpleProduct->getSku()
                ];
            }

            // 2. Créer ou récupérer les attributs configurables
            $attributeIds = [];
            $attributeOptions = [];

            foreach ($configAttributes as $code => $values) {
                $attributeId = $this->createOrGetConfigurableAttribute($code, array_values($values));
                $attributeIds[] = $attributeId;
                $attributeOptions[$attributeId] = $values;
            }

            // 3. Créer le produit parent configurable
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
            $this->addProductImages($parentProduct, $productData);

            // Désactiver l'extension de requête pour le produit configurable
            $parentProduct->setCanSaveConfigurableAttributes(false);

            // Enregistrer le produit parent
            $parentProduct = $this->productRepository->save($parentProduct);

            // 4. Créer les produits enfants (variantes)
            $childProducts = [];

            foreach ($variants as $variant) {
                $variantData = [];
                $variantData['name'] = $productData['productNameEn'] ?? 'CJ Product';

                // Générer le SKU pour la variante
                $variantSku = $sku . '-' . $variant['vid'];
                $variantData['sku'] = $variantSku;

                // Définir le prix avec la marge
                $variantPrice = isset($variant['variantSellPrice']) ? (float)$variant['variantSellPrice'] : $minPrice;
                $variantFinalPrice = $variantPrice * (1 + $markup);
                $variantData['price'] = $variantFinalPrice;

                // Définir les attributs configurables pour cette variante
                $variantAttributes = [];

                foreach ($configAttributes as $code => $values) {
                    if (isset($variant[$code])) {
                        $optionValue = $variant[$code];
                        if (isset($values[$optionValue])) {
                            $attributeId = $this->getAttributeIdByCode($code);
                            $variantAttributes[$attributeId] = $values[$optionValue];
                        }
                    }
                }

                // Définir le stock pour la variante
                $variantStockQty = isset($variant['variantStock']) ? (int)$variant['variantStock'] : $stockQty;
                $variantData['stock_data'] = [
                    'use_config_manage_stock' => 1,
                    'manage_stock' => 1,
                    'is_in_stock' => $variantStockQty > 0 ? 1 : 0,
                    'qty' => $variantStockQty
                ];

                // Créer le produit variante
                $childProduct = $this->createSimpleProduct($variantData, $variantAttributes, $categoryIds);
                $childProduct->setCustomAttribute('dropship_pid', $pid);
                $childProduct->setCustomAttribute('dropship_source', 'CJ');
                $childProduct->setCustomAttribute('dropship_variant_id', $variant['vid']);

                // Ajouter des images pour la variante si disponibles
                if (isset($variant['variantImage']) && !empty($variant['variantImage'])) {
                    $this->addProductImageFromUrl($childProduct, $variant['variantImage']);
                } else {
                    // Si pas d'image spécifique à la variante, utiliser les images du produit parent
                    $this->addProductImages($childProduct, $productData);
                }

                // Enregistrer le produit variante
                $childProduct = $this->productRepository->save($childProduct);
                $childProducts[] = $childProduct;
            }

            // 5. Associer les produits enfants au produit parent
            $configurableProductOptions = $this->optionsFactory->create([
                'attributesData' => $this->getConfigurableAttributesData($attributeIds),
                'associatedProductIds' => $this->getAssociatedProductIds($childProducts),
                'product' => $parentProduct
            ]);

            $extensionAttributes = $parentProduct->getExtensionAttributes();
            $extensionAttributes->setConfigurableProductOptions($configurableProductOptions);
            $extensionAttributes->setConfigurableProductLinks($this->getAssociatedProductIds($childProducts));

            $parentProduct->setExtensionAttributes($extensionAttributes);

            // Enregistrer le produit parent à nouveau avec ses options configurables
            $this->productRepository->save($parentProduct);

            return [
                'success' => true,
                'message' => __('Configurable product has been imported successfully with %1 variants.', count($childProducts)),
                'productId' => $parentProduct->getId(),
                'sku' => $parentProduct->getSku()
            ];
        } catch (\Exception $e) {
            $this->logger->error('Error importing configurable product: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => __('Error importing configurable product: %1', $e->getMessage())
            ];
        }
    }

    /**
     * Detect configurable attributes from variants
     *
     * @param array $variants
     * @return array
     */
    protected function detectConfigurableAttributes($variants)
    {
        $configurableAttributes = [];
        $ignoredAttributes = ['vid', 'variantSku', 'variantImage', 'variantSellPrice', 'variantStock'];

        // Parcourir toutes les variantes pour détecter les attributs configurables
        foreach ($variants as $variant) {
            foreach ($variant as $code => $value) {
                // Ignorer les attributs non configurables
                if (in_array($code, $ignoredAttributes) || empty($value)) {
                    continue;
                }

                // Si l'attribut n'existe pas encore dans notre liste, l'ajouter
                if (!isset($configurableAttributes[$code])) {
                    $configurableAttributes[$code] = [];
                }

                // Ajouter la valeur de l'attribut à notre liste s'il n'existe pas déjà
                if (!isset($configurableAttributes[$code][$value])) {
                    $configurableAttributes[$code][$value] = $value;
                }
            }
        }

        // Filtrer les attributs qui n'ont qu'une seule valeur (non configurables)
        foreach ($configurableAttributes as $code => $values) {
            if (count($values) <= 1) {
                unset($configurableAttributes[$code]);
            }
        }

        return $configurableAttributes;
    }

    /**
     * Create or get configurable attribute
     *
     * @param string $code
     * @param array $options
     * @return int
     */
    protected function createOrGetConfigurableAttribute($code, $options)
    {
        try {
            // Essayer de récupérer l'attribut s'il existe déjà
            $attribute = $this->attributeRepository->get(
                \Magento\Catalog\Model\Product::ENTITY,
                $code
            );

            // Vérifier si toutes les options existent
            $existingOptions = [];

            if ($attribute->usesSource()) {
                $attributeOptions = $attribute->getSource()->getAllOptions();
                foreach ($attributeOptions as $option) {
                    $existingOptions[$option['label']] = $option['value'];
                }
            }

            // Ajouter les options manquantes
            $newOptions = [];
            foreach ($options as $option) {
                if (!isset($existingOptions[$option])) {
                    $newOptions['option']['value'][$option][0] = $option;
                }
            }

            if (!empty($newOptions)) {
                $attribute->setData('option', $newOptions);
                $this->attributeRepository->save($attribute);
            }

            return $attribute->getAttributeId();
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            // L'attribut n'existe pas, le créer
            $attributeData = [
                'entity_type_id' => \Magento\Catalog\Model\Product::ENTITY,
                'attribute_code' => $code,
                'frontend_input' => 'select',
                'frontend_label' => ucfirst($code),
                'backend_type' => 'int',
                'is_required' => 0,
                'is_user_defined' => 1,
                'is_visible' => 1,
                'is_visible_on_front' => 1,
                'is_searchable' => 1,
                'is_filterable' => 1,
                'is_comparable' => 1,
                'is_visible_in_advanced_search' => 1,
                'is_used_for_promo_rules' => 0,
                'is_global' => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                'used_in_product_listing' => 1,
                'used_for_sort_by' => 1,
                'is_configurable' => 1,
                'option' => [
                    'values' => $options
                ]
            ];

            $attribute = $this->attributeFactory->create();
            $attribute->setData($attributeData);
            $attribute->save();

            // Ajouter l'attribut à l'ensemble d'attributs par défaut
            $this->addAttributeToDefaultAttributeSet($attribute);

            return $attribute->getAttributeId();
        }
    }

    /**
     * Add attribute to default attribute set
     *
     * @param \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute
     * @return void
     */
    protected function addAttributeToDefaultAttributeSet($attribute)
    {
        try {
            $attributeSetId = 4; // ID de l'ensemble d'attributs par défaut
            $attributeGroupId = null;

            $attributeSet = $this->attributeSetRepository->get($attributeSetId);
            $attributeGroupCollection = $attributeSet->getAttributeGroups();

            foreach ($attributeGroupCollection as $attributeGroup) {
                if ($attributeGroup->getAttributeGroupName() === 'General') {
                    $attributeGroupId = $attributeGroup->getAttributeGroupId();
                    break;
                }
            }

            if ($attributeGroupId) {
                $attribute->setAttributeSetId($attributeSetId);
                $attribute->setAttributeGroupId($attributeGroupId);
                $attribute->save();
            }
        } catch (\Exception $e) {
            $this->logger->error('Error adding attribute to default attribute set: ' . $e->getMessage());
        }
    }

    /**
     * Get attribute ID by code
     *
     * @param string $code
     * @return int|null
     */
    protected function getAttributeIdByCode($code)
    {
        try {
            $attribute = $this->attributeRepository->get(
                \Magento\Catalog\Model\Product::ENTITY,
                $code
            );

            return $attribute->getAttributeId();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Create simple product
     *
     * @param array $productData
     * @param array $attributes
     * @param array $categoryIds
     * @return \Magento\Catalog\Api\Data\ProductInterface
     */
    protected function createSimpleProduct($productData, $attributes, $categoryIds)
    {
        $product = $this->productFactory->create();

        $product->setSku($productData['sku']);
        $product->setName($productData['name']);
        $product->setPrice($productData['price']);
        $product->setAttributeSetId(4); // ID de l'ensemble d'attributs par défaut
        $product->setStatus(Status::STATUS_ENABLED);
        $product->setVisibility(Visibility::VISIBILITY_NOT_VISIBLE); // Les variantes ne sont pas visibles individuellement
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
        foreach ($attributes as $attributeId => $optionId) {
            $product->setData($this->getAttributeCodeById($attributeId), $optionId);
        }

        return $product;
    }

    /**
     * Get attribute code by ID
     *
     * @param int $attributeId
     * @return string|null
     */
    protected function getAttributeCodeById($attributeId)
    {
        try {
            $attribute = $this->attributeRepository->get(
                \Magento\Catalog\Model\Product::ENTITY,
                $attributeId
            );

            return $attribute->getAttributeCode();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get configurable attributes data
     *
     * @param array $attributeIds
     * @return array
     */
    protected function getConfigurableAttributesData($attributeIds)
    {
        $attributesData = [];

        foreach ($attributeIds as $attributeId) {
            try {
                $attribute = $this->attributeRepository->get(
                    \Magento\Catalog\Model\Product::ENTITY,
                    $attributeId
                );

                $attributesData[] = [
                    'attribute_id' => $attributeId,
                    'code' => $attribute->getAttributeCode(),
                    'label' => $attribute->getDefaultFrontendLabel(),
                    'position' => '0',
                    'values' => $this->getAttributeValues($attribute)
                ];
            } catch (\Exception $e) {
                $this->logger->error('Error getting attribute data: ' . $e->getMessage());
            }
        }

        return $attributesData;
    }

    /**
     * Get attribute values
     *
     * @param \Magento\Catalog\Api\Data\ProductAttributeInterface $attribute
     * @return array
     */
    protected function getAttributeValues($attribute)
    {
        $values = [];

        if ($attribute->usesSource()) {
            $attributeOptions = $attribute->getSource()->getAllOptions();
            foreach ($attributeOptions as $option) {
                if (!empty($option['value'])) {
                    $values[] = [
                        'value_index' => $option['value']
                    ];
                }
            }
        }

        return $values;
    }

    /**
     * Get associated product IDs
     *
     * @param array $products
     * @return array
     */
    protected function getAssociatedProductIds($products)
    {
        $ids = [];

        foreach ($products as $product) {
            $ids[] = $product->getId();
        }

        return $ids;
    }

    /**
     * Add product images from product data
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param array $productData
     * @return void
     */
    protected function addProductImages($product, $productData)
    {
        try {
            $images = [];

            // Log les données du produit pour déboguer
            $this->logger->debug('Product image data', [
                'has_productImage' => isset($productData['productImage']),
                'has_productImages' => isset($productData['productImages'])
            ]);

            // Ajouter l'image principale si disponible
            if (isset($productData['productImage']) && !empty($productData['productImage'])) {
                // Vérifier si c'est une chaîne JSON
                if (is_string($productData['productImage']) && $this->isJson($productData['productImage'])) {
                    $decodedImages = json_decode($productData['productImage'], true);
                    if (is_array($decodedImages) && !empty($decodedImages)) {
                        foreach ($decodedImages as $img) {
                            if (!empty($img)) {
                                $images[] = $img;
                            }
                        }
                    }
                } else {
                    $images[] = $productData['productImage'];
                }
            }

            // Ajouter les images supplémentaires si disponibles
            if (isset($productData['productImages']) && !empty($productData['productImages'])) {
                // Vérifier si c'est une chaîne JSON
                if (is_string($productData['productImages']) && $this->isJson($productData['productImages'])) {
                    $decodedImages = json_decode($productData['productImages'], true);
                    if (is_array($decodedImages)) {
                        foreach ($decodedImages as $img) {
                            if (!empty($img) && !in_array($img, $images)) {
                                $images[] = $img;
                            }
                        }
                    }
                } elseif (is_array($productData['productImages'])) {
                    foreach ($productData['productImages'] as $image) {
                        if (!empty($image) && !in_array($image, $images)) {
                            $images[] = $image;
                        }
                    }
                }
            }

            $this->logger->debug('Images to process', [
                'count' => count($images),
                'urls' => $images
            ]);

            // Télécharger et ajouter les images au produit
            foreach ($images as $index => $imageUrl) {
                $isMain = ($index === 0);
                $this->addProductImageFromUrl($product, $imageUrl, $isMain);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error adding product images: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Check if a string is valid JSON
     *
     * @param string $string
     * @return bool
     */
    protected function isJson($string)
    {
        if (!is_string($string)) {
            return false;
        }

        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    /**
     * Add product image from URL
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param string $imageUrl
     * @param bool $isMain
     * @return void
     */
    protected function addProductImageFromUrl($product, $imageUrl, $isMain = false)
    {
        try {
            $this->logger->debug('Processing image URL', ['url' => $imageUrl]);

            // S'assurer que l'URL est valide
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $this->logger->error('Invalid image URL', ['url' => $imageUrl]);
                return;
            }

            // Télécharger l'image
            $this->curl->setOptions([
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 30
            ]);
            $this->curl->get($imageUrl);
            $statusCode = $this->curl->getStatus();
            $imageContent = $this->curl->getBody();

            if ($statusCode == 200 && !empty($imageContent)) {
                // Extraire le nom du fichier de l'URL
                $fileName = basename(parse_url($imageUrl, PHP_URL_PATH));
                if (empty($fileName) || strlen($fileName) > 90) {
                    // Générer un nom de fichier s'il ne peut pas être extrait ou s'il est trop long
                    $fileName = 'image_' . md5($imageUrl) . '.jpg';
                }

                // Obtenir le répertoire media
                $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
                $tempFilePath = 'import/' . $fileName;

                // Enregistrer le contenu de l'image dans le répertoire media
                $mediaDirectory->writeFile($tempFilePath, $imageContent);

                // Ajouter l'image au produit
                $product->addImageToMediaGallery(
                    $mediaDirectory->getAbsolutePath($tempFilePath),
                    $isMain ? ['image', 'small_image', 'thumbnail'] : [],
                    false,
                    false
                );

                // Supprimer le fichier temporaire
                $mediaDirectory->delete($tempFilePath);

                $this->logger->debug('Image added to product', ['is_main' => $isMain]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error adding image from URL: ' . $e->getMessage(), [
                'url' => $imageUrl,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
