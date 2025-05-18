<?php
namespace Gab\Dropshipping\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Gab\Dropshipping\Model\Config;

class Dashboard extends Template
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @param Context $context
     * @param Config $config
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $config,
        array $data = []
    ) {
        $this->config = $config;
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
     * Get API status
     *
     * @return string
     */
    public function getApiStatus()
    {
        return $this->config->getApiKey() ? __('Connected') : __('Not Connected');
    }
}
