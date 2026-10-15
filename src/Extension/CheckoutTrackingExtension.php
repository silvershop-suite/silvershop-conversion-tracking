<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Extension;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Model\ConversionItem;
use SilverShop\ConversionTracking\Model\ConversionUser;
use SilverShop\ConversionTracking\Service\ConversionEventService;
use SilverShop\ConversionTracking\Service\ConversionItemFactory;
use SilverShop\Model\Buyable;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;

/**
 * InitiateCheckout / `begin_checkout` — minted once when the checkout form renders, via core's
 * {@see \SilverShop\Page\CheckoutPageController::OrderForm()} `updateOrderForm` hook. Items come from the current
 * cart ({@see \SilverShop\Model\Order::Items()}), value = {@see \SilverShop\Model\Order::Total()}. Emitted inline
 * (not deferred) because it renders in the same request. Guarded to fire at most once per order per request
 * (OrderForm may be built more than once).
 *
 * @extends Extension<\SilverShop\Page\CheckoutPageController>
 */
class CheckoutTrackingExtension extends Extension
{
    /**
     * @var array<int, bool> order id => already emitted this request
     */
    private array $emitted = [];

    public function updateOrderForm($form): void
    {
        $cart = $this->owner->Cart();
        if (!$cart || !$cart->exists()) {
            return;
        }
        $orderId = (int) $cart->ID;
        if (!empty($this->emitted[$orderId])) {
            return;
        }
        $this->emitted[$orderId] = true;

        $event = $this->buildEvent($cart, $this->owner->getRequest());
        if ($event instanceof ConversionEvent) {
            ConversionEventService::create()->track($event);
        }
    }

    /**
     * Build the normalised InitiateCheckout event from a cart (separated from the hook plumbing so it is directly
     * testable). Items come from the order lines; value = {@see \SilverShop\Model\Order::Total()}. Null when the
     * cart has no resolvable buyable lines.
     */
    public function buildEvent($cart, ?\SilverStripe\Control\HTTPRequest $request = null): ?ConversionEvent
    {
        $factory = ConversionItemFactory::create();
        $items = [];
        foreach ($cart->Items() as $orderItem) {
            $buyable = $orderItem->Buyable();
            if (!$buyable instanceof Buyable || !$buyable instanceof DataObject || !$buyable->exists()) {
                continue;
            }
            $items[] = $factory->forBuyable($buyable, (int) $orderItem->Quantity, (float) $orderItem->UnitPrice());
        }
        if ($items === []) {
            return null;
        }

        return new ConversionEvent(
            name: EventName::INITIATE_CHECKOUT,
            eventId: 'begincheckout.' . (int) $cart->ID . '.' . substr(md5(uniqid('', true)), 0, 12),
            items: $items,
            value: round((float) $cart->Total(), 2),
            currency: \SilverShop\Extension\ShopConfigExtension::get_site_currency(),
            contentType: ConversionEvent::CONTENT_TYPE_PRODUCT,
            user: (new ConversionUser())->withRequest($request),
            sourceUrl: $request ? \SilverStripe\Control\Director::absoluteURL($request->getURL()) : '',
            occurredAt: time(),
        );
    }
}
