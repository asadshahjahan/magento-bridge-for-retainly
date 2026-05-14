<?php

declare(strict_types=1);

namespace ZeroSlip\MagentoBridge\Helper;

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
     * POST a JSON payload to a ZeroSlip API endpoint.
     * Errors are logged to zeroslip.log and never re-thrown.
     */
    public function post(string $endpoint, array $payload): void
    {
        if (!$this->isEnabled()) {
            return;
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
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ]);
            $this->curl->setTimeout(10);
            $this->curl->post($url, $body);

            $status = $this->curl->getStatus();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning(sprintf(
                    '[ZeroSlip] POST %s returned HTTP %d: %s',
                    $url,
                    $status,
                    $this->curl->getBody()
                ));
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                '[ZeroSlip] POST %s failed: %s',
                $url,
                $e->getMessage()
            ));
        }
    }
}
