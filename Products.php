<?php

namespace Omnitrade\Shopify;

use Omnitrade\Model\Media;
use Omnitrade\Model\Money;
use Omnitrade\Model\Offer;
use Omnitrade\Model\Option;
use Omnitrade\Model\Product;
use Omnitrade\Model\ProductVariant;
use Omnitrade\Model\Stock;
use Omnitrade\Shopify\Api\Endpoint;

/**
 * Shopify's two product shapes, read as one Product.
 *
 * Shopify sends two different shapes for the same product and does not warn
 * you:
 *
 *   fromGraphQL()  what the products(...) and product(id:) queries return -
 *                  "id" is a gid, variants are under variants.nodes, prices
 *                  are decimal strings, the fields are camelCase.
 *   fromRest()     what the products/create|update webhook POSTs - "id" is a
 *                  bare number, "variants" is a flat array, the fields are
 *                  snake_case, the tags one comma-separated string.
 *
 * The webhook payload is REST-shaped even when the subscription was created
 * through GraphQL, so both converge here, and the tests assert they give the
 * same Product for the same product. Pure: arrays in, models out.
 */
final class Products
{
    /**
     * @param array<string, mixed> $node     a products(...).nodes[] entry, or product(id:)
     * @param bool|null            $taxIncluded the shop's taxesIncluded
     */
    public static function fromGraphQL(array $node, string $currency, ?bool $taxIncluded = null): Product
    {
        $url = self::blankToNull($node['onlineStoreUrl'] ?? null);
        $nodes = $node['variants']['nodes'] ?? [];
        $variants = [];
        foreach ($nodes as $variant) {
            $tracked = (bool) ($variant['inventoryItem']['tracked'] ?? false);
            $reference = (string) ($variant['id'] ?? '');
            $options = [];
            foreach ($variant['selectedOptions'] ?? [] as $selected) {
                if (isset($selected['name'])) {
                    $options[(string) $selected['name']] = (string) ($selected['value'] ?? '');
                }
            }
            $weight = $variant['inventoryItem']['measurement']['weight'] ?? null;
            $image = \is_array($variant['image'] ?? null) && !empty($variant['image']['url'])
                ? new Media((string) $variant['image']['url'], self::blankToNull($variant['image']['altText'] ?? null), Media::IMAGE, self::int($variant['image']['width'] ?? null), self::int($variant['image']['height'] ?? null))
                : null;

            $variants[] = new ProductVariant(
                reference: $reference,
                title: self::variantTitle($variant['title'] ?? null),
                sku: self::blankToNull($variant['sku'] ?? null),
                barcode: self::blankToNull($variant['barcode'] ?? null),
                offers: self::offers($variant['price'] ?? null, $variant['compareAtPrice'] ?? null, $currency, (bool) ($variant['availableForSale'] ?? false), self::variantUrl($url, $reference, \count($nodes)), $taxIncluded),
                options: self::withoutDefault($options),
                // Untracked inventory is "as many as you like": a null quantity.
                stock: new Stock($reference, $tracked ? (int) ($variant['inventoryQuantity'] ?? 0) : null, $tracked, self::blankToNull($variant['sku'] ?? null), item: self::blankToNull($variant['inventoryItem']['id'] ?? null)),
                image: $image,
                weight: \is_array($weight) ? self::grams($weight['value'] ?? null, (string) ($weight['unit'] ?? 'GRAMS')) : null,
                raw: $variant,
            );
        }

        $options = [];
        foreach ($node['options'] ?? [] as $option) {
            $values = isset($option['optionValues'])
                ? array_map(static fn (array $v) => (string) ($v['name'] ?? ''), $option['optionValues'])
                : array_map('strval', (array) ($option['values'] ?? []));
            $options[] = new Option((string) ($option['name'] ?? ''), array_values(array_filter($values, static fn (string $v) => '' !== $v)));
        }

        $categories = [(string) ($node['productType'] ?? '')];
        foreach ($node['collections']['nodes'] ?? [] as $collection) {
            $categories[] = (string) ($collection['title'] ?? '');
        }

        $attributes = [];
        foreach ($node['metafields']['nodes'] ?? [] as $metafield) {
            if (isset($metafield['namespace'], $metafield['key'])) {
                $attributes[$metafield['namespace'].'.'.$metafield['key']] = (string) ($metafield['value'] ?? '');
            }
        }

        $media = [];
        foreach ($node['media']['nodes'] ?? [] as $item) {
            if (null !== $m = self::media($item)) {
                $media[] = $m;
            }
        }
        if (!$media && isset($node['featuredMedia']['image']['url'])) {
            $media[] = new Media((string) $node['featuredMedia']['image']['url'], self::blankToNull($node['featuredMedia']['image']['altText'] ?? null), reference: $node['featuredMedia']['id'] ?? null);
        }

        return new Product(
            provider: 'shopify',
            reference: (string) ($node['id'] ?? ''),
            title: (string) ($node['title'] ?? ''),
            description: self::blankToNull($node['descriptionHtml'] ?? null),
            handle: self::blankToNull($node['handle'] ?? null),
            brand: self::blankToNull($node['vendor'] ?? null),
            url: $url,
            status: self::status($node['status'] ?? null),
            tags: self::tags($node['tags'] ?? []),
            categories: self::unique($categories),
            attributes: $attributes,
            options: self::onlyRealOptions($options),
            variants: $variants,
            media: $media,
            updatedAt: self::date($node['updatedAt'] ?? null),
            raw: $node,
        );
    }

    /**
     * @param array<string, mixed> $payload a products/create|update webhook body
     */
    public static function fromRest(array $payload, string $currency, ?bool $taxIncluded = null): Product
    {
        $status = self::status($payload['status'] ?? null);
        $optionNames = [];
        $options = [];
        foreach ($payload['options'] ?? [] as $i => $option) {
            $position = (int) ($option['position'] ?? $i + 1);
            $optionNames[$position] = (string) ($option['name'] ?? '');
            $options[] = new Option((string) ($option['name'] ?? ''), array_values(array_map('strval', (array) ($option['values'] ?? []))));
        }

        $imagesById = [];
        $media = [];
        foreach ($payload['images'] ?? [] as $image) {
            if (empty($image['src'])) {
                continue;
            }
            $m = new Media((string) $image['src'], self::blankToNull($image['alt'] ?? null), Media::IMAGE, self::int($image['width'] ?? null), self::int($image['height'] ?? null), isset($image['admin_graphql_api_id']) ? (string) $image['admin_graphql_api_id'] : (isset($image['id']) ? (string) $image['id'] : null));
            $media[] = $m;
            if (isset($image['id'])) {
                $imagesById[(string) $image['id']] = $m;
            }
        }
        if (!$media && !empty($payload['image']['src'])) {
            $media[] = new Media((string) $payload['image']['src'], self::blankToNull($payload['image']['alt'] ?? null));
        }

        $variants = [];
        $count = \count($payload['variants'] ?? []);
        foreach ($payload['variants'] ?? [] as $variant) {
            // Older payloads say inventory_management ("shopify" or null);
            // a payload without it counts when it gives a quantity.
            $tracked = \array_key_exists('inventory_management', $variant)
                ? null !== $variant['inventory_management'] && '' !== $variant['inventory_management']
                : (isset($variant['inventory_item']['tracked']) ? (bool) $variant['inventory_item']['tracked'] : \array_key_exists('inventory_quantity', $variant));
            $quantity = (int) ($variant['inventory_quantity'] ?? 0);
            $reference = self::gid('ProductVariant', $variant['admin_graphql_api_id'] ?? $variant['id'] ?? null);
            $values = [];
            foreach ($optionNames as $position => $name) {
                $value = $variant['option'.$position] ?? null;
                if (null !== $value && '' !== $name) {
                    $values[$name] = (string) $value;
                }
            }
            $item = isset($variant['inventory_item_id']) ? self::gid('InventoryItem', $variant['inventory_item_id']) : null;
            // The REST shape has no per-variant availability flag: for sale
            // when the product is active and it has stock, does not count
            // it, or sells on when it is out.
            $available = Product::ACTIVE === $status && (!$tracked || $quantity > 0 || 'continue' === ($variant['inventory_policy'] ?? null));

            $variants[] = new ProductVariant(
                reference: $reference,
                title: self::variantTitle($variant['title'] ?? null),
                sku: self::blankToNull($variant['sku'] ?? null),
                barcode: self::blankToNull($variant['barcode'] ?? null),
                offers: self::offers($variant['price'] ?? null, $variant['compare_at_price'] ?? null, $currency, $available, null, $taxIncluded),
                options: self::withoutDefault($values),
                stock: new Stock($reference, $tracked ? $quantity : null, $tracked, self::blankToNull($variant['sku'] ?? null), item: $item),
                image: isset($variant['image_id']) ? ($imagesById[(string) $variant['image_id']] ?? null) : null,
                weight: isset($variant['grams']) ? (int) $variant['grams'] : (isset($variant['weight']) ? self::grams($variant['weight'], (string) ($variant['weight_unit'] ?? 'g')) : null),
                raw: $variant,
            );
        }

        return new Product(
            provider: 'shopify',
            reference: self::gid('Product', $payload['admin_graphql_api_id'] ?? $payload['id'] ?? null),
            title: (string) ($payload['title'] ?? ''),
            description: self::blankToNull($payload['body_html'] ?? null),
            handle: self::blankToNull($payload['handle'] ?? null),
            brand: self::blankToNull($payload['vendor'] ?? null),
            status: $status,
            tags: self::tags($payload['tags'] ?? []),
            categories: self::unique([(string) ($payload['product_type'] ?? '')]),
            options: self::onlyRealOptions($options),
            variants: $variants,
            media: $media,
            updatedAt: self::date($payload['updated_at'] ?? null),
            raw: $payload,
        );
    }

    /**
     * A variant's inventory, as VARIANT_STOCKS and STOCKS return it.
     *
     * @param array<string, mixed> $node
     */
    public static function stock(array $node): Stock
    {
        $tracked = (bool) ($node['inventoryItem']['tracked'] ?? false);

        return new Stock(
            (string) $node['id'],
            $tracked ? (int) ($node['inventoryQuantity'] ?? 0) : null,
            $tracked,
            self::blankToNull($node['sku'] ?? null),
            item: self::blankToNull($node['inventoryItem']['id'] ?? null),
        );
    }

    /** "ACTIVE", "active" -> Product::ACTIVE; DRAFT, UNLISTED -> DRAFT; ARCHIVED. */
    public static function status(mixed $status): string
    {
        return match (strtoupper((string) $status)) {
            'ARCHIVED' => Product::ARCHIVED,
            'DRAFT', 'UNLISTED' => Product::DRAFT,
            default => Product::ACTIVE,
        };
    }

    /** @return list<Offer> */
    private static function offers(mixed $price, mixed $compareAt, string $currency, bool $available, ?string $url, ?bool $taxIncluded): array
    {
        if (null === $price || '' === $price) {
            return [];
        }
        $price = Money::fromDecimal((string) $price, $currency);
        $compareAt = null === $compareAt || '' === $compareAt ? null : Money::fromDecimal((string) $compareAt, $currency);
        if (null !== $compareAt && $compareAt->amount <= $price->amount) {
            $compareAt = null;
        }

        return [new Offer($price, $compareAt, $available, $url, taxIncluded: $taxIncluded)];
    }

    private static function media(array $item): ?Media
    {
        $type = strtoupper((string) ($item['mediaContentType'] ?? 'IMAGE'));
        $reference = self::blankToNull($item['id'] ?? null);
        $alt = self::blankToNull($item['alt'] ?? null);
        if ('IMAGE' === $type && !empty($item['image']['url'])) {
            return new Media((string) $item['image']['url'], $alt, Media::IMAGE, self::int($item['image']['width'] ?? null), self::int($item['image']['height'] ?? null), $reference);
        }
        if ('VIDEO' === $type && !empty($item['sources'][0]['url'])) {
            return new Media((string) $item['sources'][0]['url'], $alt, Media::VIDEO, self::int($item['sources'][0]['width'] ?? null), self::int($item['sources'][0]['height'] ?? null), $reference);
        }
        if ('EXTERNAL_VIDEO' === $type && !empty($item['originUrl'])) {
            return new Media((string) $item['originUrl'], $alt, Media::VIDEO, reference: $reference);
        }

        return null; // a 3D model: not a picture
    }

    /** The product page with its variant chosen, when there are several. */
    private static function variantUrl(?string $url, string $gid, int $count): ?string
    {
        if (null === $url || $count < 2) {
            return $url;
        }
        $parts = explode('/', $gid);

        return $url.(str_contains($url, '?') ? '&' : '?').'variant='.end($parts);
    }

    /**
     * "Default Title" is what Shopify calls the single variant of a product
     * that has no options. It is not a name anyone wants on a product page.
     */
    private static function variantTitle(mixed $title): ?string
    {
        $title = trim((string) $title);

        return ('' === $title || 'Default Title' === $title) ? null : $title;
    }

    /** A product without options has one, "Title: Default Title": not one. */
    private static function withoutDefault(array $options): array
    {
        return ['Title' => 'Default Title'] === $options ? [] : $options;
    }

    /** @param list<Option> $options */
    private static function onlyRealOptions(array $options): array
    {
        return array_values(array_filter($options, static fn (Option $o) => !('Title' === $o->name && ['Default Title'] === $o->values)));
    }

    /** GraphQL gives an array of tags, REST a comma-separated string. */
    private static function tags(mixed $tags): array
    {
        if (\is_string($tags)) {
            $tags = explode(',', $tags);
        }

        return self::unique(array_map('strval', (array) $tags));
    }

    /** @return list<string> trimmed, without blanks and repeats */
    private static function unique(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('trim', $values), static fn (string $v) => '' !== $v)));
    }

    private static function grams(mixed $value, string $unit): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (int) round((float) $value * match (strtolower($unit)) {
            'kilograms', 'kg' => 1000,
            'ounces', 'oz' => 28.349523125,
            'pounds', 'lb' => 453.59237,
            default => 1,
        });
    }

    private static function gid(string $type, mixed $id): string
    {
        return null === $id || '' === $id ? '' : Endpoint::gid($type, (string) $id);
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Exception) {
            return null;
        }
    }

    private static function int(mixed $value): ?int
    {
        return null === $value || '' === $value ? null : (int) $value;
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = null === $value ? '' : trim((string) $value);

        return '' === $value ? null : $value;
    }
}
