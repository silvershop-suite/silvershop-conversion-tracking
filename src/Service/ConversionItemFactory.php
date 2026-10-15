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
 *  - when silvershop/catalog-data is installed we delegate the id to its cheap {@see CatalogProductDataFactory::feedId()}
 *    (the one source of truth — the config, and therefore the id, is shared with the feed and SEO);
 *  - when it is absent we fall back to an id extractor that implements the IDENTICAL rule (SKU-first,
 *    `product-{ID}` / `{parentFeedId}-{variationID}`, ≤50 chars), reading a mirrored {@see self::$id_source} /
 *    {@see self::$id_max_length} config, so the two can never diverge.
 *
 * Name / price / brand / gtin are read DIRECTLY off the buyable (light accessors), NOT from catalog-data's full VO:
 * building that VO per line (images, category paths, descriptions) is needless for a conversion event and makes a
 * multi-line checkout / purchase render slow, so this factory stays deliberately lightweight.
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
        // Deliberately LIGHT: only the shared feedId (delegated to catalog-data's cheap feedId() method) plus direct
        // buyable accessors. We do NOT build catalog-data's full VO here — that computes images, category paths and
        // descriptions per item, which a conversion event does not need and which makes a multi-line checkout /
        // purchase render very slow (image URL / file-ID resolution on every line).
        return new ConversionItem(
            $this->feedId($buyable),
            $this->itemGroupId($buyable),
            $this->name($buyable),
            round((float) ($unitPrice ?? $this->sellingPrice($buyable)), 2),
            max(1, $quantity),
            $this->brand($buyable),
            $this->gtin($buyable),
        );
    }

    /**
     * Item name: a variation reads its parent product's title (+ its own variant title), since a variation usually
     * has no title of its own.
     */
    private function name(Buyable $buyable): string
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
     * Brand title, read from silvershop/brands' Brand() relation when present (guarded). A variation inherits its
     * parent product's brand. Null when brands is absent or no brand is set.
     */
    private function brand(Buyable $buyable): ?string
    {
        $source = $buyable instanceof Variation ? $buyable->Product() : $buyable;
        if (!$source instanceof DataObject || !$source->hasMethod('Brand')) {
            return null;
        }
        $brand = $source->Brand();
        if (!$brand instanceof DataObject || !$brand->exists()) {
            return null;
        }
        $title = trim((string) $brand->getField('Title'));

        return $title !== '' ? $title : null;
    }

    /**
     * Resolved GTIN from silvershop/product-identity (which owns the variant→product fallback), guarded. Null when
     * that module is absent or no code is set.
     */
    private function gtin(Buyable $buyable): ?string
    {
        if (!$buyable instanceof DataObject || !$buyable->hasMethod('getResolvedGtin')) {
            return null;
        }
        $gtin = trim((string) $buyable->getResolvedGtin());

        return $gtin !== '' ? $gtin : null;
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

    /**
     * item_group_id for a variation = the parent product's (shared) feedId; null for a simple product.
     */
    private function itemGroupId(Buyable $buyable): ?string
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
