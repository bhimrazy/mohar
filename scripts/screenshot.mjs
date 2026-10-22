#!/usr/bin/env node
// Headless screenshot of a running Mohar instance, using the globally installed Playwright
// (npm i -g playwright@<pinned> && playwright install chromium; billing/docs/devops/local-setup.md).
//   node scripts/screenshot.mjs <url> <out.png> [--width 1440] [--height 900] [--dark] [--full]
//        [--login admin@example.com:password]
// --login signs in through /login on the same origin first. Width 390 gives the phone layout.
import { createRequire } from 'node:module';
import { execSync } from 'node:child_process';
import path from 'node:path';

const require = createRequire(import.meta.url);
const globalRoot = execSync('npm root -g').toString().trim();
const { chromium } = require(path.join(globalRoot, 'playwright'));

const args = process.argv.slice(2);
const flag = (name, fallback) => {
    const i = args.indexOf(`--${name}`);
    return i === -1 ? fallback : args[i + 1];
};
const [url, out] = args.filter((a, i) => !a.startsWith('--') && !args[i - 1]?.match(/^--(width|height|login)$/));
if (!url || !out) {
    console.error('usage: screenshot.mjs <url> <out.png> [--width N] [--height N] [--dark] [--full] [--login email:password]');
    process.exit(2);
}
const dark = args.includes('--dark');
const width = Number(flag('width', 1440));
const height = Number(flag('height', 900));

let browser;
try {
    browser = await chromium.launch();
} catch (error) {
    if (String(error).includes("Executable doesn't exist")) {
        console.error('Chromium for this Playwright version is missing: run `playwright install chromium`.');
        process.exit(1);
    }
    throw error;
}
try {
    const context = await browser.newContext({ viewport: { width, height }, colorScheme: dark ? 'dark' : 'light' });
    // The app reads the theme from localStorage ('appearance'); set it before any page script runs.
    await context.addInitScript((mode) => localStorage.setItem('appearance', mode), dark ? 'dark' : 'light');
    const page = await context.newPage();
    const login = flag('login');
    if (login) {
        const [email, ...rest] = login.split(':');
        await page.goto(new URL('/login', url).href);
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', rest.join(':'));
        await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login')), page.click('button[type="submit"]')]);
    }
    await page.goto(url, { waitUntil: 'networkidle' });
    await page.screenshot({ path: out, fullPage: args.includes('--full') });
    console.log(`${out} (${width}x${height}${dark ? ', dark' : ''})`);
} finally {
    await browser.close();
}
