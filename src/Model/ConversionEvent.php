<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Model;

use SilverShop\ConversionTracking\EventName;

/**
 * The normalised, platform-neutral event every lifecycle seam mints — the published contract a
 * {@see \SilverShop\ConversionTracking\Provider\ConversionProvider} turns into one platform's client tag(s) and
 * server payload. It carries everything both delivery paths need:
 *
 *  - the taxonomy {@see EventName} (→ Meta `event_name` / GA4 event name),
 *  - the deterministic {@see self::$eventId} shared by the browser tag and the server send so Meta/Google
 *    de-duplicate the pair (for Purchase it is `purchase.{OrderID}`, so a webhook re-send reuses it),
 *  - the {@see ConversionItem} lines (ids = catalog-data `feedId()`), value + ISO currency, `content_type`,
 *  - the raw {@see ConversionUser} matching data (hashing/forwarding is the connector's job; never put on the
 *    browser `dataLayer`),
 *  - the source URL + unix time for CAPI `event_source_url` / `event_time`.
 *
 * It is fully array-serialisable ({@see self::toArray()} / {@see self::fromArray()}) so it can ride inside the
 * retryable {@see \SilverShop\ConversionTracking\Job\ConversionDispatchJob}; the owning Order is held by id and
 * resolved lazily to keep the job payload a plain array.
 */
final class ConversionEvent
{
    public const CONTENT_TYPE_PRODUCT = 'product';

    public const CONTENT_TYPE_PRODUCT_GROUP = 'product_group';

    /**
     * @param array<int, ConversionItem> $items
     */
    public function __construct(
        public readonly EventName $name,
        public readonly string $eventId,
        public readonly array $items = [],
        public readonly float $value = 0.0,
        public readonly string $currency = '',
        public readonly string $contentType = self::CONTENT_TYPE_PRODUCT,
        public readonly ConversionUser $user = new ConversionUser(),
        public readonly string $sourceUrl = '',
        public readonly int $occurredAt = 0,
        public readonly int $orderID = 0,
        public readonly array $consentedCategories = [],
    ) {
    }

    /**
     * Return a copy with the given user attached (used to bind request-captured matching data at mint time).
     */
    public function withUser(ConversionUser $user): self
    {
        return new self(
            $this->name,
            $this->eventId,
            $this->items,
            $this->value,
            $this->currency,
            $this->contentType,
            $user,
            $this->sourceUrl,
            $this->occurredAt,
            $this->orderID,
            $this->consentedCategories,
        );
    }

    /**
     * The cookieconsent categories granted at mint time, frozen onto the event so the later (queued, request-less)
     * server-side dispatch stays GDPR-correct — a connector only sends when its category is present here. For a
     * webhook-driven {@see \SilverShop\Model\Order} paid transition there is no request/cookie, so this is empty
     * and the server send is a graceful no-op (default-deny).
     *
     * @return array<int, string>
     */
    public function consentedCategories(): array
    {
        return $this->consentedCategories;
    }

    /**
     * Whether the given cookieconsent category was granted when the event was minted.
     */
    public function hasConsent(string $category): bool
    {
        return in_array($category, $this->consentedCategories, true);
    }

    /**
     * The flat array of product ids in this event (Meta `content_ids`).
     *
     * @return array<int, string>
     */
    public function contentIds(): array
    {
        return array_values(array_map(static fn (ConversionItem $item): string => $item->id, $this->items));
    }

    /**
     * The owning order, resolved lazily by id (purchase events only), or null. Guarded so the VO stays usable
     * without silvershop/core on the analysis/standalone path.
     */
    public function order(): ?object
    {
        if ($this->orderID <= 0 || !class_exists(\SilverShop\Model\Order::class)) {
            return null;
        }

        return \SilverShop\Model\Order::get()->byID($this->orderID);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name->value,
            'eventId' => $this->eventId,
            'items' => array_map(static fn (ConversionItem $item): array => $item->toArray(), $this->items),
            'value' => $this->value,
            'currency' => $this->currency,
            'contentType' => $this->contentType,
            'user' => $this->user->toArray(),
            'sourceUrl' => $this->sourceUrl,
            'occurredAt' => $this->occurredAt,
            'orderID' => $this->orderID,
            'consentedCategories' => array_values($this->consentedCategories),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $items = [];
        foreach ((array) ($data['items'] ?? []) as $itemData) {
            if (is_array($itemData)) {
                $items[] = ConversionItem::fromArray($itemData);
            }
        }

        return new self(
            EventName::from((string) ($data['name'] ?? EventName::PAGE_VIEW->value)),
            (string) ($data['eventId'] ?? ''),
            $items,
            (float) ($data['value'] ?? 0.0),
            (string) ($data['currency'] ?? ''),
            (string) ($data['contentType'] ?? self::CONTENT_TYPE_PRODUCT),
            ConversionUser::fromArray((array) ($data['user'] ?? [])),
            (string) ($data['sourceUrl'] ?? ''),
            (int) ($data['occurredAt'] ?? 0),
            (int) ($data['orderID'] ?? 0),
            array_values(array_filter(array_map(
                static fn ($category): string => (string) $category,
                (array) ($data['consentedCategories'] ?? [])
            ))),
        );
    }
}
