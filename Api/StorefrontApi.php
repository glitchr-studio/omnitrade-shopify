<?php

namespace Omnitrade\Shopify\Api;

/** The Storefront API, for the public catalogue and a Shopify-hosted cart. */
class StorefrontApi
{
    public function __construct(
        private readonly GraphQL $graphql,
        private readonly Endpoint $endpoint,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->endpoint->shopDomain && '' !== $this->endpoint->storefrontToken;
    }

    public function query(string $document, array $variables = []): array
    {
        if (!$this->isConfigured()) {
            throw new ShopifyApiException('The Shopify Storefront API is not configured: set storefront_token.');
        }

        return $this->graphql->query(
            $this->endpoint->storefrontUrl(),
            ['X-Shopify-Storefront-Access-Token' => $this->endpoint->storefrontToken],
            $document,
            $variables,
            $this->endpoint->timeout,
        );
    }
}
