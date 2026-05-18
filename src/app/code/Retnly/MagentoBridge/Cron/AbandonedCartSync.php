<?php

declare(strict_types=1);

namespace Retnly\MagentoBridge\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;
use Psr\Log\LoggerInterface;
use Retnly\MagentoBridge\Helper\Api;

class AbandonedCartSync
{
    private const ABANDONED_AFTER_MINUTES = 60;
    private const SYNC_TABLE              = 'retnly_abandoned_cart_sync';

    private Api $api;
    private QuoteCollectionFactory $quoteCollectionFactory;
    private ResourceConnection $resourceConnection;
    private LoggerInterface $logger;

    public function __construct(
        Api $api,
        QuoteCollectionFactory $quoteCollectionFactory,
        ResourceConnection $resourceConnection,
        LoggerInterface $logger
    ) {
        $this->api                    = $api;
        $this->quoteCollectionFactory = $quoteCollectionFactory;
        $this->resourceConnection     = $resourceConnection;
        $this->logger                 = $logger;
    }

    public function execute(): void
    {
        if (!$this->api->isEnabled()) {
            return;
        }

        $cutoff    = date('Y-m-d H:i:s', strtotime('-' . self::ABANDONED_AFTER_MINUTES . ' minutes'));
        $syncTable = $this->resourceConnection->getTableName(self::SYNC_TABLE);
        $conn      = $this->resourceConnection->getConnection();

        $collection = $this->quoteCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1)
                   ->addFieldToFilter('customer_email', ['notnull' => true])
                   ->addFieldToFilter('items_count', ['gt' => 0])
                   ->addFieldToFilter('updated_at', ['lt' => $cutoff]);

        // Skip quotes already synced to Retnly. The LEFT JOIN with IS NULL filter
        // is what makes this cron idempotent: a quote is sent exactly once.
        $collection->getSelect()->joinLeft(
            ['retnly_sync' => $syncTable],
            'main_table.entity_id = retnly_sync.quote_id',
            []
        )->where('retnly_sync.synced_at IS NULL');

        foreach ($collection as $quote) {
            $items = [];
            foreach ($quote->getAllVisibleItems() as $item) {
                $items[] = [
                    'sku'        => $item->getSku(),
                    'name'       => $item->getName(),
                    'qty'        => (float) $item->getQty(),
                    'price'      => (float) $item->getPrice(),
                    'row_total'  => (float) $item->getRowTotal(),
                    'product_id' => (int) $item->getProductId(),
                ];
            }

            $payload = [
                'store_id'           => $this->api->getStoreId(),
                'quote_id'           => (int) $quote->getId(),
                'customer_email'     => $quote->getCustomerEmail(),
                'customer_firstname' => $quote->getCustomerFirstname(),
                'customer_lastname'  => $quote->getCustomerLastname(),
                'customer_id'        => $quote->getCustomerId() !== null
                                            ? (int) $quote->getCustomerId()
                                            : null,
                'grand_total'        => (float) $quote->getGrandTotal(),
                'subtotal'           => (float) $quote->getSubtotal(),
                'items_count'        => (int) $quote->getItemsCount(),
                'items'              => $items,
                'currency_code'      => $quote->getQuoteCurrencyCode(),
                'created_at'         => $quote->getCreatedAt(),
                'updated_at'         => $quote->getUpdatedAt(),
            ];

            $status = $this->api->post('abandoned-carts/', $payload);

            if ($status !== null && $status >= 200 && $status < 300) {
                try {
                    $conn->insert($syncTable, [
                        'quote_id'  => (int) $quote->getId(),
                        'synced_at' => date('Y-m-d H:i:s'),
                    ]);
                } catch (\Exception $e) {
                    // Race: quote could be deleted between SELECT and INSERT,
                    // or another process inserted the same row. Log and keep going
                    // so a single bad row does not abort the whole cron run.
                    $this->logger->warning(sprintf(
                        '[Retnly] Failed to record sync state for quote_id=%d: %s',
                        (int) $quote->getId(),
                        $e->getMessage()
                    ));
                }
            }
            // Failed POSTs (non-2xx or null): no row written, so the cart is picked
            // up again on the next cron run until it succeeds.
        }
    }
}
