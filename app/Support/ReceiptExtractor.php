<?php

namespace App\Support;

/**
 * Turns raw OCR text into a normalized array a Bill or Transaction form can
 * be pre-filled from. Backed by a local LM Studio model. Output shape is
 * stable even when extraction partially fails — every key is present with
 * a null when the model can't find a value.
 *
 * @phpstan-type ExtractedLineItem array{description: ?string, amount: ?float, quantity: ?float}
 * @phpstan-type Extracted array{
 *   kind: ?string,
 *   vendor: ?string,
 *   amount: ?float,
 *   currency: ?string,
 *   issued_on: ?string,
 *   due_on: ?string,
 *   tax_amount: ?float,
 *   line_items: array<int, ExtractedLineItem>,
 *   category_suggestion: ?string,
 *   confidence: ?float,
 *   raw: ?string,
 * }
 */
class ReceiptExtractor
{
    public function __construct(private readonly LmStudio $lmStudio) {}

    /**
     * @return Extracted|null null when the text is empty or the model returned nothing usable.
     */
    public function extract(string $ocrText): ?array
    {
        $trimmed = trim($ocrText);
        if ($trimmed === '') {
            return null;
        }

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($trimmed)],
        ];

        $raw = $this->lmStudio->chat($messages, [
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'extracted_receipt',
                    'schema' => $this->jsonSchema(),
                ],
            ],
            'temperature' => 0.1,
        ]);

        if ($raw === null) {
            return null;
        }

        $decoded = $this->parseJson($raw);
        if ($decoded === null) {
            return null;
        }

        return $this->normalize($decoded, $raw);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You extract structured data from OCR'd receipts, bills, and invoices. You
always respond with a single JSON object — no prose, no markdown fences.

The JSON object must have exactly these keys:
- kind: one of "receipt" | "invoice" | "bill" | "statement" | "other", or null if unclear.
- vendor: the counterparty name as it appears on the document, or null.
- amount: total payable as a number (no currency symbol, no commas), or null.
- currency: ISO 4217 code inferred from currency symbols / country hints (e.g. "USD", "EUR", "GBP", "ILS"). null if ambiguous.
- issued_on: the invoice/receipt/issue date in YYYY-MM-DD, or null.
- due_on: payment due date in YYYY-MM-DD if distinct from issued_on, else null.
- tax_amount: total tax (VAT/sales tax) as a number, or null.
- line_items: array of {description, amount, quantity}. amount and quantity are numbers or null. Empty array is allowed.
- category_suggestion: a short lowercase hint like "groceries", "utilities", "medical", "dining", "fuel", "rent", "subscription", or null.
- confidence: your self-assessed confidence from 0.0 to 1.0 that the extracted values are correct.

Rules:
- Never invent values. Prefer null over a guess.
- Normalize dates to YYYY-MM-DD. If a date is partial (missing year), return null.
- Amounts are always numbers, never strings. Strip currency symbols and thousands separators.
- If the document looks like a monthly bill with both an invoice date and a due date, fill both.
- If the same total appears with and without tax, "amount" is the grand total the customer owes.
PROMPT;
    }

    private function userPrompt(string $ocrText): string
    {
        return "OCR text between the fences. Extract the JSON per the schema.\n\n```\n".$ocrText."\n```";
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonSchema(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $nullableNumber = ['type' => ['number', 'null']];

        return [
            'type' => 'object',
            'properties' => [
                'kind' => $nullableString,
                'vendor' => $nullableString,
                'amount' => $nullableNumber,
                'currency' => $nullableString,
                'issued_on' => $nullableString,
                'due_on' => $nullableString,
                'tax_amount' => $nullableNumber,
                'line_items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'description' => $nullableString,
                            'amount' => $nullableNumber,
                            'quantity' => $nullableNumber,
                        ],
                    ],
                ],
                'category_suggestion' => $nullableString,
                'confidence' => $nullableNumber,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJson(string $raw): ?array
    {
        $candidate = trim($raw);

        if (str_starts_with($candidate, '```')) {
            $candidate = (string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $candidate);
            $candidate = trim($candidate);
        }

        $start = strpos($candidate, '{');
        $end = strrpos($candidate, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $candidate = substr($candidate, $start, $end - $start + 1);

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($candidate, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return Extracted
     */
    private function normalize(array $decoded, string $raw): array
    {
        $allowedKinds = ['receipt', 'invoice', 'bill', 'statement', 'other'];
        $kind = $this->stringOrNull($decoded['kind'] ?? null);
        if ($kind !== null && ! in_array(strtolower($kind), $allowedKinds, true)) {
            $kind = 'other';
        } elseif ($kind !== null) {
            $kind = strtolower($kind);
        }

        $currency = $this->stringOrNull($decoded['currency'] ?? null);
        if ($currency !== null) {
            $currency = strtoupper($currency);
            if (! preg_match('/^[A-Z]{3}$/', $currency)) {
                $currency = null;
            }
        }

        $items = [];
        $rawItems = $decoded['line_items'] ?? [];
        if (is_array($rawItems)) {
            foreach ($rawItems as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $items[] = [
                    'description' => $this->stringOrNull($item['description'] ?? null),
                    'amount' => $this->floatOrNull($item['amount'] ?? null),
                    'quantity' => $this->floatOrNull($item['quantity'] ?? null),
                ];
            }
        }

        return [
            'kind' => $kind,
            'vendor' => $this->stringOrNull($decoded['vendor'] ?? null),
            'amount' => $this->floatOrNull($decoded['amount'] ?? null),
            'currency' => $currency,
            'issued_on' => $this->dateOrNull($decoded['issued_on'] ?? null),
            'due_on' => $this->dateOrNull($decoded['due_on'] ?? null),
            'tax_amount' => $this->floatOrNull($decoded['tax_amount'] ?? null),
            'line_items' => $items,
            'category_suggestion' => $this->stringOrNull($decoded['category_suggestion'] ?? null),
            'confidence' => $this->floatOrNull($decoded['confidence'] ?? null),
            'raw' => $raw,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    private function dateOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }
}
