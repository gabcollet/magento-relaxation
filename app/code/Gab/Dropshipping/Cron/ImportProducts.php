<?php
namespace Gab\Dropshipping\Cron;

use Gab\Dropshipping\Model\Config;
use Gab\Dropshipping\Model\Api\Client;
use Psr\Log\LoggerInterface;

class ImportProducts
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Client
     */
    protected $apiClient;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Config $config
     * @param Client $apiClient
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        Client $apiClient,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->apiClient = $apiClient;
        $this->logger = $logger;
    }

    /**
     * Execute cron job
     *
     * @return void
     */
    public function execute()
    {
        if (!$this->config->isEnabled() || !$this->config->isAutoImportEnabled()) {
            return;
        }

        try {
            $this->logger->info('Starting product import from CJ Dropshipping');

            // Your import logic will go here
            // For now, just logging

            $this->logger->info('Product import from CJ Dropshipping completed');
        } catch (\Exception $e) {
            $this->logger->error('Error during product import: ' . $e->getMessage());
        }
    }
}
