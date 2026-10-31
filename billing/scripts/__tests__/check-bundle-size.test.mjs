import { describe, expect, it } from 'vitest';
import {
    breaches,
    CHUNK_BUDGET_KB,
    ENTRY_BUDGET_KB,
} from '../check-bundle-size.mjs';

describe('breaches', () => {
    it('holds the entry to its own, lower budget', () => {
        const rows = [
            { file: 'assets/app.js', entry: true, gzipKb: ENTRY_BUDGET_KB + 1 },
            {
                file: 'assets/chart.js',
                entry: false,
                gzipKb: ENTRY_BUDGET_KB + 1,
            },
        ];

        expect(breaches(rows)).toEqual([
            expect.stringContaining('entry assets/app.js'),
        ]);
    });

    it('flags a chunk over the chunk budget only', () => {
        const rows = [
            { file: 'assets/a.js', entry: false, gzipKb: CHUNK_BUDGET_KB },
            {
                file: 'assets/b.js',
                entry: false,
                gzipKb: CHUNK_BUDGET_KB + 0.1,
            },
        ];

        expect(breaches(rows)).toEqual([
            expect.stringContaining('chunk assets/b.js'),
        ]);
    });
});
