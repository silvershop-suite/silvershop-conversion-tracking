<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking;

/**
 * The normalised, platform-neutral ecommerce event taxonomy the whole module speaks. Every lifecycle seam mints a
 * {@see \SilverShop\ConversionTracking\Model\ConversionEvent} carrying one of these, and each connector translates
 * it into that platform's own event name — Meta's PascalCase standard events ({@see self::metaName()}) and GA4's
 * snake_case ecommerce events ({@see self::ga4Name()}, which is also the backing value because the always-on
 * `dataLayer` emit is GA4-shaped).
 *
 * v1 uses PAGE_VIEW, VIEW_CONTENT, ADD_TO_CART, INITIATE_CHECKOUT and PURCHASE. ADD_PAYMENT_INFO is defined for the
 * v2 on-site multi-step-payment seam and is not emitted by any v1 hook.
 */
enum EventName: string
{
    case PAGE_VIEW = 'page_view';
    case VIEW_CONTENT = 'view_item';
    case ADD_TO_CART = 'add_to_cart';
    case INITIATE_CHECKOUT = 'begin_checkout';
    case ADD_PAYMENT_INFO = 'add_payment_info';
    case PURCHASE = 'purchase';

    /**
     * The GA4 ecommerce event name (also this enum's backing value), e.g. `view_item`, used for the `dataLayer`
     * push and the Google connector's `gtag` call.
     */
    public function ga4Name(): string
    {
        return $this->value;
    }

    /**
     * The Meta Pixel / Conversions API standard-event name, e.g. `ViewContent`.
     */
    public function metaName(): string
    {
        return match ($this) {
            self::PAGE_VIEW => 'PageView',
            self::VIEW_CONTENT => 'ViewContent',
            self::ADD_TO_CART => 'AddToCart',
            self::INITIATE_CHECKOUT => 'InitiateCheckout',
            self::ADD_PAYMENT_INFO => 'AddPaymentInfo',
            self::PURCHASE => 'Purchase',
        };
    }

    /**
     * Whether this event carries ecommerce items/value (everything except the bare PAGE_VIEW base hit).
     */
    public function isEcommerce(): bool
    {
        return $this !== self::PAGE_VIEW;
    }
}
