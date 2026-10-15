<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Tests;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Extension\CartTrackingExtension;
use SilverShop\ConversionTracking\Extension\CheckoutTrackingExtension;
use SilverShop\ConversionTracking\Extension\ProductTrackingExtension;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Service\PurchaseEventBuilder;
use SilverShop\Model\Order;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * The four lifecycle seams each mint the right normalised event — ViewContent, AddToCart, InitiateCheckout and
 * Purchase — with ids = the shared feed id, and the deterministic `purchase.{OrderID}` dedup id. The event-building
 * is tested through each seam's extracted builder, independent of the controller/hook plumbing.
 */
class LifecycleEventsTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(Product::class, 'global_allow_purchase', true);
    }

    private function newProduct(string $sku = 'RJ-001'): Product
    {
        $product = Product::create([
            'Title' => 'Rain Jacket',
            'BasePrice' => 12.95,
            'InternalItemID' => $sku,
            'AllowPurchase' => true,
        ]);
        $product->write();
        // Publish so the order item's has_one Product (and thus Buyable()) resolves off the live stage.
        $product->publishRecursive();

        return $product;
    }

    private function orderWith(Product $product, int $qty = 2): Order
    {
        $order = Order::create();
        $order->write();
        $item = $product->createItem($qty);
        $item->OrderID = $order->ID;
        $item->write();
        $order->calculate();

        return $order;
    }

    public function testViewContentEvent(): void
    {
        $product = $this->newProduct();
        $event = (new ProductTrackingExtension())->buildEvent($product);

        $this->assertSame(EventName::VIEW_CONTENT, $event->name);
        $this->assertSame('RJ-001', $event->contentIds()[0]);
        $this->assertSame(12.95, $event->value);
        $this->assertSame(ConversionEvent::CONTENT_TYPE_PRODUCT, $event->contentType);
    }

    public function testViewContentIsProductGroupWhenProductHasVariations(): void
    {
        $product = $this->newProduct();
        $variation = Variation::create([
            'ProductID' => $product->ID,
            'Price' => 12.95,
            'InternalItemID' => 'RJ-001-RED',
        ]);
        $variation->write();

        $event = (new ProductTrackingExtension())->buildEvent($product);
        $this->assertSame(ConversionEvent::CONTENT_TYPE_PRODUCT_GROUP, $event->contentType);
    }

    public function testShouldTrackGating(): void
    {
        $ext = new ProductTrackingExtension();
        $get = new \SilverStripe\Control\HTTPRequest('GET', '/rain-jacket');
        $this->assertTrue($ext->shouldTrack($get));

        $post = new \SilverStripe\Control\HTTPRequest('POST', '/rain-jacket');
        $this->assertFalse($ext->shouldTrack($post), 'POST is not a view');

        $ajax = new \SilverStripe\Control\HTTPRequest('GET', '/rain-jacket');
        $ajax->addHeader('X-Requested-With', 'XMLHttpRequest');
        $this->assertFalse($ext->shouldTrack($ajax), 'AJAX is not a view');

        $sub = new \SilverStripe\Control\HTTPRequest('GET', '/rain-jacket/Form');
        $sub->setRouteParams(['Action' => 'Form']);
        $this->assertFalse($ext->shouldTrack($sub), 'A sub-action is not a bare product view');
    }

    public function testAddToCartEvent(): void
    {
        $product = $this->newProduct();
        $order = $this->orderWith($product, 2);
        $item = $order->Items()->first();

        $event = (new CartTrackingExtension())->buildEvent($item, $product, 2);

        $this->assertSame(EventName::ADD_TO_CART, $event->name);
        $this->assertSame('RJ-001', $event->contentIds()[0]);
        $this->assertSame(2, $event->items[0]->quantity);
        $this->assertSame(round((float) $item->UnitPrice() * 2, 2), $event->value);
    }

    public function testInitiateCheckoutEvent(): void
    {
        $product = $this->newProduct();
        $order = $this->orderWith($product, 2);

        $event = (new CheckoutTrackingExtension())->buildEvent($order);

        $this->assertInstanceOf(ConversionEvent::class, $event);
        $this->assertSame(EventName::INITIATE_CHECKOUT, $event->name);
        $this->assertSame('RJ-001', $event->contentIds()[0]);
        $this->assertSame(round((float) $order->Total(), 2), $event->value);
    }

    public function testPurchaseEventUsesDeterministicDedupId(): void
    {
        $product = $this->newProduct();
        $order = $this->orderWith($product, 1);

        $event = PurchaseEventBuilder::create()->forOrder($order);

        $this->assertSame(EventName::PURCHASE, $event->name);
        $this->assertSame('purchase.' . $order->ID, $event->eventId);
        $this->assertSame('purchase.' . $order->ID, PurchaseEventBuilder::eventId($order));
        $this->assertSame($order->ID, $event->orderID);
        $this->assertSame('RJ-001', $event->contentIds()[0]);
        $this->assertSame(round((float) $order->Total(), 2), $event->value);
    }
}
