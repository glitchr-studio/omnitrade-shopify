<?php

namespace Omnitrade\Shopify;

use Omnitrade\Config;
use Omnitrade\GatewayFactory;
use Omnitrade\Shopify\Action\FetchInventoryAction;
use Omnitrade\Shopify\Action\FetchOrderAction;
use Omnitrade\Shopify\Action\FetchProductAction;
use Omnitrade\Shopify\Action\FetchProductsAction;
use Omnitrade\Shopify\Action\FetchTransactionAction;
use Omnitrade\Shopify\Action\NotifyAction;
use Omnitrade\Shopify\Action\PurchaseAction;
use Omnitrade\Shopify\Api\Endpoint;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Shopify, a commerce platform: a sale paid on the shop's invoice page (a
 * draft order), the shop's orders, its catalogue (products, variants,
 * inventory) and its webhooks.
 *
 *   options:
 *     shop_domain: '%env(SHOPIFY_SHOP_DOMAIN)%'        # example.myshopify.com
 *     admin_token: '%env(SHOPIFY_ADMIN_TOKEN)%'        # shpat_..., a custom app's Admin API token
 *     webhook_secret: '%env(SHOPIFY_WEBHOOK_SECRET)%'  # the app's API secret, for notify()
 *     storefront_token: null                           # optional, the Storefront API
 *     api_version: '2026-07'
 *     timeout: 15
 *     draft_order_tags: []                             # tags put on the draft orders it creates
 *     currency: null                                   # the catalogue's prices' currency; null: the shop's
 *
 * No authorizations, captures nor refunds from here: Shopify's own admin does
 * those on its orders.
 */
final class ShopifyGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnitrade.factory_name' => 'shopify',
            'omnitrade.factory_title' => 'Shopify',
            'omnitrade.required_options' => ['shop_domain', 'admin_token'],
            'webhook_secret' => null,
            'storefront_token' => null,
            'api_version' => '2026-07',
            'timeout' => 15,
            'draft_order_tags' => [],
            'currency' => null,
            'omnitrade.api' => function (Config $c) {
                $http = $this->http ?? HttpClient::create();

                return new Api(new Endpoint($c['shop_domain'], $c['api_version'], $c['admin_token'], $c['storefront_token'], $c['webhook_secret'], (int) $c['timeout']), $http, (array) $c['draft_order_tags']);
            },
            'omnitrade.action.purchase' => new PurchaseAction(),
            'omnitrade.action.fetch' => new FetchTransactionAction(),
            'omnitrade.action.order' => new FetchOrderAction(),
            'omnitrade.action.notify' => static fn (Config $c) => new NotifyAction($c['currency'] ?: null),
            'omnitrade.action.products' => static fn (Config $c) => new FetchProductsAction($c['currency'] ?: null),
            'omnitrade.action.product' => static fn (Config $c) => new FetchProductAction($c['currency'] ?: null),
            'omnitrade.action.inventory' => new FetchInventoryAction(),
        ]);
    }
}
