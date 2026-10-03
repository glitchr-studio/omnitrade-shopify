---
title: Catalogue
order: 10
---

# Shopify's catalogue

The shop's products, variants and inventory, read through the
[omnitrade catalogue contract](https://github.com/glitchr-studio/omnitrade/blob/1.x/docs/catalogue.md)
over the Admin GraphQL API (the cost bucket is respected, a THROTTLED answer retried).

## Installation

```sh
composer require omnitrade/shopify symfony/http-client
```

```yaml
omnitrade:
    gateways:
        shopify:
            factory: shopify
            options:
                shop_domain: '%env(SHOPIFY_SHOP_DOMAIN)%'       # example.myshopify.com
                admin_token: '%env(SHOPIFY_ADMIN_TOKEN)%'       # shpat_...
                webhook_secret: '%env(SHOPIFY_WEBHOOK_SECRET)%' # for the product and inventory webhooks
                currency: null                                  # the prices' currency; null: the shop's
```

The custom app needs the `read_products` and `read_inventory` scopes (on top of
`write_draft_orders`, `read_orders` for the sales).

| Option | Default | |
|---|---|---|
| `currency` | `null` | The currency of the prices. Shopify's prices are bare decimals in the shop's currency: by default it is read with them (`shop { currencyCode }`, in the same query for `fetchProducts()`/`fetchProduct()`, once per gateway for the webhooks). |

## Requests

| Request | What is sent |
|---|---|
| `fetchProducts(?cursor, ?updatedSince, ?query, limit)` | `products(first: limit (≤ 250), after: cursor, query: …, sortKey: UPDATED_AT)` with `shop { currencyCode taxesIncluded }`. `updatedSince` becomes `updated_at:>'2026-10-01T08:00:00Z'` (UTC), `query` is appended as Shopify's own search text (`updated_at:>'…' margaux`). The next cursor is `pageInfo.endCursor` while `hasNextPage`. Pass the same `updatedSince` and `query` with the cursor. |
| `fetchProduct($reference)` | `gid://shopify/Product/42` or `42` → `product(id:)`; a handle, or a page's URL (`https://shop.example/products/<handle>`, also under `/collections/…/products/<handle>`) → `products(query: "handle:\"<handle>\"")`, the exact handle kept; an admin URL `/admin/products/42` → by id. `null` when there is no such product. |
| `fetchInventory($variants)` | The variants (gids, or bare ids) through `nodes(ids:)`, 250 at a time; none: every variant of the shop through `productVariants`, page by page. Unknown variants are left out. |

Each product carries at most 100 variants, 20 collections, 20 metafields and 20 media.

## What is mapped how

| Shopify | omnitrade |
|---|---|
| `id` (gid) | `Product::$reference` |
| `title`, `descriptionHtml`, `handle` | `title`, `description`, `handle` |
| `vendor` | `brand` |
| `productType` + `collections.title` | `categories` (once each, the type first) |
| `tags` | `tags` |
| `metafields` (first 20) | `attributes`, keyed `namespace.key` |
| `options` (`optionValues`) | `options` (`Option`); Shopify's "Title: Default Title" is dropped |
| `media` | `media`: `MediaImage` → image, `Video` (first source) and `ExternalVideo` → video; 3D models skipped |
| `status` `ACTIVE` / `DRAFT` / `ARCHIVED` | `Product::ACTIVE` / `DRAFT` / `ARCHIVED` (`UNLISTED` → draft) |
| `onlineStoreUrl` | `url` (null when the product is not published to the online store) |
| `updatedAt` | `updatedAt` |
| variant `id`, `sku`, `barcode` | `ProductVariant::$reference`, `sku`, `barcode` (blank → null) |
| variant `title` | `title`, "Default Title" → null |
| variant `selectedOptions` | `ProductVariant::$options` (`['Millésime' => '2018']`) |
| variant `price`, `compareAtPrice` | `Offer` price and compareAt (compareAt kept only when higher), in the shop's currency; `available` = `availableForSale`; `taxIncluded` = the shop's `taxesIncluded`; `url` = the product page with `?variant=<id>` when there are several |
| variant `inventoryQuantity`, `inventoryItem { id tracked }` | `Stock`: the quantity when tracked, else `null` with `tracked: false`; `item` is the InventoryItem gid |
| variant `image` | `ProductVariant::$image` |
| `inventoryItem.measurement.weight` | `weight` in grams |

## Webhooks

Subscribe the app to `products/create`, `products/update`, `products/delete` and
`inventory_levels/update`; `notify()` checks the HMAC and the shop as for the orders.

| Topic | Notification |
|---|---|
| `products/create`, `products/update` | `$product`: the REST-shaped payload mapped to the same `Product` as a GraphQL node (bare ids made gids, the comma-separated tags split, `option1..3` named after the product's options, "Default Title" dropped; no collections, metafields nor `onlineStoreUrl`, which the payload does not carry). `$reference` is the product's gid. |
| `products/delete` | `$product` null, `$reference` the product's gid. |
| `inventory_levels/update` | `$stocks`: one `Stock` at one location (`location` = `gid://shopify/Location/…`), `quantity` = `available`. The payload names the inventory item, not the variant: `Stock::$reference` and `$item` are the `gid://shopify/InventoryItem/…` - match it on the variants' `Stock::$item`. |

```php
$notification = $gateway->notify($request->getContent(), $request->headers->all());
if ($notification->isCatalogue()) {
    match (true) {
        null !== $notification->product => $catalogue->save($notification->product),
        [] !== $notification->stocks => $catalogue->restock($notification->stocks),     // by Stock::$item
        default => $catalogue->remove($notification->reference),
    };
}
```

## Examples

```php
$page = $shopify->fetchProducts(updatedSince: $lastSync);
while (true) {
    foreach ($page->products as $product) {
        // $product->brand, $product->variants[0]->price(), $product->variants[0]->stock->quantity
    }
    if (!$page->hasMore()) {
        break;
    }
    $page = $shopify->fetchProducts($page->next, updatedSince: $lastSync);
}

$product = $shopify->fetchProduct('https://cave.example/products/margaux-2019');
$stocks = $shopify->fetchInventory(['gid://shopify/ProductVariant/7', '8']);
```

With the harness of glitchr/omnitrade (`core/docker`):

```sh
docker compose run --rm omnitrade catalogue shopify --since=-1day
docker compose run --rm omnitrade catalogue shopify --product=https://cave.example/products/margaux-2019 --inventory
```
