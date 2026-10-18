// Renders the brand SVGs (public/brand) to 2x transparent PNGs for email (Gmail and Outlook do not render SVG).
// Usage: node make-logos.mjs   (writes ../assets/logo-{light,dark,on-accent}.png and mark-*.png)
import { chromium } from '/workspaces/whmcs/.worktrees/_review/pw/node_modules/playwright/index.mjs';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url));
const brand = '/workspaces/whmcs/billing/public/brand';
const out = path.join(here, '..', 'assets');
const light = fs.readFileSync(`${brand}/logo.svg`, 'utf8');
const dark = fs.readFileSync(`${brand}/logo-dark.svg`, 'utf8');
// Light logo with a thin white halo on the wordmark: invisible on white, but it keeps the dark wordmark
// readable when Gmail/Outlook force-invert the background and leave the image untouched.
const lightHalo = light.replace(
    'fill="#1C1B33"/>',
    'fill="#1C1B33" stroke="#FFFFFF" stroke-width="1.4" stroke-linejoin="round" paint-order="stroke"/>',
);
const mark = fs.readFileSync(`${brand}/mark.svg`, 'utf8');
const markDark = mark
    .replace('fill="#4F4BC4"/>', 'fill="#8B8FEA"/>')
    .replace(
        '<circle cx="16" cy="16" r="9.5" fill="#FFFFFF"/>',
        '<circle cx="16" cy="16" r="9.5" fill="#15143A"/>',
    )
    .replace('stroke="#4F4BC4"', 'stroke="#8B8FEA"');
// Mark for the accent band (direction B): white tile, accent glyph. Paired with the brand name as live text,
// which inverts together with the band in clients that force-invert (an all-white image would vanish).
const markOnAccent = mark
    .replace(
        '<rect width="32" height="32" rx="8" fill="#4F4BC4"/>',
        '<rect width="32" height="32" rx="8" fill="#FFFFFF"/>',
    )
    .replace(
        '<circle cx="16" cy="16" r="9.5" fill="#FFFFFF"/>',
        '<circle cx="16" cy="16" r="9.5" fill="#4F4BC4"/>',
    )
    .replace('stroke="#4F4BC4"', 'stroke="#FFFFFF"');
const browser = await chromium.launch();
const page = await browser.newPage({ deviceScaleFactor: 2 });
for (const [name, svg] of [
    ['logo-light', lightHalo],
    ['logo-dark', dark],
    ['mark-light', mark],
    ['mark-dark', markDark],
    ['mark-on-accent', markOnAccent],
]) {
    await page.setContent(
        `<html><body style="margin:0;background:transparent">${svg}</body></html>`,
    );
    await page.locator('svg').screenshot({
        path: path.join(out, `${name}.png`),
        omitBackground: true,
    });
}
await browser.close();
