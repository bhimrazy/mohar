import { execFileSync } from 'node:child_process';
import { cpSync, mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
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

describe('running as a script', () => {
    it('detects the main module when the script path needs URL escaping', () => {
        const dir = mkdtempSync(join(tmpdir(), 'bundle size '));
        const script = join(dir, 'check-bundle-size.mjs');
        cpSync(new URL('../check-bundle-size.mjs', import.meta.url), script);
        mkdirSync(join(dir, 'build'));
        writeFileSync(join(dir, 'build', 'a.js'), 'x');
        writeFileSync(
            join(dir, 'build', 'manifest.json'),
            JSON.stringify({ a: { file: 'a.js', isEntry: true } }),
        );

        const output = execFileSync('node', [script], {
            env: { ...process.env, BUNDLE_DIR: join(dir, 'build') },
            encoding: 'utf8',
        });

        expect(output).toContain('1 JS files');
    });
});
