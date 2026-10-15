<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Service;

use SilverShop\CatalogData\CatalogProductDataFactory;
use SilverShop\ConversionTracking\Model\ConversionItem;
use SilverShop\Model\Buyable;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * Turns a SilverShop buyable (Product / Variation) into a normalised {@see ConversionItem}. The product identity
 * ({@see ConversionItem::$id}) is the shared feed id so the ad platforms' catalogue match lines up with the product
 * feed's `g:id` BY CONSTRUCTION:
 *
 *  - when silvershop/catalog-data is installed we read {@see CatalogProductData::feedId()} / price / currency /
 *    brand / gtin straight from its factory (the one source of truth — the config is therefore shared with the
 *    feed and SEO);
 *  - when it is absent we fall back to an id extractor that implements the IDENTICAL rule
 *    ({@see CatalogProductDataFactory::feedId()} — SKU-first, `product-{ID}` / `{parentFeedId}-{variationID}`
 *    fallback, ≤50 chars), reading a mirrored {@see self::$id_source} / {@see self::$id_max_length} config, so the
 *    two can never diverge.
 */
class ConversionItemFactory
{
    use Injectable;
    use Configurable;

    public const ID_SOURCE_INTERNAL = 'internal_item_id';

    public const ID_SOURCE_ID = 'id';

    /**
     * Fallback-only: which scheme {@see self::feedId()} uses when catalog-data is absent. Mirrors
     * {@see CatalogProductDataFactory::$id_source}; keep them aligned so ids match.
     */
    private static string $id_source = self::ID_SOURCE_INTERNAL;

    /**
     * Fallback-only: hard cap on an id's length. Mirrors {@see CatalogProductDataFactory::$id_max_length} (50).
     */
    private static int $id_max_length = 50;

    /**
     * The catalog-data factory class that, when present, is the shared source of truth. Overridable in config (to a
     * non-existent class) so the identical-rule fallback can be exercised deterministically in a test even where
     * silvershop/catalog-data is installed.
     */
    private static string $catalog_factory_class = CatalogProductDataFactory::class;

    /**
     * Build a line item for a buyable. $unitPrice (e.g. an {@see \SilverShop\Model\OrderItem::UnitPrice()}) wins
     * over the catalogue price when provided, so a cart/purchase line reflects what was actually charged.
     */
    public function forBuyable(Buyable $buyable, int $quantity = 1, ?float $unitPrice = null): ConversionItem
    {
        if ($this->catalogAvailable() && $buyable instanceof DataObject) {
            $data = CatalogProductDataFactory::create()->forBuyable($buyable);
            $price = $unitPrice ?? $data->salePrice() ?? $data->price() ?? $this->sellingPrice($buyable);

            return new ConversionItem(
                $data->feedId(),
                $data->itemGroupId(),
                $data->title(),
                round((float) $price, 2),
                max(1, $quantity),
                $data->brand(),
                $data->gtin(),
            );
        }

        return new ConversionItem(
            $this->feedId($buyable),
            $this->fallbackItemGroupId($buyable),
            $this->fallbackName($buyable),
            round((float) ($unitPrice ?? $this->sellingPrice($buyable)), 2),
            max(1, $quantity),
            null,
            null,
        );
    }

    /**
     * Fallback item name (no catalog-data): a variation reads its parent product's title (+ its own variant title)
     * the way catalog-data does, since a variation usually has no title of its own.
     */
    private function fallbackName(Buyable $buyable): string
    {
        if ($buyable instanceof Variation) {
            $parent = $buyable->Product();
            $base = ($parent instanceof Product && $parent->exists()) ? (string) $parent->getField('Title') : '';
            $variant = trim((string) $buyable->getTitle());

            return $variant !== '' ? trim($base . ' - ' . $variant) : $base;
        }

        return (string) $buyable->getTitle();
    }

    /**
     * The shared product id for a buyable. Delegates to catalog-data when present (so the config, and therefore the
     * id, is shared with the feed); otherwise the identical-rule fallback.
     */
    public function feedId(Buyable $buyable): string
    {
        if ($this->catalogAvailable() && $buyable instanceof DataObject) {
            return CatalogProductDataFactory::create()->feedId($buyable);
        }

        return $this->truncateId($this->composeId($buyable));
    }

    private function catalogAvailable(): bool
    {
        return class_exists((string) static::config()->get('catalog_factory_class'));
    }

    private function fallbackItemGroupId(Buyable $buyable): ?string
    {
        if (!$buyable instanceof Variation) {
            return null;
        }
        $parent = $buyable->Product();

        return ($parent instanceof Product && $parent->exists()) ? $this->feedId($parent) : null;
    }

    /**
     * The fallback id rule — a line-for-line mirror of {@see CatalogProductDataFactory::composeId()} so a shop
     * without catalog-data still gets ids that match the feed when catalog-data is later added.
     */
    private function composeId(Buyable $buyable): string
    {
        $useInternal = static::config()->get('id_source') !== self::ID_SOURCE_ID;

        if ($buyable instanceof Variation) {
            $own = trim((string) $buyable->getField('InternalItemID'));
            if ($useInternal && $own !== '') {
                return $own;
            }
            $parent = $buyable->Product();
            $base = ($parent instanceof Product && $parent->exists())
                ? $this->feedId($parent)
                : 'product-0';

            return $base . '-' . $buyable->ID;
        }

        if ($buyable instanceof DataObject) {
            $own = trim((string) $buyable->getField('InternalItemID'));
            if ($useInternal && $own !== '') {
                return $own;
            }

            return 'product-' . $buyable->ID;
        }

        return 'product-0';
    }

    private function truncateId(string $id): string
    {
        $max = (int) static::config()->get('id_max_length');

        return ($max > 0 && strlen($id) > $max) ? substr($id, 0, $max) : $id;
    }

    private function sellingPrice(Buyable $buyable): float
    {
        return $buyable->hasMethod('sellingPrice') ? (float) $buyable->sellingPrice() : 0.0;
    }
}
