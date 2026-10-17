/**
 * Theme tokens on the client: WCAG contrast checks and token → CSS variable mapping for the
 * theme editor's live preview. Mirrors App\Support\Themes\ThemeSchema / Contrast and
 * App\Modules\Themes\Support\ThemeStylesheet on the server, which stay authoritative.
 */

export type ThemeTokens = {
    shared: Record<string, string | number>;
    light: Record<string, string>;
    dark: Record<string, string>;
};

export type ThemeMode = 'light' | 'dark';

export type ContrastPair = [
    foreground: string,
    background: string,
    minimum: number,
];

export type FontDefinition = {
    label: string;
    kind: 'sans' | 'mono';
    stack: string;
};

export type ThemeSchemaDescription = {
    name: string;
    version: number;
    shared: Record<string, string>;
    mode: Record<string, string>;
    contrastPairs: ContrastPair[];
    fonts: Record<string, FontDefinition>;
    fontFeatures: Record<string, string>;
    densities: Record<string, Record<string, string>>;
};

export type ContrastResult = {
    mode: ThemeMode;
    foreground: string;
    background: string;
    ratio: number;
    minimum: number;
    passes: boolean;
};

export const HEX_COLOR = /^#[0-9a-fA-F]{6}$/;

function channel(value: number): number {
    const srgb = value / 255;

    return srgb <= 0.04045 ? srgb / 12.92 : ((srgb + 0.055) / 1.055) ** 2.4;
}

/** WCAG relative luminance of a #RRGGBB colour; NaN for anything else. */
export function relativeLuminance(hex: string): number {
    if (!HEX_COLOR.test(hex)) {
        return Number.NaN;
    }

    const [r, g, b] = [1, 3, 5].map((index) =>
        channel(parseInt(hex.slice(index, index + 2), 16)),
    );

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG 2.1 contrast ratio (1–21); NaN when either colour is invalid. */
export function contrastRatio(foreground: string, background: string): number {
    const a = relativeLuminance(foreground);
    const b = relativeLuminance(background);

    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

/** Every key pair in both modes, with pass/fail against its minimum. */
export function checkContrast(
    tokens: ThemeTokens,
    pairs: ContrastPair[],
): ContrastResult[] {
    return (['light', 'dark'] as const).flatMap((mode) =>
        pairs.map(([foreground, background, minimum]) => {
            const ratio = contrastRatio(
                tokens[mode][foreground] ?? '',
                tokens[mode][background] ?? '',
            );

            return {
                mode,
                foreground,
                background,
                ratio,
                minimum,
                passes: Number.isFinite(ratio) && ratio + 1e-9 >= minimum,
            };
        }),
    );
}

const FONT_TOKENS: Record<string, string> = {
    'font-sans': '--app-font-sans',
    'font-mono': '--app-font-mono',
    'font-display': '--app-font-display',
    'font-numeric': '--app-font-numeric',
};

const UNSAFE = /[;{}<>\\]|url\(|expression\(|@import/i;

/**
 * CSS custom properties for one mode (shared + mode tokens), e.g. for a preview wrapper's
 * `style`. Unknown fonts/features and unsafe values are dropped, like the server does.
 */
export function tokensToCssVars(
    tokens: ThemeTokens,
    mode: ThemeMode,
    schema: Pick<
        ThemeSchemaDescription,
        'fonts' | 'fontFeatures' | 'densities'
    >,
    density: string = 'theme',
): Record<string, string> {
    const shared = { ...tokens.shared, ...schema.densities[density] };
    const variables: Record<string, string> = {};

    for (const [name, raw] of Object.entries(shared)) {
        const value = String(raw);

        if (name in FONT_TOKENS) {
            const font = schema.fonts[value];

            if (font) {
                variables[FONT_TOKENS[name]] = font.stack;
            }
        } else if (name === 'font-features') {
            const features = schema.fontFeatures[value];

            if (features) {
                variables['--font-features'] = features;
            }
        } else {
            variables[`--${name}`] = value;
        }
    }

    for (const [name, value] of Object.entries(tokens[mode])) {
        variables[`--${name}`] = value;
    }

    if (variables['--danger'] && !variables['--destructive']) {
        variables['--destructive'] = variables['--danger'];
    }

    return Object.fromEntries(
        Object.entries(variables).filter(([, value]) => !UNSAFE.test(value)),
    );
}

/** A full stylesheet (`selector{…}` + `selector.dark{…}`), matching ThemeStylesheet::render. */
export function tokensToCss(
    tokens: ThemeTokens,
    schema: Pick<
        ThemeSchemaDescription,
        'fonts' | 'fontFeatures' | 'densities'
    >,
    selector = 'html:root',
): string {
    const block = (variables: Record<string, string>) =>
        Object.entries(variables)
            .map(([name, value]) => `${name}:${value};`)
            .join('');
    const light = tokensToCssVars(tokens, 'light', schema);
    const darkOnly = Object.fromEntries(
        Object.keys(tokens.dark).map((name) => [
            `--${name}`,
            tokensToCssVars(tokens, 'dark', schema)[`--${name}`],
        ]),
    );

    return `${selector}{${block(light)}}${selector}.dark{${block(darkOnly)}}`;
}
