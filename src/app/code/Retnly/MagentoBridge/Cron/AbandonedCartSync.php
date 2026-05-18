<?php

declare(strict_types=1);

namespace Retnly\MagentoBridge\Cron;

use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;
use Retnly\MagentoBridge\Helper\Api;

class AbandonedCartSync
{
    private const ABANDONED_AFTER_MINUTES = 60;

    private Api $api;
    private QuoteCollectionFactory $quoteCollectionFactory;

    public function __construct(
        Api $api,
        QuoteCollectionFactory $quoteCollectionFactory
    ) {
        $this->api                    = $api;
        $this->quoteCollectionFactory = $quoteCollectionFactory;
    }

    public function execute(): void
    {
        if (!$this->api->isEnabled()) {
            return;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::ABANDONED_AFTER_MINUTES . ' minutes'));

        $collection = $this->quoteCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1)
                   ->addFieldToFilter('customer_email', ['notnull' => true])
                   ->addFieldToFilter('items_count', ['gt' => 0])
                   ->addFieldToFilter('updated_at', ['lt' => $cutoff]);

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

            $this->api->post('abandoned-carts/', $payload);
        }
    }
}
