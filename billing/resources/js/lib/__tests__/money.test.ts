import { afterEach, describe, expect, it } from 'vitest';
import {
    configureCurrencies,
    currencySymbol,
    formatMoney,
    formatMoneyCompact,
    formatNumberCompact,
    labelWithCurrency,
    parseMoneyInput,
    toMinor,
} from '../money';
import type { CurrencyFormat } from '../money';

describe('parseMoneyInput', () => {
    it.each([
        ['12', 1200],
        ['12.5', 1250],
        ['12.50', 1250],
        ['0.99', 99],
        ['1,299.10', 129910],
        ['1,00,000.50', 10000050],
        ['-5.25', -525],
        ['  42 ', 4200],
    ])('accepts %s', (input, expected) => {
        expect(parseMoneyInput(input)).toBe(expected);
    });

    it.each([
        '',
        'abc',
        '12,50',
        '1.2.3',
        '1.999',
        '$12',
        '12a',
        '1,2,3',
        ',100',
        '--1',
        'NaN',
        '1e3',
    ])('rejects %s', (input) => {
        expect(parseMoneyInput(input)).toBeNull();
    });
});

describe('toMinor', () => {
    it('rounds numbers and flags invalid strings as NaN', () => {
        expect(toMinor(19.99)).toBe(1999);
        expect(toMinor('abc')).toBeNaN();
        expect(toMinor(Number.POSITIVE_INFINITY)).toBeNaN();
    });
});

const SEEDED: CurrencyFormat[] = [
    { code: 'USD', prefix: '$', suffix: '', decimals: 2, format: '1,234.56' },
    {
        code: 'INR',
        prefix: '₹',
        suffix: '',
        decimals: 2,
        format: '1,23,456.78',
    },
    {
        code: 'NPR',
        prefix: 'Rs ',
        suffix: '',
        decimals: 2,
        format: '1,23,456.78',
    },
];

describe('formatMoney', () => {
    afterEach(() => configureCurrencies([]));

    it('uses Indian grouping for INR in en-IN', () => {
        expect(formatMoney(10000050, 'INR', 'en-IN')).toBe('₹1,00,000.50');
    });

    it('formats each configured currency with its symbol and grouping', () => {
        configureCurrencies(SEEDED);

        expect(formatMoney(123450, 'USD')).toBe('$1,234.50');
        expect(formatMoney(123456700, 'USD')).toBe('$1,234,567.00');
        expect(formatMoney(12345600, 'INR')).toBe('₹1,23,456.00');
        expect(formatMoney(12345600, 'NPR')).toBe('Rs 1,23,456.00');
        expect(formatMoney(1234567800, 'npr')).toBe('Rs 1,23,45,678.00');
        expect(formatMoney(5, 'NPR')).toBe('Rs 0.05');
    });

    it('puts a true minus before the symbol and signs credits when asked', () => {
        configureCurrencies(SEEDED);

        expect(formatMoney(-120000, 'NPR')).toBe('−Rs 1,200.00');
        expect(formatMoney(-1200, 'USD')).toBe('−$12.00');
        expect(formatMoney(1200, 'USD', undefined, { signed: true })).toBe(
            '+$12.00',
        );
        expect(formatMoney(0, 'USD', undefined, { signed: true })).toBe(
            '$0.00',
        );
    });

    it('follows a customised prefix, suffix and separators', () => {
        configureCurrencies([
            {
                code: 'NPR',
                prefix: 'रू ',
                suffix: '',
                decimals: 2,
                format: '1,23,456.78',
            },
            {
                code: 'EUR',
                prefix: '',
                suffix: ' €',
                decimals: 2,
                format: '1.234,56',
            },
            {
                code: 'CHF',
                prefix: 'CHF ',
                suffix: '',
                decimals: 2,
                format: '1234.56',
            },
        ]);

        expect(formatMoney(12345600, 'NPR')).toBe('रू 1,23,456.00');
        expect(formatMoney(123456789, 'EUR')).toBe('1.234.567,89 €');
        expect(formatMoney(123456789, 'CHF')).toBe('CHF 1234567.89');
        expect(currencySymbol('EUR')).toBe('€');
    });

    it('falls back to the Intl narrow symbol for currencies that are not configured', () => {
        configureCurrencies(SEEDED);

        expect(formatMoney(123450, 'GBP')).toBe('£1,234.50');
        expect(formatMoney(-500, 'AUD')).toBe('−$5.00');
        expect(currencySymbol('GBP')).toBe('£');
    });

    it('appends the code only when asked and never twice', () => {
        configureCurrencies(SEEDED);

        expect(formatMoney(100, 'USD', undefined, { showCode: true })).toBe(
            '$1.00 USD',
        );
    });

    it('keeps the configured symbol in compact amounts', () => {
        configureCurrencies(SEEDED);

        expect(formatMoneyCompact(4800000, 'USD')).toBe('$48K');
        expect(formatMoneyCompact(120000000, 'NPR')).toBe('Rs 12L');
    });

    it('uses our own compact suffixes, never locale abbreviations', () => {
        configureCurrencies(SEEDED);

        // Indian grouping: K (thousand), L (lakh), Cr (crore).
        expect(formatMoneyCompact(200000, 'INR')).toBe('₹2K');
        expect(formatMoneyCompact(117000000, 'INR')).toBe('₹11.7L');
        expect(formatMoneyCompact(75000000, 'NPR')).toBe('Rs 7.5L');
        expect(formatMoneyCompact(1200000000, 'INR')).toBe('₹1.2Cr');
        expect(formatMoneyCompact(9996000, 'INR')).toBe('₹1L');
        expect(formatMoneyCompact(-250000, 'NPR')).toBe('−Rs 2.5K');
        expect(formatMoneyCompact(95000, 'INR')).toBe('₹950');
        // Everything else: K, M, B.
        expect(formatMoneyCompact(120000000, 'USD')).toBe('$1.2M');
        expect(formatMoneyCompact(350000000000, 'USD')).toBe('$3.5B');
        expect(formatMoneyCompact(0, 'USD')).toBe('$0');
        // Unconfigured currencies follow the same rules.
        expect(formatMoneyCompact(4800000, 'GBP')).toBe('£48K');
        expect(formatMoneyCompact(117000000, 'PKR', 'en-IN')).toMatch(
            /11\.7L$/,
        );
    });

    it('ignores a missing shared prop instead of clearing the configuration', () => {
        configureCurrencies(SEEDED);
        configureCurrencies(undefined);

        expect(formatMoney(100, 'NPR')).toBe('Rs 1.00');
    });
});

describe('labelWithCurrency', () => {
    afterEach(() => configureCurrencies([]));

    it('names the symbol and the code for amount inputs', () => {
        configureCurrencies(SEEDED);

        expect(labelWithCurrency('Amount', 'NPR')).toBe('Amount (Rs, NPR)');
        expect(labelWithCurrency('Amount', 'usd')).toBe('Amount ($, USD)');
        expect(labelWithCurrency('Amount', 'XYZ')).toBe('Amount (XYZ)');
    });
});

describe('formatNumberCompact', () => {
    it('compacts counts with the product units, never Intl abbreviations', () => {
        expect(formatNumberCompact(950)).toBe('950');
        expect(formatNumberCompact(1200)).toBe('1.2K');
        expect(formatNumberCompact(3_400_000)).toBe('3.4M');
        expect(formatNumberCompact(-2500)).toBe('−2.5K');
    });
});
