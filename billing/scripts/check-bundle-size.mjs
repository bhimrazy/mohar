// Bundle budget (audit L7): gzip size of the entry and of every JS chunk in public/build, from manifest.json.
// Reports by default and exits 0; `--enforce` (or BUNDLE_BUDGET_ENFORCE=1) exits 1 on a breach. CI reports first and
// enforces once the two known large chunks (chart, dist) have been split or accepted.
import { readFileSync, realpathSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

export const ENTRY_BUDGET_KB = 80;
export const CHUNK_BUDGET_KB = 150;

/** @returns {{file: string, entry: boolean, gzipKb: number}[]} JS files in the manifest, largest first. */
export function measure(buildDir) {
    const manifest = JSON.parse(
        readFileSync(join(buildDir, 'manifest.json'), 'utf8'),
    );
    const seen = new Set();
    const rows = [];

    for (const item of Object.values(manifest)) {
        if (!item.file.endsWith('.js') || seen.has(item.file)) {
            continue;
        }

        seen.add(item.file);
        rows.push({
            file: item.file,
            entry: item.isEntry === true,
            gzipKb:
                gzipSync(readFileSync(join(buildDir, item.file))).length / 1024,
        });
    }

    return rows.sort((a, b) => b.gzipKb - a.gzipKb);
}

/** @returns {string[]} one message per breach. */
export function breaches(rows) {
    return rows
        .filter((r) => r.gzipKb > (r.entry ? ENTRY_BUDGET_KB : CHUNK_BUDGET_KB))
        .map(
            (r) =>
                `${r.entry ? 'entry' : 'chunk'} ${r.file}: ${r.gzipKb.toFixed(1)} kB gzip > ${r.entry ? ENTRY_BUDGET_KB : CHUNK_BUDGET_KB} kB`,
        );
}

// import.meta.url is the real path; argv[1] is not (symlinked checkouts, macOS /var), and must be URL-escaped.
if (
    process.argv[1] &&
    import.meta.url === pathToFileURL(realpathSync(process.argv[1])).href
) {
    const enforce =
        process.argv.includes('--enforce') ||
        process.env.BUNDLE_BUDGET_ENFORCE === '1';
    const rows = measure(process.env.BUNDLE_DIR ?? 'public/build');
    const total = rows.reduce((sum, r) => sum + r.gzipKb, 0);

    console.log(
        `${rows.length} JS files, ${total.toFixed(0)} kB gzip in total`,
    );
    for (const r of rows.slice(0, 5)) {
        console.log(
            `  ${r.gzipKb.toFixed(1).padStart(7)} kB  ${r.entry ? 'entry ' : 'chunk '}${r.file}`,
        );
    }

    const found = breaches(rows);
    for (const message of found) {
        console.log(
            `${enforce ? '::error::' : '::warning::'}Over budget: ${message}`,
        );
    }

    process.exit(enforce && found.length > 0 ? 1 : 0);
}
