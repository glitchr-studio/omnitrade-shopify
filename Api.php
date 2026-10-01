<?php

namespace Omnitrade\Shopify;

use Omnitrade\Shopify\Api\AdminApi;
use Omnitrade\Shopify\Api\Endpoint;
use Omnitrade\Shopify\Api\GraphQL;
use Omnitrade\Shopify\Api\StorefrontApi;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** One shop's APIs, for the gateway's actions. */
final class Api
{
    public readonly AdminApi $admin;
    public readonly StorefrontApi $storefront;

    /** @param string[] $draftOrderTags */
    public function __construct(
        public readonly Endpoint $endpoint,
        HttpClientInterface $http,
        public readonly array $draftOrderTags = [],
        ?LoggerInterface $logger = null,
    ) {
        $graphql = new GraphQL($http, $logger);
        $this->admin = new AdminApi($graphql, $endpoint);
        $this->storefront = new StorefrontApi($graphql, $endpoint);
    }
}
