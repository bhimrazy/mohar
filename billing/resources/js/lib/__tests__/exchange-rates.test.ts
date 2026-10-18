import { afterEach, describe, expect, it } from 'vitest';
import { daysSince, ratePair, roundRate } from '../exchange-rates';
import { configureCurrencies } from '../money';

describe('exchange-rate display', () => {
    afterEach(() => configureCurrencies([]));

    it('rounds to at most six decimals, keeping at least two', () => {
        expect(roundRate('151.940000000000')).toBe('151.94');
        expect(roundRate('94.964898249356')).toBe('94.964898');
        expect(roundRate('0.006581545347')).toBe('0.006582');
        expect(roundRate('9.9999996')).toBe('10.00');
        expect(roundRate('1.000000000000')).toBe('1.00');
    });

    it('shows one default unit in the quoted currency', () => {
        configureCurrencies([
            {
                code: 'USD',
                prefix: '$',
                suffix: '',
                decimals: 2,
                format: '1,234.56',
            },
            {
                code: 'NPR',
                prefix: 'Rs ',
                suffix: '',
                decimals: 2,
                format: '1,23,456.78',
            },
        ]);

        expect(ratePair('151.940000000000', 'USD', 'NPR')).toBe(
            '$1 = Rs 151.94',
        );
        expect(ratePair('0.006581545347', 'NPR', 'USD')).toBe(
            'Rs 1 = $0.006582',
        );
        expect(ratePair('123456.5', 'USD', 'NPR')).toBe('$1 = Rs 1,23,456.50');
    });

    it('counts whole days since a timestamp', () => {
        expect(
            daysSince('2026-10-06T09:00:00Z', new Date('2026-10-18T10:00:00Z')),
        ).toBe(12);
        expect(daysSince(null)).toBeNull();
    });
});
