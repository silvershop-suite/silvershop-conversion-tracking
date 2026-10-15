<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Service;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Model\ConversionUser;
use SilverShop\Extension\ShopConfigExtension;
use SilverShop\Model\Buyable;
use SilverShop\Model\Order;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * Builds the single canonical Purchase {@see ConversionEvent} for an order — used by BOTH the server-side
 * {@see \SilverShop\ConversionTracking\Extension\PurchaseTrackingExtension} (Order `onPaid`) and the client-side
 * {@see \SilverShop\ConversionTracking\Extension\PurchaseConfirmationExtension} (confirmation render). Because both
 * build from here, the browser Pixel/gtag and the queued CAPI/server send share the DETERMINISTIC
 * `event_id = "purchase.{OrderID}"` (and `transaction_id = {OrderID}`), so Meta and Google de-duplicate the pair —
 * the single most important correctness detail in the module. A webhook re-send of `onPaid` reuses the same id too.
 *
 * The matching data ({@see ConversionUser}) is read from the order (email, billing name/phone/address) defensively
 * via getField so it is safe whatever the installed Address/Member shape; it is raw here and hashed/forwarded only
 * by the connector, server-side.
 */
class PurchaseEventBuilder
{
    use Injectable;

    public function forOrder(Order $order, ?HTTPRequest $request = null): ConversionEvent
    {
        $factory = ConversionItemFactory::create();
        $items = [];
        foreach ($order->Items() as $orderItem) {
            $buyable = $orderItem->Buyable();
            if (!$buyable instanceof Buyable || !$buyable instanceof DataObject || !$buyable->exists()) {
                continue;
            }
            $items[] = $factory->forBuyable($buyable, (int) $orderItem->Quantity, (float) $orderItem->UnitPrice());
        }

        $user = $this->userFor($order)->withRequest($request);

        return new ConversionEvent(
            name: EventName::PURCHASE,
            eventId: self::eventId($order),
            items: $items,
            value: round((float) $order->Total(), 2),
            currency: ShopConfigExtension::get_site_currency(),
            contentType: ConversionEvent::CONTENT_TYPE_PRODUCT,
            user: $user,
            sourceUrl: $request instanceof HTTPRequest
                ? \SilverStripe\Control\Director::absoluteURL($request->getURL())
                : '',
            occurredAt: time(),
            orderID: (int) $order->ID,
        );
    }

    /**
     * The deterministic, shared dedup id for an order's purchase.
     */
    public static function eventId(Order $order): string
    {
        return 'purchase.' . (int) $order->ID;
    }

    private function userFor(Order $order): ConversionUser
    {
        $member = $order->Member();
        $billing = $order->BillingAddress();

        $email = $order->hasMethod('getLatestEmail') ? (string) $order->getLatestEmail() : '';
        if ($email === '' && $member instanceof DataObject && $member->exists()) {
            $email = (string) $member->getField('Email');
        }

        return new ConversionUser(
            email: $this->clean($email),
            phone: $this->field($billing, 'Phone'),
            firstName: $this->field($member, 'FirstName') ?? $this->field($billing, 'FirstName'),
            lastName: $this->field($member, 'Surname') ?? $this->field($billing, 'Surname'),
            city: $this->field($billing, 'City'),
            state: $this->field($billing, 'State'),
            postcode: $this->field($billing, 'PostalCode'),
            country: $this->field($billing, 'Country'),
            externalId: ($member instanceof DataObject && $member->exists()) ? (string) $member->ID : null,
        );
    }

    private function field(?DataObject $object, string $name): ?string
    {
        if (!$object instanceof DataObject || !$object->exists()) {
            return null;
        }

        return $this->clean((string) $object->getField($name));
    }

    private function clean(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
