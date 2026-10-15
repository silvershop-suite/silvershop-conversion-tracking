<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Service;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Model\ConversionItem;
use SilverStripe\Core\Injector\Injectable;

/**
 * Builds the always-on, GA4-shaped `dataLayer` push for an event (the neutral event bus a GTM-managed shop wires
 * its own tags against — §0 of the plan). The shape is standard GA4 ecommerce: an `event` name, a nested
 * `ecommerce` object with `currency` / `value` / `items[]` (and `transaction_id` on purchase), preceded by an
 * `ecommerce: null` reset so stale items never leak into the next push (the WeltPixel idiom).
 *
 * The push carries NO personally-identifying data — matching PII only ever travels the server path / the strictly
 * gated Enhanced-Conversions client call, never the `dataLayer`.
 */
class DataLayerEmitter
{
    use Injectable;

    /**
     * The inline JS that pushes this event onto `window.dataLayer`. Wrapped for consent by
     * {@see ConsentGate} before it is emitted.
     */
    public function pushScript(ConversionEvent $event): string
    {
        $payload = json_encode(
            $this->payload($event),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return 'window.dataLayer = window.dataLayer || [];'
            . 'window.dataLayer.push({ ecommerce: null });'
            . 'window.dataLayer.push(' . $payload . ');';
    }

    /**
     * The `dataLayer` object for an event (exposed for tests + the headless surface).
     *
     * @return array<string, mixed>
     */
    public function payload(ConversionEvent $event): array
    {
        $data = ['event' => $event->name->ga4Name()];

        if (!$event->name->isEcommerce()) {
            return $data;
        }

        $ecommerce = [
            'currency' => $event->currency,
            'value' => round($event->value, 2),
        ];

        if ($event->name === EventName::PURCHASE && $event->orderID > 0) {
            $ecommerce['transaction_id'] = (string) $event->orderID;
        }

        $ecommerce['items'] = array_map(
            fn (ConversionItem $item): array => $this->item($item),
            $event->items
        );

        $data['ecommerce'] = $ecommerce;

        return $data;
    }

    /**
     * One GA4 `items[]` entry.
     *
     * @return array<string, mixed>
     */
    private function item(ConversionItem $item): array
    {
        $entry = [
            'item_id' => $item->id,
            'item_name' => $item->name,
            'price' => round($item->price, 2),
            'quantity' => $item->quantity,
        ];
        if ($item->itemGroupId !== null && $item->itemGroupId !== '') {
            $entry['item_group_id'] = $item->itemGroupId;
        }
        if ($item->brand !== null && $item->brand !== '') {
            $entry['item_brand'] = $item->brand;
        }

        return $entry;
    }
}
