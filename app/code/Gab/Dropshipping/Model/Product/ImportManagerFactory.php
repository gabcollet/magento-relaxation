<?php

namespace Gab\Dropshipping\Model\Product;

class ImportManagerFactory
{
    /**
     * Object Manager
     *
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * Constructor
     *
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     */
    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager
    )
    {
        $this->objectManager = $objectManager;
    }

    /**
     * Create Import Manager instance
     *
     * @return \Gab\Dropshipping\Model\Product\ImportManager
     */
    public function create()
    {
        return $this->objectManager->create(\Gab\Dropshipping\Model\Product\ImportManager::class);
    }
}
