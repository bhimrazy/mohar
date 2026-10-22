import { describe, expect, it } from 'vitest';
import { countryName } from '../country';

describe('countryName', () => {
    it('names a country code', () => {
        expect(countryName('NP')).toBe('Nepal');
        expect(countryName('us')).toBe('United States');
    });

    it('keeps what it cannot name and passes null through', () => {
        expect(countryName('not a code')).toBe('not a code');
        expect(countryName(null)).toBeNull();
        expect(countryName('')).toBeNull();
    });
});
