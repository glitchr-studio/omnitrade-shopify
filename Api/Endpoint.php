<?php

namespace Omnitrade\Shopify\Api;

/**
 * One Shopify shop: where it is, which API version to speak, and its tokens.
 * Every argument is nullable and normalised here, so an unset environment
 * variable (null, not '') degrades to "not configured" instead of a type error.
 */
final class Endpoint
{
    public readonly string $shopDomain;
    public readonly string $apiVersion;
    public readonly string $adminToken;
    public readonly string $storefrontToken;
    public readonly string $webhookSecret;
    public readonly int $timeout;

    public function __construct(
        ?string $shopDomain = '',
        ?string $apiVersion = '2026-07',
        ?string $adminToken = '',
        ?string $storefrontToken = '',
        ?string $webhookSecret = '',
        ?int $timeout = 15,
    ) {
        $this->shopDomain = trim((string) $shopDomain);
        $this->apiVersion = trim((string) $apiVersion) ?: '2026-07';
        $this->adminToken = trim((string) $adminToken);
        $this->storefrontToken = trim((string) $storefrontToken);
        $this->webhookSecret = trim((string) $webhookSecret);
        $this->timeout = $timeout ?: 15;
    }

    /** Whether there is enough here to call the Admin API at all. */
    public function isConfigured(): bool
    {
        return '' !== $this->shopDomain && '' !== $this->adminToken;
    }

    public function canVerifyWebhooks(): bool
    {
        return '' !== $this->webhookSecret;
    }

    /** The shop as Shopify names it in X-Shopify-Shop-Domain. */
    public function shop(): string
    {
        return $this->shopDomain;
    }

    public function adminUrl(): string
    {
        return sprintf('https://%s/admin/api/%s/graphql.json', $this->shopDomain, $this->apiVersion);
    }

    public function storefrontUrl(): string
    {
        return sprintf('https://%s/api/%s/graphql.json', $this->shopDomain, $this->apiVersion);
    }

    /** The admin URL of one resource, for an "open in Shopify" link. */
    public function adminLink(string $gid): string
    {
        $parts = explode('/', $gid);
        $id = end($parts);
        $type = strtolower((string) ($parts[\count($parts) - 2] ?? 'product'));

        return sprintf('https://%s/admin/%ss/%s', $this->shopDomain, $type, $id);
    }

    /** "gid://shopify/Order/123" from a bare id, or the gid as given. */
    public static function gid(string $type, string|int $id): string
    {
        return str_starts_with((string) $id, 'gid://') ? (string) $id : sprintf('gid://shopify/%s/%s', $type, $id);
    }
}
