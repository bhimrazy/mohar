import { describe, expect, it } from 'vitest';
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
