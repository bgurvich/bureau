<?php

declare(strict_types=1);

use App\Support\Formatting;

it('renders USD with a leading dollar sign', function () {
    expect(Formatting::money(42, 'USD'))->toBe('$42.00');
});

it('renders EUR with the euro symbol', function () {
    expect(Formatting::money(1234.5, 'EUR'))->toBe('€1,234.50');
});

it('renders GBP with the pound symbol', function () {
    expect(Formatting::money(7.5, 'GBP'))->toBe('£7.50');
});

it('prefixes the sign before the symbol for negative amounts', function () {
    // Matches browser/ICU conventions — "-$42.00", not "$-42.00".
    expect(Formatting::money(-42, 'USD'))->toBe('-$42.00');
    expect(Formatting::money(-1000, 'EUR'))->toBe('-€1,000.00');
});

it('positions Scandinavian krona after the number', function () {
    expect(Formatting::money(120, 'SEK'))->toBe('120.00 kr');
    expect(Formatting::money(-50.25, 'NOK'))->toBe('-50.25 kr');
});

it('disambiguates dollar-named currencies with region prefixes', function () {
    expect(Formatting::money(100, 'CAD'))->toBe('CA$100.00');
    expect(Formatting::money(100, 'AUD'))->toBe('A$100.00');
    expect(Formatting::money(100, 'MXN'))->toBe('MX$100.00');
});

it('falls back to the raw code for currencies not in the symbol map', function () {
    // Unknown / obscure codes keep their ISO form so we never render a wrong glyph.
    expect(Formatting::money(42, 'XAU'))->toBe('XAU42.00');
});

it('currencySymbol returns an empty string for null or empty input', function () {
    expect(Formatting::currencySymbol(null))->toBe('');
    expect(Formatting::currencySymbol(''))->toBe('');
});

it('currencySymbol is case-insensitive', function () {
    expect(Formatting::currencySymbol('usd'))->toBe('$');
    expect(Formatting::currencySymbol('EuR'))->toBe('€');
});

it('money formats thousands with commas', function () {
    expect(Formatting::money(1234567.89, 'USD'))->toBe('$1,234,567.89');
});
