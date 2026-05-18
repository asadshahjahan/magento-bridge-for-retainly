<?php

declare(strict_types=1);

namespace Retnly\MagentoBridge\Model;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Transactional outbox for outbound Retnly events.
 *
 * Observers call enqueue() to record an event in the local DB inside the
 * same transaction as the business event (order placement, customer
 * registration). The per-minute cron flushes pending rows to Retnly.
 *
 * Insert failures are logged and swallowed so a problem with our outbox
 * table never rolls back a merchant's order or registration.
 */
class EventOutbox
{
    private const TABLE = 'retnly_event_outbox';

    private ResourceConnection $resourceConnection;
    private LoggerInterface $logger;

    public function __construct(
        ResourceConnection $resourceConnection,
        LoggerInterface $logger
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->logger             = $logger;
    }

    public function enqueue(string $eventType, array $payload, ?string $idempotencyKey = null): void
    {
        try {
            $this->resourceConnection->getConnection()->insert(
                $this->resourceConnection->getTableName(self::TABLE),
                [
                    'event_type'      => $eventType,
                    'payload'         => (string) json_encode($payload),
                    'idempotency_key' => $idempotencyKey,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                '[Retnly] Failed to enqueue %s event: %s',
                $eventType,
                $e->getMessage()
            ));
        }
    }
}
