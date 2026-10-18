// Builds the reference emails: directions/<a|b|c>/<email>.html and directions/plain-text/<email>.txt.
// Usage: node build.mjs
// Everything is table-based with inline styles; the <style> block carries only the mobile and dark-mode
// overrides (prefers-color-scheme for Apple Mail/iOS/Outlook apps, [data-ogsc]/[data-ogsb] for Outlook.com).
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { company, emails, stress } from './emails.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(here, '..');
let ASSET = '../../assets';
// Maximum content width (px) per direction, chosen for ~65–75 characters per line of body text (email-design.md §4).
// Fluid below it; fixed in an MSO table for Outlook desktop.
const WIDTHS = { a: 640, b: 600, c: 600 };
let W = 640;

// Calm Fintech tokens (billing/docs/design/directions.md), tuned for mail.
const L = {
    frame: '#F3F4F8',
    card: '#FFFFFF',
    panel: '#F7F8FB',
    border: '#E4E7EE',
    strong: '#1A2133',
    body: '#323A4E',
    muted: '#5B6478',
    link: '#4F4BC4',
    accent: '#4F4BC4',
    onAccent: '#FFFFFF',
    band: '#4F4BC4',
    bandMuted: '#E2E1F8',
    code: '#F1F2F6',
};
const D = {
    frame: '#0F121A',
    card: '#181C26',
    panel: '#1E2330',
    border: '#2A3040',
    strong: '#EEF0F5',
    body: '#C9CEDA',
    muted: '#9AA3B5',
    link: '#A9ACF0',
    accent: '#8B8FEA',
    onAccent: '#12142A',
    band: '#2E2C78',
    bandMuted: '#D4D3F5',
    code: '#12151D',
};
const TONES = {
    light: {
        accent: { dot: '#4F4BC4', text: '#3F3BA8', subtle: '#EEEDFB' },
        success: { dot: '#3D9A6E', text: '#23704D', subtle: '#EAF6F0' },
        warning: { dot: '#D6A03A', text: '#875A0E', subtle: '#FBF3E2' },
        danger: { dot: '#CF5A55', text: '#A6332F', subtle: '#FBEDEC' },
        info: { dot: '#4B8BC9', text: '#2B6297', subtle: '#EAF2FA' },
        muted: { dot: '#C5CAD6', text: '#5B6478', subtle: '#F1F2F6' },
    },
    dark: {
        accent: { dot: '#8B8FEA', text: '#B3B5F3', subtle: '#23244A' },
        success: { dot: '#5BBF8E', text: '#7FD1A8', subtle: '#172A22' },
        warning: { dot: '#E2B154', text: '#EBC27A', subtle: '#2D2619' },
        danger: { dot: '#E07D78', text: '#F09C97', subtle: '#32191B' },
        info: { dot: '#6FA8E0', text: '#94BFEA', subtle: '#17253A' },
        muted: { dot: '#3A4152', text: '#9AA3B5', subtle: '#232836' },
    },
};
export const TOKENS = { L, D, TONES };

const FONT =
    "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";
const MONO =
    "ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace";
const NUM = 'font-variant-numeric:tabular-nums;';

// ── Dark-mode CSS: one rule list, emitted for prefers-color-scheme and for Outlook.com's attributes ──────
function darkRules(extra = []) {
    const r = [
        ['.bg-frame', { 'background-color': D.frame }],
        ['.bg-card', { 'background-color': D.card }],
        ['.bg-panel', { 'background-color': D.panel }],
        ['.bg-code', { 'background-color': D.code }],
        ['.bg-band', { 'background-color': D.band }],
        ['.tx-strong', { color: D.strong }],
        ['.tx-body', { color: D.body }],
        ['.tx-muted', { color: D.muted }],
        ['.tx-band', { color: D.bandMuted }],
        ['.link', { color: D.link }],
        ['.bd', { 'border-color': D.border }],
        ['.btn-td', { 'background-color': D.accent }],
        [
            '.btn-a',
            {
                'background-color': D.accent,
                'border-color': D.accent,
                color: D.onAccent,
            },
        ],
    ];
    for (const [t, c] of Object.entries(TONES.dark)) {
        r.push([`.chip-${t}`, { 'background-color': c.subtle, color: c.text }]);
        r.push([`.tx-${t}`, { color: c.text }]);
        r.push([`.dot-${t}`, { 'background-color': c.dot }]);
        r.push([`.dotc-${t}`, { color: c.dot }]);
        r.push([`.bd-${t}`, { 'border-color': c.dot }]);
        r.push([`.sub-${t}`, { 'background-color': c.subtle }]);
    }
    return r.concat(extra);
}
function darkCss(extra) {
    const rules = darkRules(extra);
    const decl = (o) =>
        Object.entries(o)
            .map(([k, v]) => `${k}:${v} !important`)
            .join(';');
    const media = rules.map(([s, o]) => `${s}{${decl(o)}}`).join('\n');
    const swap =
        '.logo-l{display:none !important}.logo-d{display:block !important;max-height:none !important;overflow:visible !important}';
    // Outlook.com rewrites colours and marks elements with data-ogsc (text) / data-ogsb (background).
    const ogsc = rules
        .filter(([, o]) => o.color || o['border-color'])
        .map(
            ([s, o]) =>
                `[data-ogsc] ${s}{${decl(Object.fromEntries(Object.entries(o).filter(([k]) => k !== 'background-color')))}}`,
        )
        .join('\n');
    const ogsb = rules
        .filter(([, o]) => o['background-color'])
        .map(
            ([s, o]) =>
                `[data-ogsb] ${s}{background-color:${o['background-color']} !important}`,
        )
        .join('\n');
    return `@media (prefers-color-scheme: dark){\n${media}\n${swap}\n}\n${ogsc}\n${ogsb}\n[data-ogsc] .logo-l{display:none !important}[data-ogsc] .logo-d{display:block !important;max-height:none !important;overflow:visible !important}`;
}

const MOBILE = `@media only screen and (max-width:620px){
.frame-pad{padding:12px 10px 24px !important}
.px{padding-left:20px !important;padding-right:20px !important}
.stack{display:block !important;width:100% !important;max-width:100% !important}
.stack-gap{padding-top:10px !important}
.ta-l{text-align:left !important}
.amount{font-size:34px !important;line-height:40px !important}
.h1{font-size:22px !important;line-height:28px !important}
.btn-full{width:100% !important}
.btn-full .btn-a{display:block !important}
.hide-sm{display:none !important}
.cell-sm{padding-left:0 !important;padding-right:0 !important}
}
@media only screen and (max-width:420px){
.f-k,.f-v{display:block !important;width:100% !important;text-align:left !important}
.f-k{padding-bottom:0 !important;border-bottom:0 !important}
.f-v{padding-top:2px !important}
.amount{font-size:30px !important;line-height:36px !important}
}
@media only screen and (max-width:360px){
.frame-pad{padding:8px 6px 20px !important}
.px{padding-left:16px !important;padding-right:16px !important}
.amount{font-size:26px !important;line-height:32px !important}
}`;

// Mobile-first inline sizes (24px side padding, smaller amounts, no thumbnail) so clients that strip <style>
// still fit a 375px phone; desktop sizes come back through min-width media queries and, for Outlook desktop
// (no media queries), the MSO style block.
const DESKTOP_RULES = `.pxa{padding-left:48px !important;padding-right:48px !important}
.pxb{padding-left:32px !important;padding-right:32px !important}
.pxc{padding-left:36px !important;padding-right:36px !important}
.amt-a{font-size:48px !important;line-height:56px !important}
.amt-b{font-size:36px !important;line-height:44px !important}
.amt-c{font-size:44px !important;line-height:50px !important}`;
const DESKTOP = `@media only screen and (min-width:621px){
${DESKTOP_RULES}
.thumb{display:table-cell !important}
}`;
// Rewrites a direction's padded cells to the 24px inline default plus its desktop class.
function progressive(html, side, cls) {
    return html.replace(
        /class="([^"]*\bpx\b[^"]*)" (bgcolor="[^"]*" )?style="padding:([^ ;]+) ([^ ;]+)(?: ([^ ;]+))?;/g,
        (m, c, bg, t, r, b) => {
            if (r !== `${side}px`) return m;
            return `class="${c} ${cls}" ${bg || ''}style="padding:${t} 24px${b ? ` ${b}` : ''};`;
        },
    );
}

function shell({ e, css = '', body, frame = L.frame }) {
    const filler = '&#8199;&#65279;&#847; '.repeat(60);
    return `<!DOCTYPE html>
<html lang="en" dir="ltr" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>${e.subject}</title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
<style>table,td,th,p,a,span,div,h1,h2{font-family:Arial,Helvetica,sans-serif !important}
${DESKTOP_RULES}</style><![endif]-->
<style>
:root{color-scheme:light dark;supported-color-schemes:light dark}
body{margin:0;padding:0;width:100% !important;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
table{border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt}
img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic}
p{margin:0}
a.btn-a:hover{opacity:.92}
a:focus-visible{outline:2px solid #4F4BC4;outline-offset:2px}
${MOBILE}
${DESKTOP}
${darkCss()}
${css}
</style>
</head>
<body class="bg-frame" style="margin:0;padding:0;background-color:${frame};" xml:lang="en">
<div role="article" aria-roledescription="email" aria-label="${e.subject.replace(/"/g, '&quot;')}" lang="en" dir="ltr" class="bg-frame" style="background-color:${frame};font-family:${FONT};font-size:16px;line-height:24px;">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:${frame};opacity:0;">${e.preheader}${filler}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg-frame" style="background-color:${frame};">
<tr><td align="center" class="frame-pad" style="padding:32px 16px 40px;">
<!--[if mso]><table role="presentation" width="${W}" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<div style="max-width:${W}px;margin:0 auto;">
${body}
</div>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</div>
</body>
</html>
`;
}

// ── Primitives ────────────────────────────────────────────────────────────────────────────────────
const tl = (t) => TONES.light[t];

function logo({
    height = 32,
    onAccent = false,
    mark = false,
    alt: altOverride,
} = {}) {
    // Styled alt text so the brand still reads with images off.
    const w = mark ? height : Math.round((103 / 32) * height);
    // The small mark sits next to the brand name in text, so it is decorative (empty alt).
    const alt = altOverride ?? (mark ? '' : company.name);
    const img = (file, cls, style = '') =>
        `<img src="${ASSET}/${file}" width="${w}" height="${height}" alt="${alt}" class="${cls}" style="display:block;width:${w}px;height:${height}px;border:0;color:${onAccent ? '#FFFFFF' : L.strong};font-family:${FONT};font-size:${mark ? 12 : 18}px;font-weight:700;line-height:${height}px;${style}">`;
    if (onAccent) {
        return img('logo-on-accent.png', '');
    }
    const base = mark ? 'mark' : 'logo';
    return `${img(`${base}-light.png`, 'logo-l')}<!--[if !mso]><!--><div class="logo-d" style="display:none;max-height:0;overflow:hidden;mso-hide:all;">${img(`${base}-dark.png`, '')}</div><!--<![endif]-->`;
}

function chip(status) {
    const c = tl(status.tone);
    return `<span class="chip-${status.tone}" style="display:inline-block;padding:4px 12px 4px 10px;border-radius:999px;background-color:${c.subtle};color:${c.text};font-size:14px;line-height:20px;font-weight:600;white-space:nowrap;"><span class="dotc-${status.tone}" aria-hidden="true" style="color:${c.dot};font-size:12px;">&#9679;</span>&nbsp;${status.label}</span>`;
}

function button(cta, { full = false } = {}) {
    return `<table role="presentation" cellpadding="0" cellspacing="0" border="0" class="btn-full"${full ? ' width="100%"' : ''} style="${full ? 'width:100%;' : ''}">
<tr><td align="center" class="btn-td" bgcolor="${L.accent}" style="border-radius:8px;background-color:${L.accent};mso-padding-alt:14px 28px;">
<a href="${cta.url}" class="btn-a" target="_blank" rel="noopener" style="display:${full ? 'block' : 'inline-block'};padding:14px 28px;border:1px solid ${L.accent};border-radius:8px;background-color:${L.accent};color:${L.onAccent};font-family:${FONT};font-size:16px;line-height:20px;font-weight:600;text-align:center;text-decoration:none;mso-padding-alt:0;${NUM}">${cta.label}</a>
</td></tr></table>`;
}

const p = (
    html,
    { size = 16, line = 24, cls = 'tx-body', color = L.body, extra = '' } = {},
) =>
    `<p class="${cls}" style="margin:0;font-size:${size}px;line-height:${line}px;color:${color};${extra}">${html}</p>`;
const link = (l, { size = 15 } = {}) =>
    `<a href="${l.url}" class="link" target="_blank" rel="noopener" style="color:${L.link};font-size:${size}px;line-height:22px;font-weight:600;text-decoration:underline;">${l.label}</a>`;
const eyebrow = (text, { color = L.muted, cls = 'tx-muted' } = {}) =>
    `<p class="${cls}" style="margin:0;font-size:13px;line-height:18px;letter-spacing:.06em;text-transform:uppercase;font-weight:600;color:${color};">${text}</p>`;

// "[[Khalti]]" in a value renders a small method badge. In the app this is the gateway's official logo
// (Gateways module) or the card brand; here a neutral text badge stands in.
const rich = (v) =>
    v.replace(
        /\[\[([^\]]+)\]\]/g,
        (_, name) =>
            `<span class="bd tx-strong" style="display:inline-block;padding:0 6px;border:1px solid ${L.border};border-radius:4px;font-size:12px;line-height:18px;font-weight:700;letter-spacing:.03em;color:${L.strong};vertical-align:1px;">${name}</span>`,
    );

// Quiet text links: downloads (with a down arrow) and an optional secondary action.
function actionLinks(e, { align = 'left' } = {}) {
    const items = [];
    for (const d of e.downloads || [])
        items.push(
            `<a href="${d.url}" class="link" target="_blank" rel="noopener" style="color:${L.link};font-size:15px;line-height:24px;font-weight:600;text-decoration:none;white-space:nowrap;"><span aria-hidden="true">&darr;&nbsp;</span><span style="text-decoration:underline;">${d.label}</span></a>`,
        );
    if (e.secondary) items.push(link(e.secondary));
    if (!items.length) return '';
    return `<p style="margin:0;text-align:${align};">${items.join('<span aria-hidden="true">&nbsp;&nbsp;&nbsp;&nbsp; </span>')}</p>`;
}

function facts(rows, { divider = true } = {}) {
    return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
${rows
    .map(
        ([k, v], i) => `<tr>
<td class="tx-muted bd f-k" valign="top" style="padding:10px 12px 10px 0;${divider && i < rows.length - 1 ? `border-bottom:1px solid ${L.border};` : ''}font-size:15px;line-height:22px;color:${L.muted};">${k}</td>
<td class="tx-strong bd f-v" valign="top" align="right" style="padding:10px 0;${divider && i < rows.length - 1 ? `border-bottom:1px solid ${L.border};` : ''}font-size:15px;line-height:22px;color:${L.strong};font-weight:600;text-align:right;overflow-wrap:anywhere;word-break:break-word;${NUM}">${rich(v)}</td>
</tr>`,
    )
    .join('\n')}
</table>`;
}

function lineItems(lines) {
    const items = lines.items
        .map(
            (it) => `<tr>
<td class="bd" valign="top" style="padding:12px 12px 12px 0;border-bottom:1px solid ${L.border};overflow-wrap:anywhere;word-break:break-word;">
${p(it.name, { cls: 'tx-strong', color: L.strong, size: 15, line: 22, extra: 'font-weight:600;' })}
${p(it.meta, { cls: 'tx-muted', color: L.muted, size: 14, line: 20 })}
</td>
<td class="tx-strong bd" valign="top" align="right" style="padding:12px 0;border-bottom:1px solid ${L.border};font-size:15px;line-height:22px;color:${L.strong};text-align:right;white-space:nowrap;${NUM}">${it.amount}</td>
</tr>`,
        )
        .join('\n');
    const totals = lines.totals
        .map((t) =>
            t.strong
                ? `<tr><td class="tx-strong bd" style="padding:12px 12px 4px 0;border-top:2px solid ${L.strong};font-size:16px;line-height:24px;font-weight:700;color:${L.strong};">${t.label}</td><td class="tx-strong bd" align="right" style="padding:12px 0 4px;border-top:2px solid ${L.strong};font-size:16px;line-height:24px;font-weight:700;color:${L.strong};text-align:right;white-space:nowrap;${NUM}">${t.amount}</td></tr>`
                : `<tr><td class="tx-muted" style="padding:8px 12px 0 0;font-size:15px;line-height:22px;color:${L.muted};">${t.label}</td><td class="tx-body" align="right" style="padding:8px 0 0;font-size:15px;line-height:22px;color:${L.body};text-align:right;white-space:nowrap;${NUM}">${t.amount}</td></tr>`,
        )
        .join('\n');
    // A real data table (not role=presentation) so screen readers announce Item / Amount.
    return `<table width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
<thead><tr>
<th scope="col" align="left" class="tx-muted bd" style="padding:0 0 8px;border-bottom:1px solid ${L.border};font-size:13px;line-height:18px;letter-spacing:.06em;text-transform:uppercase;font-weight:600;color:${L.muted};text-align:left;">${lines.heading}</th>
<th scope="col" align="right" class="tx-muted bd" style="padding:0 0 8px;border-bottom:1px solid ${L.border};font-size:13px;line-height:18px;letter-spacing:.06em;text-transform:uppercase;font-weight:600;color:${L.muted};text-align:right;">Amount</th>
</tr></thead>
<tbody>
${items}
${totals}
</tbody></table>`;
}

function callout(c) {
    const t = tl(c.tone);
    return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td class="sub-${c.tone} bd-${c.tone}" style="padding:14px 16px;background-color:${t.subtle};border-left:4px solid ${t.dot};border-radius:6px;">
${p(c.title, { cls: `tx-${c.tone}`, color: t.text, size: 15, line: 22, extra: 'font-weight:700;' })}
${p(c.text, { cls: 'tx-body', color: L.body, size: 15, line: 22, extra: 'padding-top:2px;' })}
</td></tr></table>`;
}

function avatar(m, size = 40) {
    const t = tl('info');
    return `<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td width="${size}" height="${size}" align="center" valign="middle" class="sub-info tx-info" style="width:${size}px;height:${size}px;border-radius:${size / 2}px;background-color:${t.subtle};color:${t.text};font-size:15px;font-weight:700;line-height:${size}px;text-align:center;" aria-hidden="true">${m.initials}</td></tr></table>`;
}

function messageBody(m) {
    return m.html
        .map((h) =>
            h.startsWith('<pre>')
                ? `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;"><tr><td class="bg-code tx-strong" style="padding:12px 14px;background-color:${L.code};border-radius:6px;font-family:${MONO};font-size:14px;line-height:22px;color:${L.strong};white-space:pre;">${h.slice(5, -6).replace(/\n/g, '<br>')}</td></tr></table>`
                : h.replace(
                      '<p>',
                      `<p class="tx-body" style="margin:0 0 16px;font-size:16px;line-height:24px;color:${L.body};">`,
                  ),
        )
        .join('\n');
}

function authorRow(m) {
    return `<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
<td valign="middle" style="padding-right:12px;">${avatar(m)}</td>
<td valign="middle">${p(m.author, { cls: 'tx-strong', color: L.strong, size: 15, line: 22, extra: 'font-weight:700;' })}${p(`${m.role} · ${m.time}`, { cls: 'tx-muted', color: L.muted, size: 14, line: 20 })}</td>
</tr></table>`;
}

function fallback(url) {
    return p(
        `Button not working? Copy this link into your browser:<br><a href="${url}" class="link" style="color:${L.link};word-break:break-all;">${url}</a>`,
        { cls: 'tx-muted', color: L.muted, size: 14, line: 21 },
    );
}

const intro = (e) =>
    (e.greeting ? p(e.greeting, { extra: 'padding-bottom:10px;' }) : '') +
    p(e.opening);
const help = (e) =>
    p(e.help, { cls: 'tx-muted', color: L.muted, size: 14, line: 21 });
const row = (html, pad = '0 40px 24px', extra = '') =>
    `<tr><td class="px" style="padding:${pad};${extra}">${html}</td></tr>`;
const hr = (pad = '0 40px') =>
    `<tr><td class="px" style="padding:${pad};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="bd" style="border-top:1px solid ${L.border};font-size:0;line-height:0;height:1px;">&nbsp;</td></tr></table></td></tr>`;

function footer(
    e,
    { align = 'center', mark = true, pad = '28px 24px 0' } = {},
) {
    const sep = `<span class="tx-muted" aria-hidden="true" style="color:${L.muted};">&nbsp;&nbsp;·&nbsp;&nbsp;</span>`;
    const links = [
        link({ label: 'Help centre', url: company.helpUrl }, { size: 14 }),
        link(
            { label: 'Email preferences', url: company.prefsUrl },
            { size: 14 },
        ),
    ].join(sep);
    return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="px" align="${align}" style="padding:${pad};text-align:${align};">
${mark ? `<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="${align}" style="margin:0 ${align === 'center' ? 'auto' : '0'} 12px;"><tr><td>${logo({ height: 24, mark: true })}</td></tr></table>` : ''}
${p(`<strong class="tx-strong" style="color:${L.strong};">${company.name}</strong> · ${company.tagline}`, { cls: 'tx-muted', color: L.muted, size: 14, line: 21, extra: `text-align:${align};` })}
${p(`${company.legal}, ${company.address}`, { cls: 'tx-muted', color: L.muted, size: 13, line: 20, extra: `text-align:${align};padding-top:4px;` })}
${p(`Questions? Reply to this email or write to <span class="tx-strong" style="color:${L.strong};font-weight:600;">${company.supportEmail}</span>`, { cls: 'tx-muted', color: L.muted, size: 14, line: 21, extra: `text-align:${align};padding-top:12px;` })}
${p(links, { cls: 'tx-muted', color: L.muted, size: 14, line: 22, extra: `text-align:${align};padding-top:6px;` })}
${p(e.reason, { cls: 'tx-muted', color: L.muted, size: 13, line: 20, extra: `text-align:${align};padding-top:10px;` })}
</td></tr></table>`;
}

const card = (
    inner,
    { radius = 12, border = true, extra = '' } = {},
) => `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg-card bd" style="width:100%;background-color:${L.card};${border ? `border:1px solid ${L.border};` : ''}border-radius:${radius}px;border-collapse:separate;${extra}">
${inner}
</table>`;

// ── Direction A: two-card receipt (after the owner's reference: Stripe-style receipt layout) ────────
const A_FRAME = '#EFEEEA'; // warm grey page, per the owner's reference
function docThumb() {
    const img = (file, cls, style = '') =>
        `<img src="${ASSET}/${file}" width="56" height="70" alt="" class="${cls}" style="display:block;width:56px;height:70px;border:0;${style}">`;
    return `${img('doc-light.png', 'logo-l')}<!--[if !mso]><!--><div class="logo-d" style="display:none;max-height:0;overflow:hidden;mso-hide:all;">${img('doc-dark.png', '')}</div><!--<![endif]-->`;
}
function dirA(e) {
    const PX = 48;
    const r = (html, pad) => row(html, pad.replace(/X/g, `${PX}px`));
    const tone = e.status.tone;
    const t = tl(tone);
    const label = {
        Invoice: `Invoice from ${company.legal}`,
        Receipt: `Receipt from ${company.legal}`,
        Security: 'Account security',
        Support: `Reply from ${e.facts[1][1]}`,
    }[e.kind];
    const statusLine = `<p style="margin:0;font-size:16px;line-height:24px;"><span class="dotc-${tone}" aria-hidden="true" style="color:${t.dot};font-size:13px;">&#9679;</span>&nbsp;<span class="tx-${tone}" style="color:${t.text};font-weight:700;">${e.status.label}</span>${e.amount ? `<span class="tx-muted" style="color:${L.muted};"> · ${e.amount.when}</span>` : ''}</p>`;
    const hero = e.amount
        ? `<p class="tx-strong amount amt-a" style="margin:6px 0 6px;font-size:36px;line-height:42px;font-weight:700;letter-spacing:-.025em;color:${L.strong};${NUM}">${e.amount.value}</p>`
        : `<h1 class="tx-strong h1" style="margin:8px 0 8px;font-size:30px;line-height:36px;font-weight:700;letter-spacing:-.015em;color:${L.strong};">${e.title}</h1>`;
    const top = `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td valign="top">${p(label, { cls: 'tx-muted', color: L.muted, size: 15, line: 22 })}${hero}${statusLine}</td>
${e.lines ? `<td class="hide-sm thumb" valign="top" align="right" width="56" style="display:none;mso-hide:all;width:56px;padding-left:16px;">${docThumb()}</td>` : ''}
</tr></table>`;
    // Card 1: summary and the one thing to do.
    let c1 = r(top, '48px X 28px');
    if (e.message) {
        c1 +=
            r(intro(e), '0 X 20px') +
            r(authorRow(e.message), '0 X 16px') +
            r(messageBody(e.message), '0 X 8px');
    } else {
        c1 += r(intro(e), '0 X 24px');
    }
    if (e.callout) c1 += r(callout(e.callout), '0 X 24px');
    if (e.cta)
        c1 += r(
            `<table role="presentation" cellpadding="0" cellspacing="0" border="0" class="btn-full"><tr><td>${button(e.cta)}</td></tr></table>`,
            '0 X 20px',
        );
    if (e.note && e.message)
        c1 += r(
            p(e.note, { cls: 'tx-muted', color: L.muted, size: 14, line: 21 }),
            '0 X 24px',
        );
    if (e.note && e.kind === 'Security')
        c1 += r(
            p(e.note, { cls: 'tx-muted', color: L.muted, size: 14, line: 21 }),
            '0 X 24px',
        );
    const links = actionLinks(e);
    const keyFacts = e.lines ? e.facts : null;
    if (links || keyFacts) {
        c1 += hr(`0 ${PX}px`);
        if (links) c1 += r(links, '18px X 4px');
        if (keyFacts) c1 += r(facts(keyFacts, { divider: false }), '14px X 0');
    }
    c1 += r('', '0 0 32px');
    // Card 2: the record (line items, or the details for non-money emails).
    const heading = {
        Invoice: `Invoice ${e.ref}`,
        Receipt: `Receipt ${e.ref}`,
        Security: 'Request details',
        Support: `Ticket ${e.facts[0][1]}`,
    }[e.kind];
    let c2 = r(
        `<h2 class="tx-strong" style="margin:0;font-size:18px;line-height:26px;font-weight:700;color:${L.strong};overflow-wrap:anywhere;word-break:break-word;">${heading}</h2>`,
        '36px X 16px',
    );
    if (e.lines) c2 += r(lineItems(e.lines), '0 X 24px');
    else c2 += r(facts(e.facts), '0 X 20px');
    if (e.note && !e.message && e.kind !== 'Security')
        c2 += r(
            p(e.note, { cls: 'tx-muted', color: L.muted, size: 14, line: 21 }),
            '0 X 24px',
        );
    if (e.fallbackLink) c2 += r(fallback(e.fallbackLink), '0 X 24px');
    c2 += hr(`0 ${PX}px`) + r(help(e), '16px X 32px');
    const brand = `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:0 0 20px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
<td valign="middle" style="padding-right:10px;">${logo({ height: 28, mark: true, alt: '' })}</td>
<td valign="middle" class="tx-strong" style="font-size:18px;line-height:28px;font-weight:700;letter-spacing:-.01em;color:${L.strong};">${company.name}</td>
</tr></table></td></tr></table>`;
    const body = `${brand}
${card(c1, { radius: 16, border: false })}
<div style="height:16px;line-height:16px;font-size:0;">&nbsp;</div>
${card(c2, { radius: 16, border: false })}
${footer(e)}`;
    return shell({ e, body: progressive(body, PX, 'pxa'), frame: A_FRAME });
}

// ── Direction B: branded header band ────────────────────────────────────────────────────────────
function dirB(e) {
    const bandRight =
        e.kind === 'Support'
            ? e.ref
            : e.kind === 'Security'
              ? 'Account security'
              : `${e.kind} ${e.ref}`;
    const band = `<tr><td class="bg-band px" bgcolor="${L.band}" style="padding:22px 32px;background-color:${L.band};border-radius:12px 12px 0 0;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td valign="middle"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
<td valign="middle" style="padding-right:10px;"><img src="${ASSET}/mark-on-accent.png" width="30" height="30" alt="" style="display:block;width:30px;height:30px;border:0;"></td>
<td valign="middle" style="font-size:20px;line-height:30px;font-weight:700;letter-spacing:-.01em;color:#FFFFFF;">${company.name}</td>
</tr></table></td>
<td valign="middle" align="right" class="tx-band" style="padding-left:12px;overflow-wrap:anywhere;word-break:break-word;text-align:right;color:${L.bandMuted};font-size:14px;line-height:20px;font-weight:600;${NUM}">${bandRight}</td>
</tr></table></td></tr>`;
    const cells = [];
    for (const [k, v] of e.facts) cells.push([k, v, false]);
    const grid = [];
    for (let i = 0; i < cells.length; i += 2) {
        const c = (x, right) =>
            x
                ? `<td class="stack ${right ? 'stack-gap cell-sm' : ''}" valign="top" width="50%" style="width:50%;padding:${right ? '0 0 0 12px' : '0 12px 0 0'};">
${eyebrow(x[0])}
<p class="tx-strong" style="margin:4px 0 0;font-size:16px;line-height:24px;font-weight:600;color:${L.strong};overflow-wrap:anywhere;word-break:break-word;${NUM}">${rich(x[1])}</p></td>`
                : `<td class="stack hide-sm" width="50%" style="width:50%;">&nbsp;</td>`;
        grid.push(
            `<tr><td style="padding:${i === 0 && !e.amount ? '0' : '16px'} 0 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>${c(cells[i], false)}${c(cells[i + 1], true)}</tr></table></td></tr>`,
        );
    }
    const panel = `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg-panel bd" style="background-color:${L.panel};border:1px solid ${L.border};border-radius:10px;border-collapse:separate;"><tr><td style="padding:20px 20px 20px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">${e.amount ? `<tr><td class="bd" style="padding:0 0 16px;border-bottom:1px solid ${L.border};">${eyebrow(e.amount.label)}<p class="tx-strong amount amt-b" style="margin:4px 0 0;font-size:30px;line-height:36px;font-weight:700;letter-spacing:-.02em;color:${L.strong};${NUM}">${e.amount.value}</p>${p(e.amount.caption, { cls: 'tx-muted', color: L.muted, size: 15, line: 22 })}</td></tr>` : ''}${grid.join('')}</table></td></tr></table>`;
    let rows = band;
    rows += row(
        `${chip(e.status)}<h1 class="tx-strong h1" style="margin:14px 0 0;font-size:26px;line-height:32px;font-weight:700;letter-spacing:-.01em;color:${L.strong};">${e.title}</h1>`,
        '28px 32px 12px',
    );
    if (e.message) {
        rows += row(intro(e), '0 32px 20px');
        rows += row(
            `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bd" style="border:1px solid ${L.border};border-radius:10px;border-collapse:separate;"><tr><td style="padding:20px 20px 4px;">${authorRow(e.message)}<div style="height:16px;line-height:16px;font-size:0;">&nbsp;</div>${messageBody(e.message)}</td></tr></table>`,
            '0 32px 24px',
        );
    } else {
        rows += row(intro(e), '0 32px 24px');
    }
    if (e.callout) rows += row(callout(e.callout), '0 32px 24px');
    if (!e.message) rows += row(panel, '0 32px 24px');
    const links = actionLinks(e);
    if (e.cta) {
        // Links go on their own line under the button so the row never overflows a phone, even without <style>.
        rows += row(
            `<table role="presentation" cellpadding="0" cellspacing="0" border="0" class="btn-full"><tr><td>${button(e.cta)}</td></tr></table>${links ? `<div style="height:14px;line-height:14px;font-size:0;">&nbsp;</div>${links}` : ''}`,
            '0 32px 24px',
        );
    } else if (links) {
        rows += row(links, '0 32px 24px');
    }
    if (e.note && e.message)
        rows += row(
            p(e.note, { cls: 'tx-muted', color: L.muted, size: 14, line: 21 }),
            '0 32px 24px',
        );
    if (e.message) rows += row(panel, '0 32px 24px');
    if (e.lines) rows += row(lineItems(e.lines), '8px 32px 24px');
    if (e.note && !e.message)
        rows += row(
            p(e.note, { cls: 'tx-muted', color: L.muted, size: 14, line: 21 }),
            '0 32px 24px',
        );
    if (e.fallbackLink) rows += row(fallback(e.fallbackLink), '0 32px 24px');
    rows += hr('0 32px') + row(help(e), '16px 32px 24px');
    const body = `${card(rows, { border: false, extra: 'box-shadow:0 1px 2px rgba(26,33,51,.06),0 4px 16px rgba(26,33,51,.06);' })}
${footer(e)}`;
    return shell({ e, body: progressive(body, 32, 'pxb') });
}

// ── Direction C: status rail ────────────────────────────────────────────────────────────────────
function stepper(steps, tone) {
    const t = tl(tone);
    const cols = steps
        .map((s, i) => {
            const st = s.state === 'alert' ? 'danger' : tone;
            const c = tl(st);
            const last = i === steps.length - 1;
            const dot =
                s.state === 'done' || s.state === 'alert'
                    ? `<div class="dot-${st}" style="width:14px;height:14px;border-radius:7px;background-color:${c.dot};font-size:0;line-height:0;">&nbsp;</div>`
                    : s.state === 'current'
                      ? `<div class="bg-card bd-${st}" style="width:10px;height:10px;border-radius:7px;border:2px solid ${c.dot};background-color:${L.card};font-size:0;line-height:0;">&nbsp;</div>`
                      : `<div class="bg-card bd" style="width:10px;height:10px;border-radius:7px;border:2px solid ${L.border};background-color:${L.card};font-size:0;line-height:0;">&nbsp;</div>`;
            const lineDone =
                s.state === 'done' &&
                steps[i + 1] &&
                steps[i + 1].state !== 'upcoming';
            const connector = last
                ? ''
                : `<td valign="middle" style="padding:0 8px;"><div class="${lineDone ? `dot-${tone}` : 'bd'}" style="height:2px;${lineDone ? `background-color:${t.dot};` : `border-top:2px dashed ${L.border};height:0;`}font-size:0;line-height:0;">&nbsp;</div></td>`;
            const labelTone =
                s.state === 'alert'
                    ? `tx-danger" style="color:${tl('danger').text};`
                    : s.state === 'upcoming'
                      ? `tx-muted" style="color:${L.muted};`
                      : `tx-strong" style="color:${L.strong};`;
            return `<td valign="top" width="${Math.floor(100 / steps.length)}%" style="width:${Math.floor(100 / steps.length)}%;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td width="14" valign="middle" style="width:14px;">${dot}</td>${connector}</tr></table>
<p class="${labelTone}margin:8px 0 0;font-size:14px;line-height:20px;font-weight:600;">${s.label}</p>
<p class="tx-muted" style="margin:0;font-size:14px;line-height:20px;color:${L.muted};${NUM}">${s.date}</p>
</td>`;
        })
        .join('');
    const summary = steps
        .map(
            (s) =>
                `${s.label} ${s.date} (${{ done: 'done', current: 'now', alert: 'missed', upcoming: 'next' }[s.state]})`,
        )
        .join(', ');
    return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" aria-label="Progress: ${summary}"><tr>${cols}</tr></table>`;
}

function dirC(e) {
    const tone = e.status.tone;
    const t = tl(tone);
    const statusLine = `<p style="margin:0;font-size:15px;line-height:22px;font-weight:700;"><span class="dotc-${tone}" aria-hidden="true" style="color:${t.dot};">&#9679;</span>&nbsp;<span class="tx-${tone}" style="color:${t.text};">${e.status.label}</span></p>`;
    let rows = `<tr><td class="dot-${tone}" bgcolor="${t.dot}" height="4" style="height:4px;background-color:${t.dot};border-radius:12px 12px 0 0;font-size:0;line-height:0;">&nbsp;</td></tr>`;
    rows += row(
        `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td valign="middle">${logo({ height: 28 })}</td>
<td valign="middle" align="right" class="tx-muted" style="padding-left:12px;overflow-wrap:anywhere;word-break:break-word;text-align:right;font-size:14px;line-height:20px;color:${L.muted};${NUM}">${e.ref}</td>
</tr></table>`,
        '24px 36px 28px',
    );
    rows += row(statusLine, '0 36px 8px');
    if (e.amount) {
        rows += row(
            `<p class="tx-strong amount amt-c" style="margin:0;font-size:34px;line-height:40px;font-weight:700;letter-spacing:-.02em;color:${L.strong};${NUM}">${e.amount.value}</p>
${p(`${e.amount.label} · ${e.amount.caption}`, { cls: 'tx-muted', color: L.muted, size: 16, line: 24, extra: 'padding-top:4px;' })}`,
            '0 36px 20px',
        );
    } else {
        rows += row(
            `<h1 class="tx-strong h1" style="margin:0;font-size:28px;line-height:34px;font-weight:700;letter-spacing:-.015em;color:${L.strong};">${e.title}</h1>`,
            '0 36px 16px',
        );
    }
    if (e.message) {
        rows +=
            row(intro(e), '0 36px 20px') +
            row(authorRow(e.message), '0 36px 16px');
        rows += row(
            `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="bd-info" style="border-left:3px solid ${tl('info').dot};padding:2px 0 0 16px;">${messageBody(e.message)}</td></tr></table>`,
            '0 36px 8px',
        );
    } else {
        rows += row(intro(e), '0 36px 24px');
    }
    if (e.callout) rows += row(callout(e.callout), '0 36px 24px');
    rows += row(
        stepper(e.timeline, tone === 'danger' ? 'accent' : tone),
        '4px 36px 28px',
    );
    if (e.cta) rows += row(button(e.cta, { full: true }), '0 36px 12px');
    const links = actionLinks(e, { align: 'center' });
    rows += row(
        links ||
            (e.note && e.message
                ? p(e.note, {
                      cls: 'tx-muted',
                      color: L.muted,
                      size: 14,
                      line: 21,
                      extra: 'text-align:center;',
                  })
                : ''),
        `${e.cta ? 0 : 4}px 36px 28px`,
    );
    // Perforated divider: the "tear-off" between the summary and the details.
    rows += `<tr><td style="padding:0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td width="10" class="bg-frame" style="width:10px;height:20px;background-color:${L.frame};border-radius:0 10px 10px 0;font-size:0;line-height:0;">&nbsp;</td>
<td style="padding:0 8px;"><div class="bd" style="border-top:2px dashed ${L.border};height:0;font-size:0;line-height:0;">&nbsp;</div></td>
<td width="10" class="bg-frame" style="width:10px;height:20px;background-color:${L.frame};border-radius:10px 0 0 10px;font-size:0;line-height:0;">&nbsp;</td>
</tr></table></td></tr>`;
    rows += row(eyebrow('Details'), '24px 36px 4px');
    rows += row(facts(e.facts), '0 36px 16px');
    if (e.lines) rows += row(lineItems(e.lines), '12px 36px 24px');
    if (e.note && !e.message)
        rows += row(
            p(e.note, { cls: 'tx-muted', color: L.muted, size: 14, line: 21 }),
            '0 36px 24px',
        );
    if (e.fallbackLink) rows += row(fallback(e.fallbackLink), '0 36px 24px');
    rows += `<tr><td class="bg-panel bd px" style="padding:16px 36px;background-color:${L.panel};border-top:1px solid ${L.border};border-radius:0 0 12px 12px;">${help(e)}</td></tr>`;
    const body = `${card(rows, { border: false, extra: `box-shadow:0 0 0 1px ${L.border};` })}
${footer(e)}`;
    // The notch cells take the frame colour; in dark mode they follow .bg-frame.
    return shell({ e, body: progressive(body, 36, 'pxc') });
}

// ── Plain-text alternative (same for every direction) ───────────────────────────────────────────
function plain(e) {
    const strip = (s) =>
        s
            .replace(/<br>/g, ', ')
            .replace(/<[^>]+>/g, '')
            .replace(/\[\[([^\]]+)\]\]/g, '$1');
    const out = [];
    out.push(`${company.name} · ${e.status.label}`, '');
    if (e.amount)
        out.push(`${e.amount.label}: ${e.amount.value}`, e.amount.caption, '');
    else out.push(e.title, '');
    if (e.message)
        out.push(
            e.opening,
            '',
            `${e.message.author} (${e.message.role}) · ${e.message.time}`,
            '',
            e.message.plain,
            '',
        );
    else
        out.push(...(e.greeting ? [e.greeting, ''] : []), strip(e.opening), '');
    if (e.callout) out.push(`${e.callout.title}. ${e.callout.text}`, '');
    if (e.cta) out.push(`${e.cta.label}: ${e.cta.url}`);
    for (const d of e.downloads || []) out.push(`${d.label}: ${d.url}`);
    if (e.secondary) out.push(`${e.secondary.label}: ${e.secondary.url}`);
    if (e.attachment) out.push(`Attached: ${e.attachment}`);
    out.push('');
    const w = Math.max(...e.facts.map(([k]) => k.length));
    for (const [k, v] of e.facts) out.push(`${k.padEnd(w)}  ${strip(v)}`);
    if (e.lines) {
        out.push('', e.lines.heading);
        for (const it of e.lines.items)
            out.push(`- ${it.name} (${it.meta}): ${it.amount}`);
        for (const t of e.lines.totals) out.push(`  ${t.label}: ${t.amount}`);
    }
    if (e.note) out.push('', strip(e.note));
    out.push('', e.help);
    out.push(
        '',
        '--',
        `${company.name} · ${company.tagline}`,
        `${company.legal}, ${company.address}`,
        `Questions? Reply to this email or write to ${company.supportEmail}`,
        `Help centre: ${company.helpUrl}`,
        `Email preferences: ${company.prefsUrl}`,
        e.reason,
        '',
    );
    return out.join('\n');
}

// Build only when run directly (contrast.mjs imports TOKENS).
if (process.argv[1] && process.argv[1].endsWith('build.mjs')) {
    for (const [key, e] of Object.entries(emails)) {
        W = WIDTHS.a;
        fs.writeFileSync(
            path.join(root, 'directions/a', `${key}.html`),
            dirA(e),
        );
        W = WIDTHS.b;
        fs.writeFileSync(
            path.join(root, 'directions/b', `${key}.html`),
            dirB(e),
        );
        W = WIDTHS.c;
        fs.writeFileSync(
            path.join(root, 'directions/c', `${key}.html`),
            dirC(e),
        );
        fs.writeFileSync(
            path.join(root, 'directions/plain-text', `${key}.txt`),
            plain(e),
        );
    }
    for (const [d, fn] of [
        ['a', dirA],
        ['b', dirB],
        ['c', dirC],
    ]) {
        W = WIDTHS[d];
        fs.writeFileSync(
            path.join(root, 'directions', d, 'invoice-issued-long.html'),
            fn(stress),
        );
        // Width comparison (600 / 640 / 680) for the invoice email.
        fs.mkdirSync(path.join(root, 'directions', d, 'width'), {
            recursive: true,
        });
        const keep = W;
        ASSET = '../../../assets';
        for (const w of [600, 640, 680]) {
            W = w;
            fs.writeFileSync(
                path.join(
                    root,
                    'directions',
                    d,
                    'width',
                    `invoice-issued-${w}.html`,
                ),
                fn(emails['invoice-issued']),
            );
        }
        W = keep;
        ASSET = '../../assets';
    }
    console.log('built', Object.keys(emails).length * 3, 'emails');
}
