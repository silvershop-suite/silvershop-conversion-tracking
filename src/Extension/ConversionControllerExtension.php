<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Extension;

use SilverShop\ConversionTracking\Service\ConversionEventService;
use SilverStripe\Core\Extension;

/**
 * The always-on page seam (mirrors how silvershop/cookieconsent injects — a ContentController extension's
 * onAfterInit + Requirements, never a theme edit). On every front-end page it:
 *
 *  - emits each active connector's once-per-page base tag (Pixel init / gtag loader), consent-gated;
 *  - drains the deferred client-event queue (e.g. an AddToCart stashed on the previous, redirecting request) into
 *    the `dataLayer` + connector tags.
 *
 * @extends Extension<\SilverStripe\CMS\Controllers\ContentController>
 */
class ConversionControllerExtension extends Extension
{
    public function onAfterInit(): void
    {
        $service = ConversionEventService::create();
        $service->emitBaseTags();
        $service->drainQueue();
    }
}
