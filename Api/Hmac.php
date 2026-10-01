<?php

namespace Omnitrade\Shopify\Api;

/**
 * Shopify signs a webhook body with the app's API secret and sends the digest
 * in X-Shopify-Hmac-Sha256: base64 of the raw binary hash, one value, no
 * timestamp (X-Shopify-Triggered-At is outside the signature). Replay defence
 * is the delivery id, X-Shopify-Webhook-Id, which the application keeps.
 */
final class Hmac
{
    public static function verify(string $payload, ?string $header, string $secret): bool
    {
        if (null === $header || '' === $header || '' === $secret) {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $payload, $secret, true)), $header);
    }

    /** The header Shopify would send for this body - used by the tests. */
    public static function sign(string $payload, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $payload, $secret, true));
    }
}
