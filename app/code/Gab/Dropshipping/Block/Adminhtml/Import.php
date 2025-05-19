<?php
namespace Gab\Dropshipping\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Gab\Dropshipping\Model\Config;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;

class Import extends Template
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var CategoryFactory
     */
    protected $categoryFactory;

    /**
     * @var CategoryCollectionFactory
     */
    protected $categoryCollectionFactory;

    /**
     * @param Context $context
     * @param Config $config
     * @param CategoryFactory $categoryFactory
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $config,
        CategoryFactory $categoryFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        array $data = []
    ) {
        $this->config = $config;
        $this->categoryFactory = $categoryFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        parent::__construct($context, $data);
    }

    /**
     * Check if module is enabled
     *
     * @return bool
     */
    public function isModuleEnabled()
    {
        return $this->config->isEnabled();
    }

    /**
     * Get URL for search action
     *
     * @return string
     */
    public function getSearchUrl()
    {
        return $this->getUrl('dropshipping/import/search');
    }

    /**
     * Get URL for details action
     *
     * @return string
     */
    public function getDetailsUrl()
    {
        return $this->getUrl('dropshipping/import/details');
    }

    /**
     * Get URL for import action
     *
     * @return string
     */
    public function getImportUrl()
    {
        return $this->getUrl('dropshipping/import/importProduct');
    }

    /**
     * Get URL for variants action
     *
     * @return string
     */
    public function getVariantsUrl()
    {
        return $this->getUrl('dropshipping/import/getVariants');
    }

    /**
     * Get price markup percentage
     *
     * @return float
     */
    public function getMarkupPercentage()
    {
        return $this->config->getMarkupPercentage();
    }

    /**
     * Get default stock quantity
     *
     * @return int
     */
    public function getDefaultStock()
    {
        return $this->config->getDefaultStock();
    }

    /**
     * Get category options for select field
     *
     * @return array
     */
    public function getCategoryOptions()
    {
        $options = [];

        try {
            $collection = $this->categoryCollectionFactory->create();
            $collection->addAttributeToSelect('name')
                ->addFieldToFilter('level', ['gt' => 0])
                ->addIsActiveFilter()
                ->setOrder('position', 'ASC');

            foreach ($collection as $category) {
                $options[] = [
                    'value' => $category->getId(),
                    'label' => $this->getCategoryPath($category)
                ];
            }
        } catch (\Exception $e) {
            // Fallback to default category if error occurs
            $options[] = [
                'value' => 2,
                'label' => __('Default Category')
            ];
        }

        return $options;
    }

    /**
     * Get category path
     *
     * @param \Magento\Catalog\Model\Category $category
     * @return string
     */
    protected function getCategoryPath($category)
    {
        $path = [];
        $pathIds = $category->getPathIds();

        // Skip root category (usually ID 1)
        array_shift($pathIds);

        foreach ($pathIds as $categoryId) {
            if ($categoryId == $category->getId()) {
                $path[] = $category->getName();
            } else {
                $parentCategory = $this->categoryFactory->create()->load($categoryId);
                $path[] = $parentCategory->getName();
            }
        }

        return implode(' > ', $path);
    }
}
