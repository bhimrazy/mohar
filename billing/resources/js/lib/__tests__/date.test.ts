import { afterEach, describe, expect, it } from 'vitest';
import { formatDate, formatWithPattern, setDateFormat } from '../date';

afterEach(() => setDateFormat(null));

describe('formatWithPattern', () => {
    const date = new Date('2026-03-04T00:00:00Z');

    it.each([
        ['M j, Y', 'Mar 4, 2026'],
        ['j M Y', '4 Mar 2026'],
        ['Y-m-d', '2026-03-04'],
        ['d/m/Y', '04/03/2026'],
        ['m/d/Y', '03/04/2026'],
        ['d.m.Y', '04.03.2026'],
    ])('formats %s', (pattern, expected) => {
        expect(formatWithPattern(date, pattern, true)).toBe(expected);
    });
});

describe('formatDate with the date format setting', () => {
    it('uses the configured pattern for calendar dates', () => {
        setDateFormat('d/m/Y');

        expect(formatDate('2026-10-24')).toBe('24/10/2026');
    });

    it('keeps the time for date-times', () => {
        setDateFormat('Y-m-d');

        expect(formatDate('2026-10-24T09:30:00Z', 'datetime')).toMatch(
            /^2026-10-2[45], \d{1,2}:\d{2}/,
        );
    });

    it('falls back to the locale format without a setting', () => {
        expect(formatDate('2026-10-24')).toBe('Oct 24, 2026');
    });

    it.each(['YYYY-MM-DD', 'F j, Y', 'Y'])(
        'ignores %s, which is not a supported format',
        (format) => {
            setDateFormat(format);

            expect(formatDate('2026-10-24')).toBe('Oct 24, 2026');
        },
    );

    it('leaves times alone', () => {
        setDateFormat('d/m/Y');

        expect(formatDate('2026-10-24T09:30:00Z', 'time')).not.toContain('/');
    });
});
