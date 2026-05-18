<?php

declare(strict_types=1);

namespace Retnly\MagentoBridge\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Retnly\MagentoBridge\Helper\Api;
use Retnly\MagentoBridge\Model\EventOutbox;

class CustomerRegisteredObserver implements ObserverInterface
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

        /** @var \Magento\Customer\Model\Customer $customer */
        $customer = $observer->getEvent()->getCustomer();

        $payload = [
            'store_id'           => $this->api->getStoreId(),
            'customer_id'        => (int) $customer->getId(),
            'email'              => $customer->getEmail(),
            'firstname'          => $customer->getFirstname(),
            'lastname'           => $customer->getLastname(),
            'group_id'           => (int) $customer->getGroupId(),
            'website_id'         => (int) $customer->getWebsiteId(),
            'store_id_customer'  => (int) $customer->getStoreId(),
            'created_at'         => $customer->getCreatedAt(),
            'updated_at'         => $customer->getUpdatedAt(),
        ];

        $idempotencyKey = sprintf('magento-customer-%d', (int) $customer->getId());

        $this->outbox->enqueue('customers/', $payload, $idempotencyKey);
    }
}
