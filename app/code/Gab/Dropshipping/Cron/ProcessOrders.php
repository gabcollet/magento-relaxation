<?php
namespace Gab\Dropshipping\Cron;

use Gab\Dropshipping\Model\Config;
use Gab\Dropshipping\Model\Api\Client;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

class ProcessOrders
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
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Config $config
     * @param Client $apiClient
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        Client $apiClient,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->apiClient = $apiClient;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->logger = $logger;
    }

    /**
     * Execute cron job for processing orders
     *
     * @return void
     */
    public function execute()
    {
        if (!$this->config->isEnabled() || !$this->config->isAutoSubmitEnabled()) {
            return;
        }

        try {
            $this->logger->info('Starting order processing for CJ Dropshipping');

            // Get eligible order statuses
            $orderStatuses = $this->config->getOrderStatuses();
            if (empty($orderStatuses)) {
                $orderStatuses = ['processing']; // Default
            }

            // Find orders with the eligible statuses
            $this->searchCriteriaBuilder->addFilter('status', $orderStatuses, 'in');

            // Only process orders that haven't been sent to CJ yet
            $this->searchCriteriaBuilder->addFilter('dropship_processed', 0);

            $searchCriteria = $this->searchCriteriaBuilder->create();
            $orders = $this->orderRepository->getList($searchCriteria)->getItems();

            if (empty($orders)) {
                $this->logger->info('No new orders to process for dropshipping');
                return;
            }

            $this->logger->info(sprintf('Found %d orders to process for dropshipping', count($orders)));

            foreach ($orders as $order) {
                $this->processOrder($order);
            }

            $this->logger->info('Order processing for CJ Dropshipping completed');
        } catch (\Exception $e) {
            $this->logger->error('Error during order processing: ' . $e->getMessage());
        }
    }

    /**
     * Process a single order
     *
     * @param Order $order
     * @return void
     */
    protected function processOrder(Order $order)
    {
        try {
            $orderItems = $order->getAllItems();
            $dropshipItems = [];

            // Find which items are from CJ (based on SKU prefix or other identifier)
            foreach ($orderItems as $item) {
                $sku = $item->getSku();
                if (strpos($sku, 'CJ-') === 0) {
                    // Extract CJ product ID
                    $pid = str_replace('CJ-', '', $sku);

                    // Add to dropship items
                    $dropshipItems[] = [
                        'pid' => $pid,
                        'quantity' => (int)$item->getQtyOrdered(),
                        'variant_id' => '', // You would need to store and retrieve this
                    ];
                }
            }

            if (empty($dropshipItems)) {
                // No CJ products in this order
                $this->logger->info(sprintf('Order #%s has no dropshipping products', $order->getIncrementId()));
                return;
            }

            // Prepare order data for CJ
            $shippingAddress = $order->getShippingAddress();
            $billingAddress = $order->getBillingAddress();

            $orderData = [
                'orderNumber' => $order->getIncrementId(),
                'shippingCountryCode' => $shippingAddress->getCountryId(),
                'shippingProvince' => $shippingAddress->getRegion(),
                'shippingCity' => $shippingAddress->getCity(),
                'shippingAddress' => implode(', ', $shippingAddress->getStreet()),
                'shippingZip' => $shippingAddress->getPostcode(),
                'shippingCustomerName' => $shippingAddress->getFirstname() . ' ' . $shippingAddress->getLastname(),
                'shippingPhone' => $shippingAddress->getTelephone(),
                'customerEmail' => $order->getCustomerEmail(),
                'logisticName' => 'CJPacket', // Default shipping method, adjust as needed
                'products' => $dropshipItems
            ];

            // Submit order to CJ
            $response = $this->apiClient->createOrder($orderData);

            if (isset($response['data']['orderId'])) {
                // Order successfully submitted
                $cjOrderId = $response['data']['orderId'];

                // Save the CJ order ID to the Magento order
                // You would need a custom field for this
                // $order->setData('cj_order_id', $cjOrderId);

                // Mark the order as processed
                $order->setData('dropship_processed', 1);

                // Add a comment to the order
                $order->addCommentToStatusHistory(
                    sprintf('Order submitted to CJ Dropshipping. CJ Order ID: %s', $cjOrderId),
                    false,
                    true
                );

                $this->orderRepository->save($order);

                $this->logger->info(sprintf(
                    'Order #%s successfully submitted to CJ Dropshipping. CJ Order ID: %s',
                    $order->getIncrementId(),
                    $cjOrderId
                ));
            } else {
                // Failed to submit
                $errorMessage = isset($response['error']) ? $response['error'] : 'Unknown error';

                $order->addCommentToStatusHistory(
                    sprintf('Failed to submit order to CJ Dropshipping. Error: %s', $errorMessage),
                    false,
                    true
                );

                $this->orderRepository->save($order);

                $this->logger->error(sprintf(
                    'Failed to submit Order #%s to CJ Dropshipping. Error: %s',
                    $order->getIncrementId(),
                    $errorMessage
                ));
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Exception processing Order #%s: %s',
                $order->getIncrementId(),
                $e->getMessage()
            ));
        }
    }
}
