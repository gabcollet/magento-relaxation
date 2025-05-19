<?php

namespace Gab\Dropshipping\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    const XML_PATH_ENABLED = 'dropshipping/general/enabled';
    const XML_PATH_API_KEY = 'dropshipping/general/api_key';
    const XML_PATH_API_URL = 'dropshipping/general/api_url';
    const XML_PATH_EMAIL = 'dropshipping/general/email';
    const XML_PATH_MARKUP_PERCENTAGE = 'dropshipping/import/markup_percentage';
    const XML_PATH_DEFAULT_STOCK = 'dropshipping/import/default_stock';
    const XML_PATH_AUTO_IMPORT_CRON = 'dropshipping/import/auto_import_cron';
    const XML_PATH_CRON_FREQUENCY = 'dropshipping/import/cron_frequency';
    const XML_PATH_AUTO_SUBMIT = 'dropshipping/orders/auto_submit';
    const XML_PATH_ORDER_STATUS = 'dropshipping/orders/order_status';

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig
    )
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Check if module is enabled
     *
     * @param string $scopeType
     * @param null|string $scopeCode
     * @return bool
     */
    public function isEnabled($scopeType = ScopeInterface::SCOPE_STORE, $scopeCode = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            $scopeType,
            $scopeCode
        );
    }

    /**
     * Get API Key
     *
     * @param string $scopeType
     * @param null|string $scopeCode
     * @return string
     */
    public function getApiKey($scopeType = ScopeInterface::SCOPE_STORE, $scopeCode = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_API_KEY,
            $scopeType,
            $scopeCode
        );
    }

    /**
     * Get Email
     *
     * @param string $scopeType
     * @param null|string $scopeCode
     * @return string
     */
    public function getEmail($scopeType = ScopeInterface::SCOPE_STORE, $scopeCode = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_EMAIL,
            $scopeType,
            $scopeCode
        );
    }

    /**
     * Get API URL
     *
     * @param string $scopeType
     * @param null|string $scopeCode
     * @return string
     */
    public function getApiUrl($scopeType = ScopeInterface::SCOPE_STORE, $scopeCode = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_API_URL,
            $scopeType,
            $scopeCode
        );
    }

    /**
     * Get Price Markup Percentage
     *
     * @param string $scopeType
     * @param null|string $scopeCode
     * @return float
     */
    public function getMarkupPercentage($scopeType = ScopeInterface::SCOPE_STORE, $scopeCode = null)
    {
        return (float)$this->scopeConfig->getValue(
            self::XML_PATH_MARKUP_PERCENTAGE,
            $scopeType,
            $scopeCode
        );
    }

    /**
     * Get Default Stock Quantity
     *
     * @param string $scopeType
     * @param null|string $scopeCode
     * @return int
     */
    public function getDefaultStock($scopeType = ScopeInterface::SCOPE_STORE, $scopeCode = null)
    {
        return (int)$this->scopeConfig->getValue(
            self::XML_PATH_DEFAULT_STOCK,
            $scopeType,
            $scopeCode
        );
    }

    /**
     * Check if auto import via cron is enabled
     *
     * @param string $scopeType
     * @param null|string $scopeCode
     * @return bool
     */
    public function isAutoImportEnabled($scopeType = ScopeInterface::SCOPE_STORE, $scopeCode = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_AUTO_IMPORT_CRON,
            $scopeType,
            $scopeCode
        );
    }

    /**
     * Get cron frequency
     *
     * @param string $scopeType
     * @param null|string $scopeCode
     * @return string
     */
    public function getCronFrequency($scopeType = ScopeInterface::SCOPE_STORE, $scopeCode = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_CRON_FREQUENCY,
            $scopeType,
            $scopeCode
        );
    }

    /**
     * Check if auto submit orders is enabled
     *
     * @param string $scopeType
     * @param null|string $scopeCode
     * @return bool
     */
    public function isAutoSubmitEnabled($scopeType = ScopeInterface::SCOPE_STORE, $scopeCode = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_AUTO_SUBMIT,
            $scopeType,
            $scopeCode
        );
    }

    /**
     * Get order statuses for processing
     *
     * @param string $scopeType
     * @param null|string $scopeCode
     * @return array
     */
    public function getOrderStatuses($scopeType = ScopeInterface::SCOPE_STORE, $scopeCode = null)
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_ORDER_STATUS,
            $scopeType,
            $scopeCode
        );

        return $value ? explode(',', $value) : [];
    }
}
