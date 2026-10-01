<?php

namespace Omnitrade\Shopify\Api;

/** The Admin GraphQL API of the configured shop: the client bound to one Endpoint. */
class AdminApi
{
    public function __construct(
        private readonly GraphQL $graphql,
        private readonly Endpoint $endpoint,
    ) {
    }

    public function endpoint(): Endpoint
    {
        return $this->endpoint;
    }

    public function isConfigured(): bool
    {
        return $this->endpoint->isConfigured();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ShopifyApiException
     */
    public function query(string $document, array $variables = []): array
    {
        if (!$this->endpoint->isConfigured()) {
            throw new ShopifyApiException('Shopify is not configured: set shop_domain and admin_token.');
        }

        return $this->graphql->query(
            $this->endpoint->adminUrl(),
            ['X-Shopify-Access-Token' => $this->endpoint->adminToken],
            $document,
            $variables,
            $this->endpoint->timeout,
        );
    }

    /** A mutation, with its userErrors raised rather than returned. */
    public function mutate(string $document, array $variables, string $root): array
    {
        $data = $this->query($document, $variables);

        return $this->graphql->assertNoUserErrors($root, $data[$root] ?? []);
    }
}
