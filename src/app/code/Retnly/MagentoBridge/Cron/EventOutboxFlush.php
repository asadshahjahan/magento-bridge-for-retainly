<?php

declare(strict_types=1);

namespace Retnly\MagentoBridge\Cron;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use Retnly\MagentoBridge\Helper\Api;

/**
 * Drains the retnly_event_outbox table to Retnly.
 *
 * Picks the oldest unsent rows in batches, POSTs each with its stored
 * idempotency key, and either marks sent_at on 2xx or records the failure
 * and leaves the row pending for retry on the next minute's run.
 */
class EventOutboxFlush
{
    private const TABLE      = 'retnly_event_outbox';
    private const BATCH_SIZE = 50;

    private Api $api;
    private ResourceConnection $resourceConnection;
    private LoggerInterface $logger;

    public function __construct(
        Api $api,
        ResourceConnection $resourceConnection,
        LoggerInterface $logger
    ) {
        $this->api                = $api;
        $this->resourceConnection = $resourceConnection;
        $this->logger             = $logger;
    }

    public function execute(): void
    {
        if (!$this->api->isEnabled()) {
            return;
        }

        $conn  = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $select = $conn->select()
            ->from($table)
            ->where('sent_at IS NULL')
            ->order('entity_id ASC')
            ->limit(self::BATCH_SIZE);

        $rows = $conn->fetchAll($select);

        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (!is_array($payload)) {
                $payload = [];
            }
            $idempotencyKey = $row['idempotency_key'] !== null && $row['idempotency_key'] !== ''
                ? (string) $row['idempotency_key']
                : null;

            $status = $this->api->post(
                (string) $row['event_type'],
                $payload,
                $idempotencyKey
            );

            $isSuccess = $status !== null && $status >= 200 && $status < 300;

            $update = [
                'attempts'    => (int) $row['attempts'] + 1,
                'last_status' => $status,
            ];
            if ($isSuccess) {
                $update['sent_at']    = date('Y-m-d H:i:s');
                $update['last_error'] = null;
            } else {
                $update['last_error'] = sprintf(
                    'POST returned %s',
                    $status === null ? 'no response (transport failure)' : (string) $status
                );
            }

            try {
                $conn->update(
                    $table,
                    $update,
                    ['entity_id = ?' => (int) $row['entity_id']]
                );
            } catch (\Exception $e) {
                $this->logger->warning(sprintf(
                    '[Retnly] Failed to update outbox row %d: %s',
                    (int) $row['entity_id'],
                    $e->getMessage()
                ));
            }
        }
    }
}
