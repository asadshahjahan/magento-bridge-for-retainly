<?php

declare(strict_types=1);

namespace ZeroSlip\MagentoBridge\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use ZeroSlip\MagentoBridge\Helper\Api;

class CustomerRegisteredObserver implements ObserverInterface
{
    private Api $api;

    public function __construct(Api $api)
    {
        $this->api = $api;
    }

    public function execute(Observer $observer): void
    {
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

        $this->api->post('customers/', $payload);
    }
}
