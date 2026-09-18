<?php

declare(strict_types=1);

namespace Retnly\MagentoBridge\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Retnly\MagentoBridge\Model\EventOutbox;

/**
 * Pushes a product to Retnly the moment it is saved or deleted.
 *
 * WHY
 *   Retnly's catalogue copy (`MagentoProduct`) was refreshed once a day by
 *   `sync_all_magento_products_task`, so a price change could be up to 24 hours
 *   stale. Iris quotes prices from that table and the brand-asset retriever
 *   picks photos from it -- neither reads the live Magento API -- so the table
 *   being a day behind means the merchant's customers are quoted yesterday's
 *   price. Shopify closed this window with a product webhook; this is the
 *   Magento equivalent.
 *
 * WHY THE OUTBOX
 *   Same reasoning as the order observers: enqueue inside the merchant's own
 *   transaction and let the per-minute cron deliver. A slow or unreachable
 *   Retnly must never make saving a product in the admin hang or fail.
 *
 * DELETES
 *   `catalog_product_delete_after` carries the product, so the id is still
 *   readable at that point. It is sent with action=delete rather than as a
 *   bare id so the receiver needs only one endpoint and one payload shape.
 */
class ProductChangedObserver implements ObserverInterface
{
    private const ENDPOINT = 'products/webhook/';

    private EventOutbox $outbox;

    public function __construct(EventOutbox $outbox)
    {
        $this->outbox = $outbox;
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getProduct();
        if (!$product || !$product->getId()) {
            return;
        }

        $isDelete = $observer->getEvent()->getName() === 'catalog_product_delete_after';

        $payload = [
            'action' => $isDelete ? 'delete' : 'save',
            'id'     => (int) $product->getId(),
            'sku'    => (string) $product->getSku(),
        ];

        if (!$isDelete) {
            $payload += [
                'name'       => (string) $product->getName(),
                'status'     => (int) $product->getStatus(),
                'type_id'    => (string) $product->getTypeId(),
                'price'      => $product->getPrice() !== null ? (float) $product->getPrice() : null,
                'created_at' => $product->getCreatedAt(),
                'updated_at' => $product->getUpdatedAt(),
                // Storefront path segment, so Iris can deep-link a product card.
                'url_key'    => (string) $product->getUrlKey(),
                // Plain text: the backend parses this to learn the merchant's own
                // attribute vocabulary, so HTML tags would become false facets.
                'description' => $this->plainText((string) $product->getDescription()),
                // Shopify-shaped dimensions, for variant resolution in chat.
                'options'    => $this->configurableOptions($product),
                // Same shape the REST sync returns, so the receiver reuses
                // `_build_product_images` rather than growing a second parser.
                'media_gallery_entries' => $this->galleryEntries($product),
            ];
        }

        // Keyed on id + action + updated_at so a genuine later edit is its own
        // event, while a double-save of the same state de-dupes.
        $this->outbox->enqueue(
            self::ENDPOINT,
            $payload,
            sprintf(
                'product-%d-%s-%s',
                (int) $product->getId(),
                $isDelete ? 'delete' : 'save',
                (string) ($product->getUpdatedAt() ?? '')
            )
        );
    }

    /**
     * Magento descriptions are HTML. The backend parses this text to learn the
     * merchant's own attribute vocabulary ("Fabric: Cotton Lawn."), so tags
     * left in would become false facets. Block-level tags become newlines
     * first, otherwise two sentences in separate <p>s run together into one.
     */
    private function plainText(string $html): string
    {
        $text = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $html));
        return trim(preg_replace('/\n{3,}/', "\n\n", html_entity_decode($text)));
    }

    /**
     * Shopify-shaped option list: [{"name": "Size", "values": ["S", "M"]}].
     *
     * The key is `name`, not `label`, because that is exactly what the Shopify
     * sync writes — the backend's card builder reads `options[].name` and would
     * silently show no dimensions for any other spelling.
     *
     * Magento models a configurable product as a parent plus child products
     * rather than as variants, so the dimensions live on the parent's
     * configurable attributes and have to be assembled rather than copied.
     * A simple product has none, and an empty list is the correct answer.
     *
     * Wrapped like galleryEntries: a product loaded without its type instance
     * must not make saving it in the admin fail.
     */
    private function configurableOptions($product): array
    {
        if ($product->getTypeId() !== 'configurable') {
            return [];
        }
        $out = [];
        try {
            $attributes = $product->getTypeInstance()->getConfigurableAttributesAsArray($product);
            foreach ((array) $attributes as $attr) {
                $values = [];
                foreach ((array) ($attr['values'] ?? []) as $v) {
                    if (!empty($v['store_label'])) {
                        $values[] = (string) $v['store_label'];
                    }
                }
                if ($values) {
                    $out[] = [
                        'name'   => (string) ($attr['store_label'] ?? $attr['frontend_label'] ?? ''),
                        'values' => $values,
                    ];
                }
            }
        } catch (\Exception $e) {
            return [];
        }
        return $out;
    }

    /**
     * Gallery in the REST shape. Returns [] when the product was loaded without
     * its media -- a price-only save often is -- and the receiver deliberately
     * leaves the stored images alone when this is empty, rather than blanking
     * the catalogue one photo at a time.
     */
    private function galleryEntries($product): array
    {
        $entries = [];
        try {
            foreach ((array) $product->getMediaGalleryEntries() as $entry) {
                $entries[] = [
                    'media_type' => (string) $entry->getMediaType(),
                    'file'       => (string) $entry->getFile(),
                    'label'      => (string) $entry->getLabel(),
                    'position'   => (int) $entry->getPosition(),
                ];
            }
        } catch (\Exception $e) {
            return [];
        }
        return $entries;
    }
}
