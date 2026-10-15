<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Extension;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Model\ConversionUser;
use SilverShop\ConversionTracking\Service\ConversionEventService;
use SilverShop\ConversionTracking\Service\ConversionItemFactory;
use SilverShop\Page\Product;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;

/**
 * ViewContent / `view_item` — minted on a product-page view. Mirrors the hook location and eligibility idiom of
 * silvershop/analytics' ProductViewTrackingExtension (GET, not AJAX, the bare product page — not Form /
 * AddProductForm sub-actions), but EMITS a browser event instead of recording a reporting row. The product is read
 * from {@see \SilverShop\Page\ProductController::data()}; `content_type` is `product_group` when the product has
 * variations, else `product`.
 *
 * @extends Extension<\SilverShop\Page\ProductController>
 */
class ProductTrackingExtension extends Extension
{
    use Configurable;

    private static bool $enabled = true;

    public function onAfterInit(): void
    {
        $request = $this->owner->getRequest();
        if (!$request instanceof HTTPRequest || !$this->shouldTrack($request)) {
            return;
        }

        $product = $this->owner->data();
        if (!$product instanceof Product || !$product->exists()) {
            return;
        }

        ConversionEventService::create()->track($this->buildEvent($product, $request));
    }

    /**
     * Build the normalised ViewContent event for a product (separated from the controller plumbing so it is
     * directly testable). `content_type` = product_group when the product has variations, else product.
     */
    public function buildEvent(Product $product, ?HTTPRequest $request = null): ConversionEvent
    {
        $item = ConversionItemFactory::create()->forBuyable($product, 1);

        return new ConversionEvent(
            name: EventName::VIEW_CONTENT,
            eventId: $this->eventId($product),
            items: [$item],
            value: $item->price,
            currency: $item->price > 0 ? $this->currency() : '',
            contentType: $this->hasVariations($product)
                ? ConversionEvent::CONTENT_TYPE_PRODUCT_GROUP
                : ConversionEvent::CONTENT_TYPE_PRODUCT,
            user: (new ConversionUser())->withRequest($request),
            sourceUrl: (string) $product->AbsoluteLink(),
            occurredAt: time(),
        );
    }

    public function shouldTrack(HTTPRequest $request): bool
    {
        if (!static::config()->get('enabled')) {
            return false;
        }
        if (!$request->isGET() || $request->isAjax()) {
            return false;
        }
        // Only the bare product page, not sub-actions such as Form / AddProductForm.
        if ($request->param('Action')) {
            return false;
        }

        return true;
    }

    private function hasVariations(Product $product): bool
    {
        return $product->hasMethod('Variations') && $product->Variations()->exists();
    }

    private function currency(): string
    {
        return \SilverShop\Extension\ShopConfigExtension::get_site_currency();
    }

    /**
     * A per-view event id (ViewContent is not deduplicated against a server event in v1, but a stable id keeps the
     * pixel/dataLayer consistent within the request).
     */
    private function eventId(Product $product): string
    {
        return 'view.' . $product->ID . '.' . substr(md5(uniqid('', true)), 0, 12);
    }
}
