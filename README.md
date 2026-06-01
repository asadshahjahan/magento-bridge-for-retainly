# Retnly Magento Bridge

Official Magento 2 integration module for the (Retnly) CRM and marketing automation platform.

## What It Does

- Syncs new orders to Retnly automatically within ~1 minute of placement
- Syncs customer registrations automatically
- Tracks abandoned carts and syncs hourly (no duplicate sends)
- Fires automation trigger events for order paid, fulfilled, cancelled, and refunded
- Uses a transactional outbox pattern — checkout speed is never affected by backend availability

## Automation Triggers

Once connected, merchants can build automations in the ZeroSlip dashboard using these Magento events:

| Event | When It Fires |
|---|---|
| `magento_order_placed` | New order placed |
| `magento_order_paid` | Invoice created (payment confirmed) |
| `magento_order_fulfilled` | Order shipped and complete |
| `magento_order_cancelled` | Order cancelled |
| `magento_order_refunded` | Credit memo issued |
| `magento_cart_abandoned` | Cart idle for 1+ hour (Email & Push only) |

## Requirements

- Magento 2.4+
- PHP 8.1+
- Retnly merchant account 

## Installation

```bash
composer require retnly/magento-sync
bin/magento module:enable Retnly_MagentoBridge
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

## Full Setup Guide

See [integration.txt](./integration.txt) for the complete step-by-step merchant setup guide.

## Architecture

This module uses a **transactional outbox pattern**:

1. When an order is placed, the event is written to a local `retnly_event_outbox` table inside the same database transaction as the order — no HTTP calls at checkout
2. A cron job runs every minute, picks up pending events, and POSTs them to ZeroSlip with idempotency keys
3. Failed deliveries are retried automatically on the next cron run

This means checkout is instant and unaffected even if ZeroSlip's backend is temporarily unavailable.

## Support

For setup help, contact [support@zeroslip.co](mailto:support@zeroslip.co)