<?php

declare(strict_types=1);

namespace Retnly\MagentoBridge\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Retnly\MagentoBridge\Helper\Api;
use Retnly\MagentoBridge\Model\EventOutbox;

class OrderRefundedObserver implements ObserverInterface
{
    private Api $api;
    private EventOutbox $outbox;

    public function __construct(Api $api, EventOutbox $outbox)
    {
        $this->api    = $api;
        $this->outbox = $outbox;
    }

    public function execute(Observer $observer): void
    {
        if (!$this->api->isEnabled()) {
            return;
        }

        /** @var \Magento\Sales\Model\Order\Creditmemo $creditmemo */
        $creditmemo = $observer->getEvent()->getCreditmemo();
        /** @var \Magento\Sales\Model\Order $order */
        $order = $creditmemo->getOrder();

        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                'sku'        => $item->getSku(),
                'name'       => $item->getName(),
                'qty'        => (float) $item->getQtyOrdered(),
                'price'      => (float) $item->getPrice(),
                'row_total'  => (float) $item->getRowTotal(),
                'product_id' => (int) $item->getProductId(),
            ];
        }

        $billing  = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();

        $payload = [
            'store_id'            => $this->api->getStoreId(),
            'increment_id'        => $order->getIncrementId(),
            'grand_total'         => (float) $order->getGrandTotal(),
            'subtotal'            => (float) $order->getSubtotal(),
            'customer_email'      => $order->getCustomerEmail(),
            'customer_firstname'  => $order->getCustomerFirstname(),
            'customer_lastname'   => $order->getCustomerLastname(),
            'customer_is_guest'   => (bool) $order->getCustomerIsGuest(),
            'customer_id'         => $order->getCustomerId() !== null
                                        ? (int) $order->getCustomerId()
                                        : null,
            'state'               => $order->getState(),
            'status'              => $order->getStatus(),
            'items'               => $items,
            'billing_address'     => $billing  ? $billing->getData()  : null,
            'shipping_address'    => $shipping ? $shipping->getData() : null,
            'payment'             => $order->getPayment()
                                        ? ['method' => $order->getPayment()->getMethod()]
                                        : null,
            'currency_code'       => $order->getOrderCurrencyCode(),
            'created_at'          => $order->getCreatedAt(),
            'updated_at'          => $order->getUpdatedAt(),
            'refund_amount'       => (float) $creditmemo->getGrandTotal(),
        ];

        $idempotencyKey = sprintf(
            'magento-order-refunded-%d-%s',
            (int) $order->getStoreId(),
            (string) $order->getIncrementId()
        );

        $this->outbox->enqueue('orders/', $payload, $idempotencyKey);
    }
}
