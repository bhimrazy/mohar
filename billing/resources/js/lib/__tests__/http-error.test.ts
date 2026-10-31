import { describe, expect, it } from 'vitest';
import { failureMessage } from '../http-error';

const failed = (status: number) => ({ response: { status, data: '{}' } });

describe('failureMessage', () => {
    it('tells the user to check their connection when no answer came back', () => {
        expect(failureMessage(new Error('Network Error'))).toContain(
            'connection',
        );
        expect(failureMessage(null)).toContain('connection');
    });

    it('tells the user to reload when the session expired (419)', () => {
        expect(failureMessage(failed(419))).toBe(
            'Your session expired. Reload the page and try again.',
        );
    });

    it('tells the user to wait when rate limited (429)', () => {
        expect(failureMessage(failed(429))).toContain('Wait');
    });

    it.each([500, 502, 503])('names a server-side problem for %i', (status) => {
        expect(failureMessage(failed(status))).toContain('our side');
    });

    it('never names a product', () => {
        for (const status of [419, 429, 500]) {
            expect(failureMessage(failed(status))).not.toMatch(
                /mohar|laravel/i,
            );
        }
    });
});
