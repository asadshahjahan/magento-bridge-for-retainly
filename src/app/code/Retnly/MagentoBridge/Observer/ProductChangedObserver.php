<?php

declare(strict_types=1);

namespace Retnly\MagentoBridge\Observer;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Retnly\MagentoBridge\Helper\Api;
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
    private Api $api;
    private StockRegistryInterface $stockRegistry;
    private CategoryRepositoryInterface $categoryRepository;

    /** @var array<string,string> category id => name, for one save only. */
    private array $categoryNames = [];

    public function __construct(
        EventOutbox $outbox,
        Api $api,
        StockRegistryInterface $stockRegistry,
        CategoryRepositoryInterface $categoryRepository
    ) {
        $this->outbox = $outbox;
        $this->api = $api;
        $this->stockRegistry = $stockRegistry;
        $this->categoryRepository = $categoryRepository;
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
                // "Not Visible Individually" (1) means this row is a configurable's
                // CHILD -- a variant, not a product. The receiver folds those into
                // their parent instead of listing them, and saving a child fires
                // this observer too, so it has to be able to tell.
                'visibility' => (int) $product->getVisibility(),
                // Categories as the merchant sees them, not Magento's `type_id`.
                'categories' => $this->categories($product),
                // Shopify's variant shape, assembled here because only PHP can
                // cheaply turn a child's `color: 12` into "Blue" and read its stock
                // without one API call per product.
                'variants'   => $this->variants($product),
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
     * The product's barcode, or '' when the merchant records none.
     *
     * Magento has NO barcode attribute. Merchants create a custom one and name it
     * after their trade, so the configured code wins and otherwise the common
     * spellings are tried in turn. Returning '' rather than null keeps the JSON
     * field a string, which is what the receiver and the product card expect.
     */
    private function barcode($product): string
    {
        $configured = $this->api->getBarcodeAttribute();
        $codes = $configured !== '' ? [$configured] : ['barcode', 'upc', 'ean', 'gtin'];

        foreach ($codes as $code) {
            try {
                $value = $product->getData($code);
            } catch (\Exception $e) {
                continue;
            }
            if ($value !== null && $value !== '' && !is_array($value)) {
                return (string) $value;
            }
        }
        return '';
    }

    /**
     * [quantity, policy] for one product, in Shopify's vocabulary.
     *
     * backorders 0 means "stop selling at zero" (Shopify's `deny`); anything else
     * keeps selling (`continue`). A product whose stock cannot be read returns
     * [null, ''] rather than [0, 'deny'] -- "unknown" and "none left" must not
     * look the same on a product card, which is exactly why the dashboard used to
     * hide stock for Magento rather than show a misleading zero.
     */
    private function stockFor($product): array
    {
        try {
            $item = $this->stockRegistry->getStockItem((int) $product->getId());
            $qty = $item->getQty();
            return [
                $qty === null ? null : (float) $qty,
                (int) $item->getBackorders() === 0 ? 'deny' : 'continue',
            ];
        } catch (\Exception $e) {
            return [null, ''];
        }
    }

    /**
     * [{"id": "3", "name": "makeup", "path": "Default Category / makeup"}].
     *
     * The path is worth the extra lookups: "what eye makeup do you have?" needs
     * the hierarchy, not just the leaf. Names are memoised per save because a
     * product in several categories otherwise re-loads the same ancestors for
     * each of them.
     */
    private function categories($product): array
    {
        $out = [];
        try {
            $ids = (array) $product->getCategoryIds();
        } catch (\Exception $e) {
            return [];
        }

        foreach ($ids as $id) {
            $id = (string) $id;
            try {
                $category = $this->categoryRepository->get((int) $id);
                $this->categoryNames[$id] = (string) $category->getName();
                $out[] = [
                    'id'   => $id,
                    'name' => (string) $category->getName(),
                    'path' => $this->categoryPath($category),
                ];
            } catch (\Exception $e) {
                // A category we cannot load is still a real grouping the merchant
                // uses; keeping the id understates it less than dropping it.
                $out[] = ['id' => $id, 'name' => '', 'path' => ''];
            }
        }
        return $out;
    }

    /**
     * "Default Category / makeup" from Magento's id path ("1/2/3").
     *
     * The root (id 1) is skipped: it is Magento's internal tree root and means
     * nothing to a shopper or to a merchant reading a product card.
     */
    private function categoryPath($category): string
    {
        $names = [];
        foreach (explode('/', (string) $category->getPath()) as $ancestorId) {
            if ($ancestorId === '' || (int) $ancestorId <= 1) {
                continue;
            }
            if (!isset($this->categoryNames[$ancestorId])) {
                try {
                    $this->categoryNames[$ancestorId] =
                        (string) $this->categoryRepository->get((int) $ancestorId)->getName();
                } catch (\Exception $e) {
                    $this->categoryNames[$ancestorId] = '';
                }
            }
            if ($this->categoryNames[$ancestorId] !== '') {
                $names[] = $this->categoryNames[$ancestorId];
            }
        }
        return implode(' / ', $names);
    }

    /**
     * The product's variants in Shopify's shape.
     *
     * A configurable's children ARE its variants. Everything else gets exactly one
     * synthetic variant built from itself, because Shopify guarantees at least one
     * and every consumer reads variants[0] without asking which platform a product
     * came from. An empty list here would push a platform branch into all of them.
     */
    private function variants($product): array
    {
        if ($product->getTypeId() === 'configurable') {
            try {
                $children = $product->getTypeInstance()->getUsedProducts($product);
            } catch (\Exception $e) {
                $children = [];
            }
            $out = [];
            foreach ($children as $child) {
                $out[] = $this->variantRow($child, $this->selectedOptions($product, $child));
            }
            if ($out) {
                return $out;
            }
        }
        return [$this->variantRow($product, [])];
    }

    /** One product -- parent, child or simple -- as a variant row. */
    private function variantRow($product, array $selectedOptions): array
    {
        [$qty, $policy] = $this->stockFor($product);
        return [
            'id'                 => (string) $product->getId(),
            'title'              => (string) $product->getName(),
            'sku'                => (string) $product->getSku(),
            'price'              => (string) ($product->getPrice() ?? '0.00'),
            'inventory_quantity' => $qty,
            'inventory_policy'   => $policy,
            'barcode'            => $this->barcode($product),
            'selected_options'   => $selectedOptions,
        ];
    }

    /**
     * [{"name": "Size", "value": "M"}] for one child of a configurable.
     *
     * A child stores its size as an option id, not a label, so the label is read
     * off the parent's configurable attribute. This is what lets "the medium one"
     * resolve to a specific SKU; without it the variants are an unlabelled list of
     * prices, which is enough to show a range and not enough to sell.
     */
    private function selectedOptions($parent, $child): array
    {
        $out = [];
        try {
            $attributes = $parent->getTypeInstance()->getConfigurableAttributesAsArray($parent);
        } catch (\Exception $e) {
            return [];
        }

        foreach ((array) $attributes as $attr) {
            $code = $attr['attribute_code'] ?? null;
            if (!$code) {
                continue;
            }
            $value = $child->getData($code);
            if ($value === null || $value === '') {
                continue;
            }
            $label = null;
            foreach ((array) ($attr['values'] ?? []) as $option) {
                if ((string) ($option['value_index'] ?? '') === (string) $value) {
                    $label = $option['store_label'] ?? null;
                    break;
                }
            }
            $out[] = [
                'name'  => (string) ($attr['store_label'] ?? $attr['frontend_label'] ?? $code),
                'value' => (string) ($label ?? $value),
            ];
        }
        return $out;
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
