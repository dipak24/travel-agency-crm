<?php

use App\Support\Money;

test('toDecimal converts minor-unit cents into a major-unit decimal', function () {
    expect(Money::toDecimal(10000))->toBe(100.0)
        ->and(Money::toDecimal(150))->toBe(1.5)
        ->and(Money::toDecimal(1))->toBe(0.01)
        ->and(Money::toDecimal(0))->toBe(0.0);
});

test('toDecimal returns null for null or empty input', function () {
    expect(Money::toDecimal(null))->toBeNull()
        ->and(Money::toDecimal(''))->toBeNull();
});

test('toCents converts a major-unit decimal into minor-unit cents', function () {
    expect(Money::toCents(100.00))->toBe(10000)
        ->and(Money::toCents('100.00'))->toBe(10000)
        ->and(Money::toCents(1.5))->toBe(150)
        ->and(Money::toCents(0.01))->toBe(1)
        ->and(Money::toCents(0))->toBe(0);
});

test('toCents returns null for null or empty input', function () {
    expect(Money::toCents(null))->toBeNull()
        ->and(Money::toCents(''))->toBeNull();
});

test('toCents rounds to the nearest cent rather than truncating', function () {
    // Floating point decimals like 19.999 must round to the nearest cent (2000), not truncate to 1999.
    expect(Money::toCents(19.999))->toBe(2000)
        ->and(Money::toCents(19.994))->toBe(1999);
});

test('toDecimal and toCents round-trip without drift', function () {
    foreach ([1, 99, 100, 1099, 150000, 999999] as $cents) {
        expect(Money::toCents(Money::toDecimal($cents)))->toBe($cents);
    }
});

test('format renders a cents value as a decimal string, optionally with a currency prefix', function () {
    expect(Money::format(10000))->toBe('100.00')
        ->and(Money::format(150))->toBe('1.50')
        ->and(Money::format(10000, 'USD'))->toBe('USD 100.00')
        ->and(Money::format(null, 'USD'))->toBe('USD 0.00');
});
