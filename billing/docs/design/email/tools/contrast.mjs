// WCAG 2.1 contrast for every text/background pair the emails use, in light, dark (our styles) and a
// forced-inversion approximation (invert(1) hue-rotate(180deg), sRGB, as in shoot.mjs). Usage: node contrast.mjs
import { TOKENS } from './build.mjs';
const { L, D, TONES } = TOKENS;
const hex = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16) / 255);
const lin = (c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
const lum = (rgb) =>
    0.2126 * lin(rgb[0]) + 0.7152 * lin(rgb[1]) + 0.0722 * lin(rgb[2]);
const ratio = (a, b) => {
    const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m);
    return (x + 0.05) / (y + 0.05);
};
const clamp = (v) => Math.min(1, Math.max(0, v));
const invert = (rgb) => {
    const [r, g, b] = rgb.map((c) => 1 - c);
    return [
        clamp(-0.574 * r + 1.43 * g + 0.144 * b),
        clamp(0.426 * r + 0.43 * g + 0.144 * b),
        clamp(0.426 * r + 1.43 * g - 0.856 * b),
    ];
};
const pairs = (T, tn) => [
    ['Body text on card', T.body, T.card, 4.5],
    ['Headings / amounts on card', T.strong, T.card, 4.5],
    ['Muted text on card', T.muted, T.card, 4.5],
    ['Muted text on panel', T.muted, T.panel, 4.5],
    ['Footer text on frame', T.muted, T.frame, 4.5],
    [
        'Footer text on warm frame (A)',
        T.muted,
        T === L ? '#EFEEEA' : T.frame,
        4.5,
    ],
    [
        'Footer link on warm frame (A)',
        T.link,
        T === L ? '#EFEEEA' : T.frame,
        4.5,
    ],
    [
        'Brand name on warm frame (A)',
        T.strong,
        T === L ? '#EFEEEA' : T.frame,
        4.5,
    ],
    ['Link on card', T.link, T.card, 4.5],
    ['Link on frame (footer)', T.link, T.frame, 4.5],
    ['Button label on button', T.onAccent, T.accent, 4.5],
    ['Button vs card (non-text)', T.accent, T.card, 3],
    ['Band text (B) on band', T.bandMuted, T.band, 4.5],
    ['Code text on code block', T.strong, T.code, 4.5],
    ...Object.entries(tn)
        .filter(([k]) => k !== 'muted')
        .flatMap(([k, c]) => [
            [`Chip ${k}: text on subtle`, c.text, c.subtle, 4.5],
            [`Status ${k}: text on card (C)`, c.text, T.card, 4.5],
        ]),
    ['Callout body on warning subtle', T.body, tn.warning.subtle, 4.5],
];
const rows = [];
const light = pairs(L, TONES.light);
const dark = pairs(D, TONES.dark);
for (let i = 0; i < light.length; i++) {
    const [name, f, b, min] = light[i];
    const [, df, db] = dark[i];
    const l = ratio(hex(f), hex(b));
    const d = ratio(hex(df), hex(db));
    const v = ratio(invert(hex(f)), invert(hex(b)));
    const mark = (x) => `${x.toFixed(2)}${x >= min ? '' : ' FAIL'}`;
    rows.push(
        `| ${name} | ${min}:1 | ${f} on ${b} · ${mark(l)} | ${df} on ${db} · ${mark(d)} | ${mark(v)} |`,
    );
}
console.log(
    '| Pair | Min | Light | Dark (our styles) | Forced inversion |\n|---|---|---|---|---|\n' +
        rows.join('\n'),
);
