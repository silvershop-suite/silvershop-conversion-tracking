<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Extension;

use SilverShop\ConversionTracking\Service\ConversionEventService;
use SilverShop\ConversionTracking\Service\PurchaseEventBuilder;
use SilverShop\Model\Order;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;

/**
 * Purchase — the CLIENT side, fired only on the confirmed-paid confirmation render, never on pending. Attaches to
 * the checkout + account controllers and, in onAfterInit, re-resolves the order from the route (the id param) and
 * emits the browser Purchase ONLY when the order {@see \SilverShop\Model\Order::IsPaid()} and the viewer is allowed
 * to see it. It uses the SAME {@see PurchaseEventBuilder} as the server seam, so the pixel and the queued CAPI send
 * carry the identical `event_id = purchase.{OrderID}` and Meta/Google de-duplicate.
 *
 * With silvershop/order-confirmation installed this is the unified `checkout/complete/{ID}` success render; the
 * `order` action covers core's guest/member order view as the fallback. It never runs the server path — that is
 * {@see PurchaseTrackingExtension}'s job on `onPaid`.
 *
 * @extends Extension<\SilverStripe\CMS\Controllers\ContentController>
 */
class PurchaseConfirmationExtension extends Extension
{
    use Configurable;

    /**
     * Route actions on which a paid order's client Purchase may fire. `complete` = silvershop/order-confirmation's
     * unified success render; `order` = core's guest/member order view (the fallback when order-confirmation is
     * absent).
     *
     * @var array<int, string>
     */
    private static array $confirmation_actions = ['complete', 'order'];

    public function onAfterInit(): void
    {
        $request = $this->getOwner()->getRequest();
        if (!$request instanceof HTTPRequest) {
            return;
        }

        $action = (string) $request->param('Action');
        if (!in_array($action, (array) static::config()->get('confirmation_actions'), true)) {
            return;
        }

        $id = (int) $request->param('ID');
        if ($id <= 0) {
            return;
        }

        $order = Order::get()->byID($id);
        if (!$order || !$order->exists() || !$order->IsPaid()) {
            return;
        }
        if (!$this->viewerMaySee($order, $request)) {
            return;
        }

        $service = ConversionEventService::create();
        $event = PurchaseEventBuilder::create()->forOrder($order, $request);
        $service->clientEmit($service->stampConsent($event), false);
    }

    /**
     * Only emit a paid order's data into the page for a viewer entitled to see that order: the framework's own
     * canView, or a matching guest token (order-confirmation's `?t=` / GuestToken). Prevents leaking order
     * value/items by guessing an id in the URL.
     */
    private function viewerMaySee(Order $order, HTTPRequest $request): bool
    {
        if ($order->canView()) {
            return true;
        }

        $token = (string) $request->getVar('t');
        if ($token !== '' && $order->hasField('GuestToken')) {
            $stored = (string) $order->getField('GuestToken');

            return $stored !== '' && hash_equals($stored, $token);
        }

        return false;
    }
}
