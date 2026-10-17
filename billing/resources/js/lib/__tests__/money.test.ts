import { describe, expect, it } from 'vitest';
import { formatMoney, parseMoneyInput, toMinor } from '../money';

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

describe('formatMoney', () => {
    it('uses Indian grouping for INR in en-IN', () => {
        expect(formatMoney(10000050, 'INR', 'en-IN')).toBe('₹1,00,000.50');
    });
});
