// Width comparison: renders directions/<d>/width/invoice-issued-<w>.html inside the webmail pane at 1280px
// and measures body-text characters per line. Usage: node width.mjs
import { chromium } from '/workspaces/whmcs/.worktrees/_review/pw/node_modules/playwright/index.mjs';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(here, '..');
const SAMPLE =
    'Your invoice for the renewals starting next month is ready, and you can pay it online by UPI, card or net banking before the due date so that your server, hosting and domain keep running without a break. '.repeat(
        16,
    );
const browser = await chromium.launch();
const rows = [];
for (const d of ['a', 'b', 'c']) {
    for (const w of [600, 640, 680]) {
        const file = path.join(
            root,
            'directions',
            d,
            'width',
            `invoice-issued-${w}.html`,
        );
        const ctx = await browser.newContext({
            viewport: { width: 1280, height: 900 },
        });
        const page = await ctx.newPage();
        await page.goto('file://' + path.join(here, 'webmail-pane.html'));
        await page.evaluate(
            ([html, base]) => window.load(html, base),
            [
                fs.readFileSync(file, 'utf8'),
                'file://' + path.dirname(file) + '/',
            ],
        );
        await page.waitForFunction(() => window.__ready === true);
        const m = await page
            .frameLocator('#f')
            .locator('body')
            .evaluate((body, sample) => {
                const ps = [...body.querySelectorAll('p.tx-body')].filter(
                    (p) => getComputedStyle(p).fontSize === '16px',
                );
                const p = ps[ps.length - 1];
                const clone = p.cloneNode();
                clone.textContent = sample;
                p.after(clone);
                const lines = Math.round(
                    clone.getBoundingClientRect().height /
                        parseFloat(getComputedStyle(clone).lineHeight),
                );
                const width = Math.round(clone.getBoundingClientRect().width);
                clone.remove();
                const btn = body
                    .querySelector('a.btn-a')
                    .getBoundingClientRect();
                return {
                    textWidth: width,
                    cpl: Math.round(sample.length / lines),
                    button: `${Math.round(btn.width)}×${Math.round(btn.height)}`,
                };
            }, SAMPLE);
        rows.push({ dir: d, width: w, ...m });
        await page.screenshot({
            path: path.join(
                root,
                'screenshots',
                `${d}-invoice-issued-width-${w}-gmail-light.png`,
            ),
            fullPage: true,
        });
        await ctx.close();
    }
}
await browser.close();
console.table(rows);
fs.writeFileSync(
    path.join(here, 'width-report.json'),
    JSON.stringify(rows, null, 1),
);
