<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Extension;

use SilverShop\ConversionTracking\Service\ConversionEventService;
use SilverShop\ConversionTracking\Service\PurchaseEventBuilder;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Extension;

/**
 * Purchase — the SERVER-side truth. Hooks core's {@see \SilverShop\Model\Order} `onPaid`, which fires exactly once
 * on the transition into Paid (the webhook truth for async iDEAL/Mollie — NOT `onPlaceOrder`, which is pre-payment
 * for offsite gateways). Enqueues the retryable {@see \SilverShop\ConversionTracking\Job\ConversionDispatchJob}
 * (Meta CAPI etc.) with the deterministic `event_id = purchase.{OrderID}` shared with the client Pixel/gtag.
 *
 * Consent is stamped from the current request: a synchronous (customer-present) paid transition carries the
 * consent cookie, so the server send can run; a request-less webhook transition stamps nothing, so the send is a
 * graceful default-deny no-op (v2 stores consent on the order to cover async payments).
 *
 * @extends Extension<\SilverShop\Model\Order>
 */
class PurchaseTrackingExtension extends Extension
{
    public function onPaid(): void
    {
        $order = $this->getOwner();
        if (!$order || !$order->exists()) {
            return;
        }

        $service = ConversionEventService::create();
        $event = PurchaseEventBuilder::create()->forOrder($order, $this->currentRequest());
        $service->dispatchServerSide($service->stampConsent($event));
    }

    private function currentRequest(): ?HTTPRequest
    {
        if (Director::is_cli()) {
            return null;
        }
        $controller = Controller::curr();

        return $controller ? $controller->getRequest() : null;
    }
}
