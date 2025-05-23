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

    /**
     * @param AttributeRepositoryInterface $attributeRepository
     * @param AttributeFactory $attributeFactory
     * @param AttributeSetRepositoryInterface $attributeSetRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param AttributeGroupRepositoryInterface $attributeGroupRepository
     * @param EavSetupFactory $eavSetupFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        AttributeRepositoryInterface      $attributeRepository,
        AttributeFactory                  $attributeFactory,
        AttributeSetRepositoryInterface   $attributeSetRepository,
        SearchCriteriaBuilder             $searchCriteriaBuilder,
        AttributeGroupRepositoryInterface $attributeGroupRepository,
        EavSetupFactory                   $eavSetupFactory,
        LoggerInterface                   $logger
    )
    {
        $this->attributeRepository = $attributeRepository;
        $this->attributeFactory = $attributeFactory;
        $this->attributeSetRepository = $attributeSetRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->attributeGroupRepository = $attributeGroupRepository;
        $this->eavSetupFactory = $eavSetupFactory;
        $this->logger = $logger;
    }

    /**
     * Detect configurable attributes from variants
     *
     * @param array $variants
     * @return array
     */
    public function detectConfigurableAttributes($variants)
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

                // Valider et nettoyer le code d'attribut
                $cleanCode = $this->validateAttributeCode($code);

                // Si l'attribut n'existe pas encore dans notre liste, l'ajouter
                if (!isset($configurableAttributes[$cleanCode])) {
                    $configurableAttributes[$cleanCode] = [];
                }

                // Convertir explicitement les valeurs numériques en chaînes de caractères
                // pour éviter les problèmes de comparaison float/int
                $valueKey = is_numeric($value) ? (string)$value : $value;

                // Ajouter la valeur de l'attribut à notre liste s'il n'existe pas déjà
                if (!isset($configurableAttributes[$cleanCode][$valueKey])) {
                    $configurableAttributes[$cleanCode][$valueKey] = $valueKey;
                }
            }
        }

        // Filtrer les attributs qui n'ont qu'une seule valeur (non configurables)
        foreach ($configurableAttributes as $code => $values) {
            if (count($values) <= 1) {
                unset($configurableAttributes[$code]);
            }
        }

        // Log des attributs détectés pour le débogage
        $this->logger->debug('Attributs configurables détectés', [
            'configurableAttributes' => $configurableAttributes
        ]);

        return $configurableAttributes;
    }

    /**
     * Validate attribute code
     *
     * @param string $code
     * @return string
     */
    public function validateAttributeCode($code)
    {
        // Nettoyer le code d'attribut (supprimer les caractères spéciaux, espaces, etc.)
        $cleanCode = preg_replace('/[^a-zA-Z0-9_]/', '_', $code);
        $cleanCode = strtolower($cleanCode);

        // S'assurer que le code commence par une lettre
        if (!preg_match('/^[a-z]/', $cleanCode)) {
            $cleanCode = 'attr_' . $cleanCode;
        }

        // Limiter la longueur à 30 caractères (limitation de Magento)
        if (strlen($cleanCode) > 30) {
            $cleanCode = substr($cleanCode, 0, 30);
        }

        // Éviter les codes réservés
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
            // Essayer de récupérer l'attribut s'il existe déjà
            $attribute = $this->attributeRepository->get(
                \Magento\Catalog\Model\Product::ENTITY,
                $code
            );

            $attributeId = $attribute->getAttributeId();
            $optionsMap = [];

            // Vérifier si toutes les options existent
            if ($attribute->usesSource()) {
                $attributeOptions = $attribute->getSource()->getAllOptions();
                foreach ($attributeOptions as $option) {
                    $optionsMap[$option['label']] = $option['value'];
                }
            }

            // Ajouter les options manquantes
            $newOptions = [];
            foreach ($options as $option) {
                if (!isset($optionsMap[$option])) {
                    $newOptions['option']['value'][$option][0] = $option;
                }
            }

            if (!empty($newOptions)) {
                $attribute->setData('option', $newOptions);
                $this->attributeRepository->save($attribute);

                // Récupérer à nouveau les options après l'ajout
                $attributeOptions = $attribute->getSource()->getAllOptions();
                foreach ($attributeOptions as $option) {
                    $optionsMap[$option['label']] = $option['value'];
                }
            }

            return [
                'attribute_id' => $attributeId,
                'options' => $optionsMap
            ];
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            // L'attribut n'existe pas, le créer
            try {
                // Obtenir l'ID du type d'entité pour les produits
                $entityTypeId = $this->getEntityTypeId(\Magento\Catalog\Model\Product::ENTITY);

                if (!$entityTypeId) {
                    throw new \Exception('Entity type ID for products not found');
                }

                $optionValues = [];
                foreach ($options as $option) {
                    $optionValues['option']['value'][$option][0] = $option;
                }

                $eavSetup = $this->eavSetupFactory->create();
                $attributeId = $eavSetup->addAttribute(
                    \Magento\Catalog\Model\Product::ENTITY,
                    $code,
                    [
                        'type' => 'int',
                        'backend' => '',
                        'frontend' => '',
                        'label' => ucfirst($code),
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
                    ]
                );

                // Ajouter l'attribut à l'ensemble d'attributs par défaut
                $eavSetup->addAttributeToGroup(
                    \Magento\Catalog\Model\Product::ENTITY,
                    4, // Attribute Set ID (default)
                    'General', // Attribute Group
                    $code,
                    90 // Sort Order
                );

                // Récupérer les options de l'attribut nouvellement créé
                $attribute = $this->attributeRepository->get(
                    \Magento\Catalog\Model\Product::ENTITY,
                    $code
                );

                $optionsMap = [];
                if ($attribute->usesSource()) {
                    $attributeOptions = $attribute->getSource()->getAllOptions();
                    foreach ($attributeOptions as $option) {
                        $optionsMap[$option['label']] = $option['value'];
                    }
                }

                return [
                    'attribute_id' => $attribute->getAttributeId(),
                    'options' => $optionsMap
                ];
            } catch (\Exception $innerException) {
                $this->logger->error('Erreur lors de la création de l\'attribut configurable: ' . $innerException->getMessage(), [
                    'code' => $code,
                    'options' => $options,
                    'trace' => $innerException->getTraceAsString()
                ]);

                throw $innerException;
            }
        }
    }

    /**
     * Get entity type ID by entity type code
     *
     * @param string $entityTypeCode
     * @return int|null
     */
    public function getEntityTypeId($entityTypeCode)
    {
        try {
            // Utiliser le EavSetup pour obtenir l'entityTypeId
            $eavSetup = $this->eavSetupFactory->create();
            return $eavSetup->getEntityTypeId($entityTypeCode);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la récupération de l\'ID du type d\'entité: ' . $e->getMessage());
            return null;
        }
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
                $attribute = $this->attributeRepository->get(
                    \Magento\Catalog\Model\Product::ENTITY,
                    $attributeId
                );

                if (!$attribute || !$attribute->getAttributeId()) {
                    $this->logger->warning('Attribut non trouvé ou ID manquant pour: ' . $attributeId);
                    continue;
                }

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
                    'attribute_id' => $attributeId,
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        // Important : Log des attributs pour débogage
        $this->logger->debug('Configurable attributes data:', [
            'data' => json_encode($attributesData)
        ]);

        return $attributesData;
    }
}
