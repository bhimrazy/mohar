#!/usr/bin/env node
/**
 * Keep committed screenshots and design images small (docs/handoff/operating-model.md → Conventions).
 *
 *   node scripts/optimize-images.mjs [paths...]          optimise PNG/JPEG files in place (default: docs)
 *   node scripts/optimize-images.mjs --check [paths...]  fail when an image is larger than --max-kb (CI)
 *
 * PNGs are re-encoded as palette PNGs (quality 90, dithered), which is visually near-identical for UI
 * captures; JPEGs use mozjpeg at quality 82. Names and formats never change, so links keep working, and a
 * file is only replaced when the result is at least 10% smaller. `--check` reads file sizes only.
 *
 * Options: --max-kb=<n> (default 500), --min-saving=<0..1> (default 0.1).
 */
import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { extname, join, relative } from 'node:path';

const args = process.argv.slice(2);
const flags = Object.fromEntries(
    args
        .filter((arg) => arg.startsWith('--'))
        .map((arg) => {
            const [key, value] = arg.slice(2).split('=');

            return [key, value ?? true];
        }),
);
const roots = args.filter((arg) => !arg.startsWith('--'));
const maxBytes = Number(flags['max-kb'] ?? 500) * 1024;
const minSaving = Number(flags['min-saving'] ?? 0.1);
const extensions = new Set(['.png', '.jpg', '.jpeg']);

function* walk(path) {
    const stats = statSync(path);

    if (stats.isFile()) {
        if (extensions.has(extname(path).toLowerCase())) {
            yield path;
        }

        return;
    }

    for (const entry of readdirSync(path, { withFileTypes: true })) {
        if (entry.name === 'node_modules' || entry.name.startsWith('.')) {
            continue;
        }

        yield* walk(join(path, entry.name));
    }
}

const files = (roots.length > 0 ? roots : ['docs']).flatMap((root) => [
    ...walk(root),
]);
const kb = (bytes) => `${Math.round(bytes / 1024)} KB`;

if (flags.check) {
    const oversized = files.filter((file) => statSync(file).size > maxBytes);

    for (const file of oversized) {
        console.error(
            `${relative(process.cwd(), file)}: ${kb(statSync(file).size)} (limit ${kb(maxBytes)})`,
        );
    }

    if (oversized.length > 0) {
        console.error(
            `\n${oversized.length} image(s) over the limit. Run: npm run images:optimize -- <paths>`,
        );
        process.exit(1);
    }

    console.log(`${files.length} image(s) within ${kb(maxBytes)}.`);
    process.exit(0);
}

const { default: sharp } = await import('sharp');
let before = 0;
let after = 0;
let changed = 0;

for (const file of files) {
    const input = readFileSync(file);
    const isPng = extname(file).toLowerCase() === '.png';
    const output = await (
        isPng
            ? sharp(input).png({
                  palette: true,
                  quality: 90,
                  dither: 1,
                  effort: 10,
                  compressionLevel: 9,
              })
            : sharp(input).jpeg({ quality: 82, mozjpeg: true })
    ).toBuffer();

    before += input.length;

    if (output.length <= input.length * (1 - minSaving)) {
        writeFileSync(file, output);
        after += output.length;
        changed++;
    } else {
        after += input.length;
    }
}

console.log(
    `${changed} of ${files.length} image(s) optimised: ${kb(before)} → ${kb(after)}.`,
);
