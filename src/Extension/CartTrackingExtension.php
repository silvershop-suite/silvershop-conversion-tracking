<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Extension;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Model\ConversionUser;
use SilverShop\ConversionTracking\Service\ConversionEventService;
use SilverShop\ConversionTracking\Service\ConversionItemFactory;
use SilverShop\Model\Buyable;
use SilverShop\Model\OrderItem;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;

/**
 * AddToCart / `add_to_cart` — minted from core's {@see \SilverShop\Cart\ShoppingCart::add()} `afterAdd` hook on the
 * Order ($item, $buyable, $effectiveDelta, $filter). Because the add action redirects or returns AJAX, the browser
 * event cannot render inline, so it is emitted DEFERRED: stashed on a session queue and drained into the page on
 * the next render ({@see ConversionControllerExtension}). The id = the catalog-data feed id, qty = the increment
 * actually applied, unit price = {@see OrderItem::UnitPrice()}.
 *
 * @extends Extension<\SilverShop\Model\Order>
 */
class CartTrackingExtension extends Extension
{
    public function afterAdd(OrderItem $item, Buyable $buyable, int $quantity = 1, array $filter = []): void
    {
        if ($quantity <= 0) {
            return;
        }

        ConversionEventService::create()->track(
            $this->buildEvent($item, $buyable, $quantity, $this->currentRequest()),
            true
        );
    }

    /**
     * Build the normalised AddToCart event (separated from the hook plumbing so it is directly testable). qty = the
     * increment actually applied; unit price from {@see OrderItem::UnitPrice()}; id = the shared feed id.
     */
    public function buildEvent(
        OrderItem $item,
        Buyable $buyable,
        int $quantity,
        ?\SilverStripe\Control\HTTPRequest $request = null
    ): ConversionEvent {
        $unitPrice = (float) $item->UnitPrice();
        $conversionItem = ConversionItemFactory::create()->forBuyable($buyable, $quantity, $unitPrice);
        $buyableId = $buyable instanceof DataObject ? (int) $buyable->ID : 0;
        $buyableKey = str_replace('\\', '-', get_class($buyable));

        return new ConversionEvent(
            name: EventName::ADD_TO_CART,
            eventId: 'addtocart.' . $buyableKey . '.' . $buyableId . '.' . substr(md5(uniqid('', true)), 0, 12),
            items: [$conversionItem],
            value: round($unitPrice * $quantity, 2),
            currency: \SilverShop\Extension\ShopConfigExtension::get_site_currency(),
            contentType: ConversionEvent::CONTENT_TYPE_PRODUCT,
            user: (new ConversionUser())->withRequest($request),
            sourceUrl: $request ? \SilverStripe\Control\Director::absoluteURL($request->getURL()) : '',
            occurredAt: time(),
        );
    }

    private function currentRequest(): ?\SilverStripe\Control\HTTPRequest
    {
        $controller = Controller::curr();

        return $controller ? $controller->getRequest() : null;
    }
}
