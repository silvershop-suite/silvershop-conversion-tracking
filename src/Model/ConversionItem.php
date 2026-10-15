<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Model;

/**
 * One line item inside a {@see ConversionEvent} — the platform-neutral shape a connector turns into a Meta
 * `contents[]` entry or a GA4 `items[]` entry. The {@see self::$id} is the shared product identity
 * ({@see \SilverShop\CatalogData\CatalogProductData::feedId()} when silvershop/catalog-data is installed, otherwise
 * the identical-rule fallback) so the ad-platform catalogue match and the product feed's `g:id` agree by
 * construction.
 */
final class ConversionItem
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $itemGroupId = null,
        public readonly string $name = '',
        public readonly float $price = 0.0,
        public readonly int $quantity = 1,
        public readonly ?string $brand = null,
        public readonly ?string $gtin = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'itemGroupId' => $this->itemGroupId,
            'name' => $this->name,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'brand' => $this->brand,
            'gtin' => $this->gtin,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            isset($data['itemGroupId']) ? (string) $data['itemGroupId'] : null,
            (string) ($data['name'] ?? ''),
            (float) ($data['price'] ?? 0.0),
            (int) ($data['quantity'] ?? 1),
            isset($data['brand']) ? (string) $data['brand'] : null,
            isset($data['gtin']) ? (string) $data['gtin'] : null,
        );
    }
}
