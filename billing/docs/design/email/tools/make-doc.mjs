// Renders the small document thumbnail used in direction A (our own drawing): ../assets/doc-{light,dark}.png
import { chromium } from '/workspaces/whmcs/.worktrees/_review/pw/node_modules/playwright/index.mjs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const out = path.join(
    path.dirname(fileURLToPath(import.meta.url)),
    '..',
    'assets',
);
const svg = (
    page,
    stroke,
    strong,
    faint,
) => `<svg xmlns="http://www.w3.org/2000/svg" width="56" height="70" viewBox="0 0 56 70">
<path d="M5 1.5h34l15.5 15.5v47.5a4 4 0 0 1-4 4H5a4 4 0 0 1-4-4v-59a4 4 0 0 1 4-4z" fill="${page}" stroke="${stroke}" stroke-width="1.5"/>
<path d="M39 1.5v11.5a4 4 0 0 0 4 4h11.5" fill="none" stroke="${stroke}" stroke-width="1.5"/>
<rect x="9" y="12" width="18" height="4" rx="2" fill="${strong}"/>
<rect x="9" y="26" width="38" height="3" rx="1.5" fill="${faint}"/><rect x="9" y="33" width="30" height="3" rx="1.5" fill="${faint}"/>
<rect x="9" y="40" width="34" height="3" rx="1.5" fill="${faint}"/>
<rect x="9" y="50" width="38" height="1.5" fill="${faint}"/><rect x="29" y="56" width="18" height="4" rx="2" fill="${strong}"/></svg>`;
const browser = await chromium.launch();
const page = await browser.newPage({ deviceScaleFactor: 2 });
for (const [name, s] of [
    ['doc-light', svg('#F7F7F5', '#D3D2CC', '#BDBCB5', '#E2E1DC')],
    ['doc-dark', svg('#222632', '#3A4152', '#4F576B', '#323848')],
]) {
    await page.setContent(
        `<html><body style="margin:0;background:transparent">${s}</body></html>`,
    );
    await page.locator('svg').screenshot({
        path: path.join(out, `${name}.png`),
        omitBackground: true,
    });
}
await browser.close();
