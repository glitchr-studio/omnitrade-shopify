# omnitrade/shopify

Shopify for [glitchr/omnitrade](https://github.com/glitchr-studio/omnitrade): a sale paid on the
shop's invoice page (a draft order with your prices), the shop's orders, and its webhooks - over
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
```

`purchase()` creates a draft order and answers PENDING with its invoice URL; Shopify never
redirects back, so `fetch()` (the draft completed into a paid order, or deleted) and `notify()`
(`orders/paid`, `orders/cancelled`, `refunds/create`, HMAC and shop checked) finish the sale.
`fetchOrder()` reads any order of the shop. A line whose `reference` is a variant gid names
the variant, so Shopify counts its stock; any other line is a custom one at the price given.

No authorizations, captures nor refunds from here: Shopify's admin does those on its orders.

Credentials: a custom app in the shop's admin (Settings → Apps → Develop apps) with the
`write_draft_orders`, `read_orders` scopes, its Admin API access token, and the app's API
secret for the webhooks (`orders/paid`, `orders/cancelled`, `refunds/create` to your endpoint).

License: LGPL-3.0-or-later.
