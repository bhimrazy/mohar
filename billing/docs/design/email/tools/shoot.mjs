// Screenshots and layout checks for the reference emails.
// Usage: node shoot.mjs [a b c]        (run through scripts/heavy.sh)
// Writes ../screenshots/<dir>-<email>-<variant>.png and prints an overflow report as JSON to stdout.
//
// Variants per direction × email:
//   desktop-light / desktop-dark / desktop-inverted   720px viewport (640px email + frame)
//   mobile-light  / mobile-dark  / mobile-inverted    375px viewport, 2x
//   320-light                                          320px viewport, 2x
// Extra: invoice-issued wide-light (1024px), images-off-light (desktop), gmail-light (webmail pane),
//        no-styles-mobile-light (<style> removed: no media queries, no dark rules);
//        invoice-issued-long 320-light and desktop-light (stress sample).
// "dark" = our prefers-color-scheme styles. "inverted" = forced inversion approximating Gmail apps and
// Outlook desktop: invert(1) hue-rotate(180deg) on the page, images re-inverted (those clients leave images alone).
import { chromium } from '/workspaces/whmcs/.worktrees/_review/pw/node_modules/playwright/index.mjs';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(here, '..');
const out = path.join(root, 'screenshots');
const dirs = process.argv.slice(2).length
    ? process.argv.slice(2)
    : ['a', 'b', 'c'];
const EMAILS = [
    'invoice-issued',
    'payment-receipt',
    'overdue-reminder',
    'password-reset',
    'ticket-reply',
];
const INVERT =
    'html{background:#fff !important;filter:invert(1) hue-rotate(180deg) !important}img{filter:invert(1) hue-rotate(180deg) !important}';

const browser = await chromium.launch();
const report = [];

async function overflow(page) {
    return page.evaluate(() => {
        const vw = document.documentElement.clientWidth;
        const bad = [];
        for (const el of document.querySelectorAll('body *')) {
            const r = el.getBoundingClientRect();
            if (r.width === 0 || getComputedStyle(el).display === 'none')
                continue;
            if (r.right > vw + 0.5 || r.left < -0.5)
                bad.push(
                    `${el.tagName.toLowerCase()}.${el.className || ''} → ${Math.round(r.left)}..${Math.round(r.right)}`,
                );
        }
        const btn = [...document.querySelectorAll('a.btn-a')].map((a) =>
            Math.round(a.getBoundingClientRect().height),
        );
        const btnW = [...document.querySelectorAll('a.btn-a')].map((a) =>
            Math.round(a.getBoundingClientRect().width),
        );
        return {
            vw,
            scrollW: document.documentElement.scrollWidth,
            bad: bad.slice(0, 5),
            buttonHeights: btn,
            buttonWidths: btnW,
        };
    });
}

async function shot(
    file,
    name,
    {
        width,
        scheme = 'light',
        invert = false,
        dpr = 1,
        images = true,
        check = true,
    },
) {
    const ctx = await browser.newContext({
        viewport: { width, height: 900 },
        colorScheme: scheme,
        deviceScaleFactor: dpr,
    });
    const page = await ctx.newPage();
    if (!images) await page.route('**/*.png', (r) => r.abort());
    await page.goto('file://' + file);
    if (invert) await page.addStyleTag({ content: INVERT });
    await page.waitForTimeout(100);
    if (check)
        report.push({
            file: path.relative(root, file),
            name,
            width,
            scheme,
            invert,
            ...(await overflow(page)),
        });
    if (name)
        await page.screenshot({
            path: path.join(out, `${name}.png`),
            fullPage: true,
        });
    await ctx.close();
}

for (const d of dirs) {
    for (const e of EMAILS) {
        const file = path.join(root, 'directions', d, `${e}.html`);
        const n = `${d}-${e}`;
        await shot(file, `${n}-desktop-light`, { width: 720 });
        await shot(file, `${n}-desktop-dark`, { width: 720, scheme: 'dark' });
        await shot(file, `${n}-desktop-inverted`, {
            width: 720,
            invert: true,
            check: false,
        });
        await shot(file, `${n}-mobile-light`, { width: 375, dpr: 2 });
        await shot(file, `${n}-mobile-dark`, {
            width: 375,
            dpr: 2,
            scheme: 'dark',
        });
        await shot(file, `${n}-mobile-inverted`, {
            width: 375,
            dpr: 2,
            invert: true,
            check: false,
        });
        await shot(file, `${n}-320-light`, { width: 320, dpr: 2 });
        await shot(file, null, { width: 414 });
        await shot(file, null, { width: 600 });
        await shot(file, null, { width: 1024 });
    }
    const inv = path.join(root, 'directions', d, 'invoice-issued.html');
    await shot(inv, `${d}-invoice-issued-wide-light`, { width: 1024 });
    await shot(inv, `${d}-invoice-issued-images-off-light`, {
        width: 720,
        images: false,
    });
    // Styles stripped (Gmail with non-Google accounts, some webmail): inline styles only, no media queries.
    {
        const ctx = await browser.newContext({
            viewport: { width: 375, height: 900 },
            deviceScaleFactor: 2,
        });
        const page = await ctx.newPage();
        await page.goto('file://' + inv);
        const html = fs
            .readFileSync(inv, 'utf8')
            .replace(/<style>[\s\S]*?<\/style>/g, '');
        await page.setContent(
            html.replace(
                '<head>',
                `<head><base href="file://${path.dirname(inv)}/">`,
            ),
            { waitUntil: 'load' },
        );
        report.push({
            file: path.relative(root, inv),
            name: 'no-styles',
            width: 375,
            ...(await overflow(page)),
        });
        await page.screenshot({
            path: path.join(
                out,
                `${d}-invoice-issued-no-styles-mobile-light.png`,
            ),
            fullPage: true,
        });
        await ctx.close();
    }
    const long = path.join(root, 'directions', d, 'invoice-issued-long.html');
    await shot(long, `${d}-invoice-issued-long-320-light`, {
        width: 320,
        dpr: 2,
    });
    await shot(long, `${d}-invoice-issued-long-desktop-light`, { width: 720 });
    await shot(long, null, { width: 375 });
    await shot(long, null, { width: 414 });

    // Webmail-style reading pane (generic chrome, not a real client): light mode, 1280px window.
    const ctx = await browser.newContext({
        viewport: { width: 1280, height: 900 },
        colorScheme: 'light',
    });
    const page = await ctx.newPage();
    await page.goto('file://' + path.join(here, 'webmail-pane.html'));
    await page.evaluate(
        ([html, base]) => window.load(html, base, 'Invoice-INV-2026-0142.pdf'),
        [fs.readFileSync(inv, 'utf8'), 'file://' + path.dirname(inv) + '/'],
    );
    await page.waitForFunction(() => window.__ready === true);
    await page.screenshot({
        path: path.join(out, `${d}-invoice-issued-gmail-light.png`),
        fullPage: true,
    });
    await ctx.close();
}

await browser.close();
fs.writeFileSync(
    path.join(here, `layout-report-${dirs.join('')}.json`),
    JSON.stringify(report, null, 1),
);
const fails = report.filter((r) => r.scrollW > r.vw || r.bad.length);
console.log(JSON.stringify({ checked: report.length, fails }, null, 1));
