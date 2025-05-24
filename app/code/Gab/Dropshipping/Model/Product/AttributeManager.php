<?php

namespace Gab\Dropshipping\Model\Product;

use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Eav\AttributeFactory;
use Magento\Eav\Api\AttributeGroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Catalog\Api\AttributeSetRepositoryInterface;
use Psr\Log\LoggerInterface;

class AttributeManager
{
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
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * @var AttributeGroupRepositoryInterface
     */
    protected $attributeGroupRepository;

    /**
     * @var EavSetupFactory
     */
    protected $eavSetupFactory;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(
        AttributeRepositoryInterface      $attributeRepository,
        AttributeFactory                  $attributeFactory,
        AttributeSetRepositoryInterface   $attributeSetRepository,
        SearchCriteriaBuilder             $searchCriteriaBuilder,
        AttributeGroupRepositoryInterface $attributeGroupRepository,
        EavSetupFactory                   $eavSetupFactory,
        LoggerInterface                   $logger
    ) {
        $this->attributeRepository = $attributeRepository;
        $this->attributeFactory = $attributeFactory;
        $this->attributeSetRepository = $attributeSetRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->attributeGroupRepository = $attributeGroupRepository;
        $this->eavSetupFactory = $eavSetupFactory;
        $this->logger = $logger;
    }

    /**
     * Get variant attributes for selection
     *
     * @param array $variants
     * @return array
     */
    public function getVariantAttributesForSelection($variants)
    {
        $allAttributes = $this->extractAllVariantAttributes($variants);
        $selectableAttributes = [];

        foreach ($allAttributes as $code => $values) {
            if ($this->isAttributeConfigurable($values)) {
                $selectableAttributes[$code] = [
                    'code' => $code,
                    'label' => $this->generateAttributeLabel($code),
                    'values' => array_values($values),
                    'sample_values' => array_slice($values, 0, 3)
                ];
            }
        }

        return $selectableAttributes;
    }

    /**
     * Extract all variant attributes
     *
     * @param array $variants
     * @return array
     */
    protected function extractAllVariantAttributes($variants)
    {
        $attributes = [];
        $systemAttributes = ['vid', 'variantSku', 'variantImage', 'variantSellPrice', 'variantStock', 'variantSugSellPrice'];

        foreach ($variants as $variant) {
            foreach ($variant as $code => $value) {
                if (in_array($code, $systemAttributes) || empty($value)) {
                    continue;
                }

                $cleanCode = $this->validateAttributeCode($code);
                $valueKey = is_numeric($value) ? (string)$value : $value;

                if (!isset($attributes[$cleanCode])) {
                    $attributes[$cleanCode] = [];
                }

                $attributes[$cleanCode][$valueKey] = $valueKey;
            }
        }

        return $attributes;
    }

    /**
     * Check if attribute is configurable (has multiple values)
     *
     * @param array $values
     * @return bool
     */
    protected function isAttributeConfigurable($values)
    {
        return count($values) > 1;
    }

    /**
     * Generate human-readable label from attribute code
     *
     * @param string $code
     * @return string
     */
    protected function generateAttributeLabel($code)
    {
        return ucwords(str_replace(['_', '-'], ' ', $code));
    }

    /**
     * Create configurable attributes from selected codes
     *
     * @param array $selectedAttributes
     * @param array $variants
     * @return array
     */
    public function createSelectedConfigurableAttributes($selectedAttributes, $variants)
    {
        $attributeMap = [];
        $allAttributes = $this->extractAllVariantAttributes($variants);

        foreach ($selectedAttributes as $code) {
            if (!isset($allAttributes[$code])) {
                continue;
            }

            try {
                $attributeData = $this->createOrGetConfigurableAttribute($code, array_values($allAttributes[$code]));
                $attributeMap[$attributeData['attribute_id']] = [
                    'code' => $code,
                    'options' => $attributeData['options'],
                    'indexed_options' => $this->indexAttributeOptionsByLabel($attributeData['options'])
                ];
            } catch (\Exception $e) {
                $this->logger->warning('Failed to create attribute: ' . $code . '. Error: ' . $e->getMessage());
                continue;
            }
        }

        return $attributeMap;
    }

    /**
     * Validate attribute code
     *
     * @param string $code
     * @return string
     */
    public function validateAttributeCode($code)
    {
        $cleanCode = preg_replace('/[^a-zA-Z0-9_]/', '_', $code);
        $cleanCode = strtolower($cleanCode);

        if (!preg_match('/^[a-z]/', $cleanCode)) {
            $cleanCode = 'attr_' . $cleanCode;
        }

        if (strlen($cleanCode) > 30) {
            $cleanCode = substr($cleanCode, 0, 30);
        }

        $reservedWords = ['url_key', 'status', 'visibility', 'category_ids'];
        if (in_array($cleanCode, $reservedWords)) {
            $cleanCode = 'cj_' . $cleanCode;
        }

        return $cleanCode;
    }

    /**
     * Create or get configurable attribute
     *
     * @param string $code
     * @param array $options
     * @return array
     */
    public function createOrGetConfigurableAttribute($code, $options)
    {
        try {
            return $this->getExistingAttribute($code);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            return $this->createNewAttribute($code, $options);
        }
    }

    /**
     * Get existing attribute
     *
     * @param string $code
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getExistingAttribute($code)
    {
        $attribute = $this->attributeRepository->get(\Magento\Catalog\Model\Product::ENTITY, $code);

        $finalOptions = [];
        if ($attribute->usesSource()) {
            $attributeOptions = $attribute->getSource()->getAllOptions();
            foreach ($attributeOptions as $option) {
                if (!empty($option['value']) && $option['value'] !== '') {
                    $finalOptions[] = [
                        'label' => $option['label'],
                        'value_index' => $option['value'],
                    ];
                }
            }
        }

        return [
            'attribute_id' => $attribute->getAttributeId(),
            'code' => $attribute->getAttributeCode(),
            'label' => $attribute->getDefaultFrontendLabel(),
            'options' => $finalOptions
        ];
    }

    /**
     * Create new attribute
     *
     * @param string $code
     * @param array $options
     * @return array
     */
    protected function createNewAttribute($code, $options)
    {
        try {
            $optionValues = $this->prepareOptionValues($options);
            $eavSetup = $this->eavSetupFactory->create();

            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                $code,
                $this->getAttributeConfig($code, $optionValues)
            );

            $this->addAttributeToDefaultGroup($eavSetup, $code);

            // Reload attribute to get fresh data
            $attribute = $this->attributeRepository->get(\Magento\Catalog\Model\Product::ENTITY, $code);

            return $this->getExistingAttribute($code);
        } catch (\Exception $e) {
            $this->logger->error('Error creating attribute: ' . $code . '. Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Prepare option values for attribute creation
     *
     * @param array $options
     * @return array
     */
    protected function prepareOptionValues($options)
    {
        $optionValues = [];
        foreach ($options as $option) {
            $optionValues['option']['value'][$option][0] = $option;
        }
        return $optionValues;
    }

    /**
     * Get attribute configuration
     *
     * @param string $code
     * @param array $optionValues
     * @return array
     */
    protected function getAttributeConfig($code, $optionValues)
    {
        return [
            'type' => 'int',
            'backend' => '',
            'frontend' => '',
            'label' => $this->generateAttributeLabel($code),
            'input' => 'select',
            'class' => '',
            'source' => '',
            'global' => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
            'visible' => true,
            'required' => false,
            'user_defined' => true,
            'default' => '',
            'searchable' => true,
            'filterable' => true,
            'comparable' => true,
            'visible_on_front' => true,
            'used_in_product_listing' => true,
            'unique' => false,
            'option' => $optionValues
        ];
    }

    /**
     * Add attribute to default group
     *
     * @param \Magento\Eav\Setup\EavSetup $eavSetup
     * @param string $code
     * @return void
     */
    protected function addAttributeToDefaultGroup($eavSetup, $code)
    {
        $eavSetup->addAttributeToGroup(
            \Magento\Catalog\Model\Product::ENTITY,
            4, // Default attribute set ID
            'General',
            $code,
            90
        );
    }

    /**
     * Get configurable attributes data
     *
     * @param array $attributeIds
     * @return array
     */
    public function getConfigurableAttributesData($attributeIds)
    {
        $attributesData = [];

        foreach ($attributeIds as $attributeId) {
            try {
                $attribute = $this->attributeRepository->get(\Magento\Catalog\Model\Product::ENTITY, $attributeId);

                if (!$attribute->getAttributeId()) {
                    continue;
                }

                $attrValues = $this->getAttributeValues($attribute);

                if (!empty($attrValues)) {
                    $attributesData[] = [
                        'attribute_id' => $attribute->getAttributeId(),
                        'code' => $attribute->getAttributeCode(),
                        'label' => $attribute->getDefaultFrontendLabel(),
                        'position' => '0',
                        'values' => $attrValues
                    ];
                }
            } catch (\Exception $e) {
                $this->logger->error('Error getting attribute data: ' . $e->getMessage(), [
                    'attribute_id' => $attributeId
                ]);
            }
        }

        return $attributesData;
    }

    /**
     * Get attribute values
     *
     * @param \Magento\Eav\Api\Data\AttributeInterface $attribute
     * @return array
     */
    protected function getAttributeValues($attribute)
    {
        $attrValues = [];

        if ($attribute->usesSource()) {
            $options = $attribute->getSource()->getAllOptions();
            foreach ($options as $option) {
                if (!empty($option['value']) && $option['value'] !== '') {
                    $attrValues[] = [
                        'label' => $option['label'],
                        'attribute_id' => $attribute->getAttributeId(),
                        'value_index' => $option['value'],
                    ];
                }
            }
        }

        return $attrValues;
    }

    /**
     * Index attribute options by label
     *
     * @param array $options
     * @return array
     */
    public function indexAttributeOptionsByLabel(array $options): array
    {
        $indexed = [];
        foreach ($options as $option) {
            if (isset($option['label'], $option['value_index'])) {
                $indexed[strtolower($option['label'])] = $option['value_index'];
            }
        }
        return $indexed;
    }
}
