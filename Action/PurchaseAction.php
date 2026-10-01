<?php

namespace Omnitrade\Shopify\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction;
use Omnitrade\Request\Purchase;
use Omnitrade\Request\Request;
use Omnitrade\Shopify\Api;
use Omnitrade\Shopify\Documents;

/**
 * A draft order, paid on Shopify's invoice page: the buyer is sent there
 * (PENDING with the invoice URL), and Shopify's orders/paid webhook or
 * fetch() says when they paid. A draft order's lines take the prices they are
 * given - a line with a variant gid as its reference names the variant, so
 * Shopify counts its stock; any other is a custom line. The shipping and the
 * discount are carried over whole. Shopify's checkout never redirects back:
 * returnUrl is not used.
 */
final class PurchaseAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Purchase;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Purchase);
        $payment = $request->payment;
        $result = $this->api->admin->mutate(Documents::DRAFT_ORDER_CREATE, ['input' => self::input($payment, $this->api->draftOrderTags)], 'draftOrderCreate');
        $draft = $result['draftOrder'] ?? [];
        if (empty($draft['id']) || empty($draft['invoiceUrl'])) {
            throw new ProviderException('shopify', 'Shopify created no draft order with an invoice URL.');
        }

        $request->setResult(new Transaction(
            provider: 'shopify',
            reference: (string) $draft['id'],
            status: Status::PENDING,
            amount: isset($draft['totalPrice']) ? Money::fromDecimal((string) $draft['totalPrice'], $payment->amount->currency) : $payment->amount,
            redirectUrl: (string) $draft['invoiceUrl'],
            message: $draft['status'] ?? null,
            metadata: ['name' => $draft['name'] ?? null, 'shop' => $this->api->endpoint->shop()],
            raw: $result,
        ));
    }

    /**
     * @param string[] $tags
     *
     * @return array<string, mixed> a DraftOrderInput
     */
    public static function input(Payment $payment, array $tags = []): array
    {
        $currency = $payment->amount->currency;
        $lineItems = [];
        if ($payment->linesAddUp()) {
            foreach ($payment->lines as $line) {
                $item = [
                    'quantity' => $line->quantity,
                    'originalUnitPriceWithCurrency' => ['amount' => $line->unitAmount->decimal(), 'currencyCode' => $currency],
                ];
                if ($line->reference && str_starts_with($line->reference, 'gid://shopify/ProductVariant/')) {
                    $item['variantId'] = $line->reference;
                } else {
                    $item['title'] = $line->label;
                    $item['requiresShipping'] = $line->physical;
                    if ($line->sku) {
                        $item['sku'] = $line->sku;
                    }
                }
                $lineItems[] = $item;
            }
        } else {
            $lineItems[] = [
                'quantity' => 1,
                'title' => $payment->description ?? $payment->reference,
                'requiresShipping' => false,
                'originalUnitPriceWithCurrency' => ['amount' => $payment->amount->decimal(), 'currencyCode' => $currency],
            ];
        }
        $input = [
            'lineItems' => $lineItems,
            'note' => $payment->description ?? sprintf('Order %s', $payment->reference),
            'customAttributes' => [['key' => Documents::REFERENCE_KEY, 'value' => $payment->reference]],
            'presentmentCurrencyCode' => $currency,
        ];
        foreach ($payment->metadata as $key => $value) {
            $input['customAttributes'][] = ['key' => (string) $key, 'value' => (string) $value];
        }
        if ($tags) {
            $input['tags'] = $tags;
        }
        if ($payment->customer?->email) {
            $input['email'] = $payment->customer->email;
        }
        if ($payment->linesAddUp() && $payment->shipping && $payment->shipping->amount > 0) {
            $input['shippingLine'] = ['title' => 'Shipping', 'priceWithCurrency' => ['amount' => $payment->shipping->decimal(), 'currencyCode' => $currency]];
        }
        if ($payment->linesAddUp() && $payment->discount && $payment->discount->amount > 0) {
            $input['appliedDiscount'] = ['title' => 'Discount', 'value' => (float) $payment->discount->decimal(), 'valueType' => 'FIXED_AMOUNT'];
        }

        return $input;
    }
}
