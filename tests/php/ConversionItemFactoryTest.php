<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Tests;

use SilverShop\ConversionTracking\Service\ConversionItemFactory;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * content_ids / item_id = the shared feed id. Exercises BOTH paths:
 *  - the identical-rule FALLBACK (forced on by pointing catalog_factory_class at a non-existent class), proving a
 *    shop without silvershop/catalog-data still gets ids that match the feed's rule;
 *  - DELEGATION to silvershop/catalog-data when installed (the id must equal its feedId()), skipped on a bare
 *    install.
 */
class ConversionItemFactoryTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(Product::class, 'global_allow_purchase', true);
    }

    private function forceFallback(): ConversionItemFactory
    {
        Config::modify()->set(ConversionItemFactory::class, 'catalog_factory_class', '\\NoCatalogModule');

        return ConversionItemFactory::create();
    }

    private function newProduct(array $data = []): Product
    {
        $product = Product::create(array_merge(['Title' => 'Rain Jacket', 'BasePrice' => 12.95], $data));
        $product->write();
        $product->publishRecursive();

        return $product;
    }

    private function newVariation(Product $product, array $data = []): Variation
    {
        $variation = Variation::create(array_merge(['ProductID' => $product->ID, 'Price' => 12.95], $data));
        $variation->write();

        return $variation;
    }

    public function testFallbackProductUsesInternalItemId(): void
    {
        $factory = $this->forceFallback();
        $this->assertSame('RJ-001', $factory->feedId($this->newProduct(['InternalItemID' => 'RJ-001'])));
    }

    public function testFallbackProductFallsBackToIdWhenNoSku(): void
    {
        $factory = $this->forceFallback();
        $product = $this->newProduct();
        $this->assertSame('product-' . $product->ID, $factory->feedId($product));
    }

    public function testFallbackVariationUsesOwnInternalItemId(): void
    {
        $factory = $this->forceFallback();
        $product = $this->newProduct(['InternalItemID' => 'RJ-001']);
        $variation = $this->newVariation($product, ['InternalItemID' => 'RJ-001-RED']);
        $this->assertSame('RJ-001-RED', $factory->feedId($variation));
    }

    public function testFallbackVariationComposesParentFeedIdPlusVariationId(): void
    {
        $factory = $this->forceFallback();
        $product = $this->newProduct(['InternalItemID' => 'RJ-001']);
        $variation = $this->newVariation($product);
        $this->assertSame('RJ-001-' . $variation->ID, $factory->feedId($variation));
    }

    public function testFallbackVariationUsesProductIdWhenParentHasNoSku(): void
    {
        $factory = $this->forceFallback();
        $product = $this->newProduct();
        $variation = $this->newVariation($product);
        $this->assertSame('product-' . $product->ID . '-' . $variation->ID, $factory->feedId($variation));
    }

    public function testFallbackIdSourceIdIgnoresSku(): void
    {
        Config::modify()->set(ConversionItemFactory::class, 'id_source', ConversionItemFactory::ID_SOURCE_ID);
        $factory = $this->forceFallback();
        $product = $this->newProduct(['InternalItemID' => 'RJ-001']);
        $this->assertSame('product-' . $product->ID, $factory->feedId($product));
    }

    public function testFallbackTruncatesToMaxLength(): void
    {
        Config::modify()->set(ConversionItemFactory::class, 'id_max_length', 5);
        $factory = $this->forceFallback();
        $this->assertSame('RJ-00', $factory->feedId($this->newProduct(['InternalItemID' => 'RJ-001234'])));
    }

    public function testForBuyableBuildsItemWithIdNamePriceAndGroup(): void
    {
        $factory = $this->forceFallback();
        $product = $this->newProduct(['InternalItemID' => 'RJ-001']);
        $variation = $this->newVariation($product, ['InternalItemID' => 'RJ-001-RED']);

        $item = $factory->forBuyable($variation, 3, 9.99);
        $this->assertSame('RJ-001-RED', $item->id);
        $this->assertSame('RJ-001', $item->itemGroupId);
        $this->assertSame(9.99, $item->price);
        $this->assertSame(3, $item->quantity);
        $this->assertStringContainsString('Rain Jacket', $item->name);
    }

    public function testDelegatesToCatalogDataWhenInstalled(): void
    {
        $catalogClass = 'SilverShop\\CatalogData\\CatalogProductDataFactory';
        if (!class_exists($catalogClass)) {
            $this->markTestSkipped('silvershop/catalog-data is not installed.');
        }

        // Default config → the factory must delegate, so its id equals catalog-data's feedId() exactly.
        $factory = ConversionItemFactory::create();
        $product = $this->newProduct(['InternalItemID' => 'RJ-DELEGATE']);

        $expected = $catalogClass::create()->feedId($product);
        $this->assertSame($expected, $factory->feedId($product));
        $this->assertSame($expected, $factory->forBuyable($product, 1)->id);
    }
}
