<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Tests;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Model\ConversionItem;
use SilverShop\ConversionTracking\Model\ConversionUser;
use SilverStripe\Dev\SapphireTest;

/**
 * The normalised event model: the taxonomy enum's Meta/GA4 names, the value objects, and the array round-trip the
 * dispatch job relies on.
 */
class EventModelTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testEventNameMapsToMetaAndGa4Names(): void
    {
        $this->assertSame('ViewContent', EventName::VIEW_CONTENT->metaName());
        $this->assertSame('view_item', EventName::VIEW_CONTENT->ga4Name());
        $this->assertSame('AddToCart', EventName::ADD_TO_CART->metaName());
        $this->assertSame('add_to_cart', EventName::ADD_TO_CART->ga4Name());
        $this->assertSame('InitiateCheckout', EventName::INITIATE_CHECKOUT->metaName());
        $this->assertSame('begin_checkout', EventName::INITIATE_CHECKOUT->ga4Name());
        $this->assertSame('Purchase', EventName::PURCHASE->metaName());
        $this->assertSame('purchase', EventName::PURCHASE->ga4Name());
        $this->assertSame('PageView', EventName::PAGE_VIEW->metaName());
    }

    public function testPageViewIsNotEcommerce(): void
    {
        $this->assertFalse(EventName::PAGE_VIEW->isEcommerce());
        $this->assertTrue(EventName::VIEW_CONTENT->isEcommerce());
        $this->assertTrue(EventName::PURCHASE->isEcommerce());
    }

    public function testConversionItemRoundTrip(): void
    {
        $item = new ConversionItem('RJ-001', 'RJ', 'Rain Jacket', 12.95, 2, 'Acme', '4006381333931');
        $restored = ConversionItem::fromArray($item->toArray());

        $this->assertSame('RJ-001', $restored->id);
        $this->assertSame('RJ', $restored->itemGroupId);
        $this->assertSame('Rain Jacket', $restored->name);
        $this->assertSame(12.95, $restored->price);
        $this->assertSame(2, $restored->quantity);
        $this->assertSame('Acme', $restored->brand);
        $this->assertSame('4006381333931', $restored->gtin);
    }

    public function testConversionUserCapturesFbpFbcIpAndUserAgentFromRequest(): void
    {
        $request = new \SilverStripe\Control\HTTPRequest('GET', '/product', ['fbclid' => 'abc123']);
        $request->setIP('203.0.113.9');
        $request->addHeader('User-Agent', 'PHPUnit');
        // _fbp cookie present, _fbc absent → synthesised from fbclid.
        $_COOKIE['_fbp'] = 'fb.1.100.xyz';

        $user = (new ConversionUser(email: 'a@example.com'))->withRequest($request);

        $this->assertSame('fb.1.100.xyz', $user->fbp);
        $this->assertStringStartsWith('fb.1.', (string) $user->fbc);
        $this->assertStringEndsWith('.abc123', (string) $user->fbc);
        $this->assertSame('203.0.113.9', $user->clientIp);
        $this->assertSame('PHPUnit', $user->userAgent);

        unset($_COOKIE['_fbp']);
    }

    public function testConversionUserIsEmptyWhenNothingToMatch(): void
    {
        $this->assertTrue((new ConversionUser())->isEmpty());
        $this->assertFalse((new ConversionUser(email: 'a@example.com'))->isEmpty());
    }

    public function testConversionEventRoundTripPreservesEverything(): void
    {
        $event = new ConversionEvent(
            name: EventName::PURCHASE,
            eventId: 'purchase.42',
            items: [new ConversionItem('RJ-001', null, 'Rain Jacket', 12.95, 1)],
            value: 12.95,
            currency: 'EUR',
            contentType: ConversionEvent::CONTENT_TYPE_PRODUCT,
            user: new ConversionUser(email: 'buyer@example.com'),
            sourceUrl: 'https://shop.test/checkout/complete/42',
            occurredAt: 1_700_000_000,
            orderID: 42,
            consentedCategories: ['marketing', 'statistics'],
        );

        $restored = ConversionEvent::fromArray($event->toArray());

        $this->assertSame(EventName::PURCHASE, $restored->name);
        $this->assertSame('purchase.42', $restored->eventId);
        $this->assertSame(['RJ-001'], $restored->contentIds());
        $this->assertSame(12.95, $restored->value);
        $this->assertSame('EUR', $restored->currency);
        $this->assertSame(42, $restored->orderID);
        $this->assertSame('buyer@example.com', $restored->user->email);
        $this->assertTrue($restored->hasConsent('marketing'));
        $this->assertTrue($restored->hasConsent('statistics'));
        $this->assertFalse($restored->hasConsent('preferences'));
    }

    public function testPurchaseEventIdIsDeterministicPerOrder(): void
    {
        // The dedup contract: the id is stable for a given order, so the pixel + server send + any webhook re-send
        // all collide on it. (The full builder is exercised in LifecycleEventsTest.)
        $a = new ConversionEvent(EventName::PURCHASE, 'purchase.42', orderID: 42);
        $b = new ConversionEvent(EventName::PURCHASE, 'purchase.42', orderID: 42);
        $this->assertSame($a->eventId, $b->eventId);
    }
}
