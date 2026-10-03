# omnitrade/shopify

Shopify for [glitchr/omnitrade](https://github.com/glitchr-studio/omnitrade): a sale paid on the
shop's invoice page (a draft order with your prices), the shop's orders, its catalogue, and its webhooks - over
the Admin GraphQL API, its cost bucket respected.

```yaml
omnitrade:
    gateways:
        shopify:
            factory: shopify
            options:
                shop_domain: '%env(SHOPIFY_SHOP_DOMAIN)%'       # example.myshopify.com
                admin_token: '%env(SHOPIFY_ADMIN_TOKEN)%'       # shpat_..., a custom app's Admin API token
                webhook_secret: '%env(SHOPIFY_WEBHOOK_SECRET)%' # the app's API secret, for notify()
                draft_order_tags: [omnitrade]
                currency: null                                  # the catalogue's currency; null: the shop's
```

`purchase()` creates a draft order and answers PENDING with its invoice URL; Shopify never
redirects back, so `fetch()` (the draft completed into a paid order, or deleted) and `notify()`
(`orders/paid`, `orders/cancelled`, `refunds/create`, HMAC and shop checked) finish the sale.
`fetchOrder()` reads any order of the shop. A line whose `reference` is a variant gid names
the variant, so Shopify counts its stock; any other line is a custom one at the price given.

No authorizations, captures nor refunds from here: Shopify's admin does those on its orders.

## Catalogue

`fetchProducts()` pages through the shop's products (`updated_at` and search filters),
`fetchProduct()` finds one by gid, bare id, handle or page URL, `fetchInventory()` reads the
variants' quantities; `notify()` reads `products/create|update|delete` and
`inventory_levels/update` into a `Notification` carrying the `Product` or the `Stock`. Vendor is
the brand, product type and collections the categories, metafields the attributes, the variants'
selected options their options. See [docs/catalogue.md](docs/catalogue.md); it needs the
`read_products` and `read_inventory` scopes.

Credentials: a custom app in the shop's admin (Settings → Apps → Develop apps) with the
`write_draft_orders`, `read_orders` scopes, its Admin API access token, and the app's API
secret for the webhooks (`orders/paid`, `orders/cancelled`, `refunds/create` to your endpoint).

License: LGPL-3.0-or-later.
