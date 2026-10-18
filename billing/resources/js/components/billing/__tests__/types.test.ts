import { describe, expect, it } from 'vitest';
import { formatMoney, formatMoneyCompact } from '@/lib/money';
import { normalizeMoney, withMoney } from '../types';

describe('normalizeMoney', () => {
    it('canonicalises well-formed amounts', () => {
        expect(normalizeMoney('1,299.1')).toBe('1299.10');
        expect(normalizeMoney('1,00,000.50')).toBe('100000.50');
        expect(normalizeMoney(' -5 ')).toBe('-5.00');
    });

    it('passes invalid input through for the server to reject', () => {
        expect(normalizeMoney('12,50')).toBe('12,50');
        expect(normalizeMoney('abc')).toBe('abc');
    });
});

describe('withMoney', () => {
    it('normalises only the listed non-empty fields', () => {
        expect(
            withMoney({ amount: '1,000', fee: '', note: '1,000' }, [
                'amount',
                'fee',
            ]),
        ).toEqual({ amount: '1000.00', fee: '', note: '1,000' });
    });
});

describe('formatMoney default locale per currency', () => {
    it('groups INR and NPR in lakhs and others in thousands', () => {
        expect(formatMoney(123456789, 'INR')).toBe('₹12,34,567.89');
        expect(formatMoney(123456789, 'USD')).toBe('$1,234,567.89');
    });
});

describe('formatMoney symbols and compact amounts', () => {
    it('prints the Nepalese rupee as रू with lakh grouping', () => {
        expect(formatMoney(78680000, 'NPR')).toBe('रू 7,86,800.00');
        expect(formatMoney(-50000, 'NPR')).toBe('−रू 500.00');
    });

    it('compacts axis labels in each currency own grouping', () => {
        expect(formatMoneyCompact(4500000, 'USD')).toBe('$45K');
        expect(formatMoneyCompact(116720000, 'INR')).toBe('₹11.7L');
        expect(formatMoneyCompact(78680000, 'NPR')).toBe('रू 7.9L');
    });
});
