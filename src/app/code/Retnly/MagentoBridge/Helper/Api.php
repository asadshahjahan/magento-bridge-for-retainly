<?php

declare(strict_types=1);

namespace Retnly\MagentoBridge\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class Api extends AbstractHelper
{
    private const XML_PATH_ENABLED  = 'zeroslip/general/enabled';
    private const XML_PATH_API_KEY  = 'zeroslip/general/api_key';
    private const XML_PATH_STORE_ID = 'zeroslip/general/store_id';
    private const XML_PATH_BASE_URL = 'zeroslip/general/api_base_url';

    private Curl $curl;
    private EncryptorInterface $encryptor;
    private LoggerInterface $logger;

    public function __construct(
        Context $context,
        Curl $curl,
        EncryptorInterface $encryptor,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->curl      = $curl;
        $this->encryptor = $encryptor;
        $this->logger    = $logger;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getStoreId(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_STORE_ID,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * POST a JSON payload to a Retnly API endpoint.
     * Returns the HTTP status code, or null when the call did not happen
     * (integration disabled or transport-level exception). Errors are
     * logged and never re-thrown so callers can stay simple.
     *
     * When $idempotencyKey is supplied it is sent as the Idempotency-Key
     * header so the receiver can de-dupe retried events.
     */
    public function post(string $endpoint, array $payload, ?string $idempotencyKey = null): ?int
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $apiKey  = $this->encryptor->decrypt(
            (string) $this->scopeConfig->getValue(
                self::XML_PATH_API_KEY,
                ScopeInterface::SCOPE_STORE
            )
        );
        $baseUrl = rtrim(
            (string) $this->scopeConfig->getValue(
                self::XML_PATH_BASE_URL,
                ScopeInterface::SCOPE_STORE
            ),
            '/'
        ) . '/';

        $url  = $baseUrl . ltrim($endpoint, '/');
        $body = (string) json_encode($payload);

        try {
            $headers = [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ];
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $headers['Idempotency-Key'] = $idempotencyKey;
            }
            $this->curl->setHeaders($headers);
            $this->curl->setTimeout(10);
            $this->curl->post($url, $body);

            $status = $this->curl->getStatus();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning(sprintf(
                    '[Retnly] POST %s returned HTTP %d: %s',
                    $url,
                    $status,
                    $this->curl->getBody()
                ));
            }
            return $status;
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                '[Retnly] POST %s failed: %s',
                $url,
                $e->getMessage()
            ));
            return null;
        }
    }
}
