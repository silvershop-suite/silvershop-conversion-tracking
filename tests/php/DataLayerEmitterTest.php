<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Tests;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Model\ConversionItem;
use SilverShop\ConversionTracking\Model\ConversionUser;
use SilverShop\ConversionTracking\Service\DataLayerEmitter;
use SilverStripe\Dev\SapphireTest;

/**
 * The always-on, GA4-shaped `dataLayer` push: event name, ecommerce object, items mapping, transaction_id on
 * purchase, the ecommerce-reset, and the guarantee it carries no PII.
 */
class DataLayerEmitterTest extends SapphireTest
{
    protected $usesDatabase = false;

    private function emitter(): DataLayerEmitter
    {
        return DataLayerEmitter::create();
    }

    private function event(EventName $name, int $orderID = 0): ConversionEvent
    {
        return new ConversionEvent(
            name: $name,
            eventId: 'e1',
            items: [new ConversionItem('RJ-001', 'RJ', 'Rain Jacket', 12.95, 2, 'Acme')],
            value: 25.90,
            currency: 'EUR',
            user: new ConversionUser(email: 'secret@example.com', phone: '+31600000000'),
            orderID: $orderID,
        );
    }

    public function testViewItemPayloadShape(): void
    {
        $payload = $this->emitter()->payload($this->event(EventName::VIEW_CONTENT));

        $this->assertSame('view_item', $payload['event']);
        $this->assertSame('EUR', $payload['ecommerce']['currency']);
        $this->assertSame(25.90, $payload['ecommerce']['value']);
        $this->assertArrayNotHasKey('transaction_id', $payload['ecommerce']);

        $item = $payload['ecommerce']['items'][0];
        $this->assertSame('RJ-001', $item['item_id']);
        $this->assertSame('Rain Jacket', $item['item_name']);
        $this->assertSame(12.95, $item['price']);
        $this->assertSame(2, $item['quantity']);
        $this->assertSame('RJ', $item['item_group_id']);
        $this->assertSame('Acme', $item['item_brand']);
    }

    public function testEcommerceEventNamesPerType(): void
    {
        $this->assertSame('add_to_cart', $this->eventNameFor(EventName::ADD_TO_CART));
        $this->assertSame('begin_checkout', $this->eventNameFor(EventName::INITIATE_CHECKOUT));
        $this->assertSame('purchase', $this->eventNameFor(EventName::PURCHASE, 42));
    }

    private function eventNameFor(EventName $name, int $orderID = 0): string
    {
        return (string) $this->emitter()->payload($this->event($name, $orderID))['event'];
    }

    public function testPurchaseCarriesTransactionId(): void
    {
        $payload = $this->emitter()->payload($this->event(EventName::PURCHASE, 42));
        $this->assertSame('42', $payload['ecommerce']['transaction_id']);
    }

    public function testPageViewHasNoEcommerceBlock(): void
    {
        $payload = $this->emitter()->payload($this->event(EventName::PAGE_VIEW));
        $this->assertSame('page_view', $payload['event']);
        $this->assertArrayNotHasKey('ecommerce', $payload);
    }

    public function testPushScriptResetsEcommerceAndCarriesNoPii(): void
    {
        $js = $this->emitter()->pushScript($this->event(EventName::PURCHASE, 42));

        $this->assertStringContainsString('window.dataLayer.push({ ecommerce: null });', $js);
        $this->assertStringContainsString('"event":"purchase"', $js);
        // No matching PII ever reaches the dataLayer.
        $this->assertStringNotContainsString('secret@example.com', $js);
        $this->assertStringNotContainsString('+31600000000', $js);
    }
}
