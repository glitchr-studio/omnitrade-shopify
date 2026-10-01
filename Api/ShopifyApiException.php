<?php

namespace Omnitrade\Shopify\Api;

use Omnitrade\Exception\ProviderException;

/**
 * Shopify said no, or said nothing. Carries the userErrors/errors it came
 * with; never the access token (the headers are built at request time).
 */
class ShopifyApiException extends ProviderException
{
    public function __construct(string $message, public readonly array $errors = [], ?\Throwable $previous = null)
    {
        parent::__construct('shopify', $message, null, $previous);
    }

    /** @param array<array{message?: string, field?: array|null}> $errors */
    public static function fromErrors(string $what, array $errors): self
    {
        $messages = [];
        foreach ($errors as $error) {
            $field = $error['field'] ?? null;
            $messages[] = ($field ? implode('.', (array) $field).': ' : '').($error['message'] ?? 'unknown error');
        }

        return new self($what.': '.implode('; ', $messages ?: ['unknown error']), $errors);
    }
}
