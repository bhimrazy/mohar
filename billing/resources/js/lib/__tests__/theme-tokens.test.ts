import { describe, expect, it } from 'vitest';
import {
    checkContrast,
    contrastRatio,
    relativeLuminance,
    tokensToCss,
    tokensToCssVars,
} from '../theme-tokens';
import type { ThemeTokens } from '../theme-tokens';

const schema = {
    fonts: {
        inter: {
            label: 'Inter',
            kind: 'sans' as const,
            stack: "'Inter', sans-serif",
        },
        'system-mono': {
            label: 'Mono',
            kind: 'mono' as const,
            stack: 'ui-monospace, monospace',
        },
    },
    fontFeatures: { normal: 'normal', 'cv11-ss03': "'cv11' 1, 'ss03' 1" },
    densities: { compact: { 'cell-py': '0.4375rem' } },
};

const tokens: ThemeTokens = {
    shared: {
        'font-sans': 'inter',
        'font-mono': 'system-mono',
        'font-features': 'cv11-ss03',
        radius: '0.5rem',
        'cell-py': '0.75rem',
        'heading-weight': 600,
    },
    light: {
        background: '#FFFFFF',
        foreground: '#1A2133',
        card: '#FFFFFF',
        danger: '#CF5A55',
    },
    dark: {
        background: '#12151D',
        foreground: '#E3E6EE',
        card: '#181C26',
        danger: '#E07D78',
    },
};

describe('contrast', () => {
    it('matches the WCAG reference values', () => {
        expect(contrastRatio('#000000', '#FFFFFF')).toBeCloseTo(21, 5);
        expect(contrastRatio('#FFFFFF', '#FFFFFF')).toBeCloseTo(1, 5);
        expect(contrastRatio('#767676', '#FFFFFF')).toBeCloseTo(4.54, 2);
        expect(relativeLuminance('#FFFFFF')).toBeCloseTo(1, 5);
    });

    it('is symmetric', () => {
        expect(contrastRatio('#4F4BC4', '#FFFFFF')).toBeCloseTo(
            contrastRatio('#FFFFFF', '#4F4BC4'),
            10,
        );
    });

    it('treats invalid colours as failing', () => {
        expect(Number.isNaN(contrastRatio('red', '#FFFFFF'))).toBe(true);
        expect(Number.isNaN(contrastRatio('#FFF', '#FFFFFF'))).toBe(true);
    });

    it('checks every pair in both modes', () => {
        const results = checkContrast(tokens, [
            ['foreground', 'background', 4.5],
            ['danger', 'card', 4.5],
        ]);

        expect(results).toHaveLength(4);
        expect(results.filter((result) => result.passes)).toHaveLength(3);
        expect(
            results.find((r) => r.mode === 'light' && r.foreground === 'danger')
                ?.passes,
        ).toBe(false);
    });
});

describe('tokensToCssVars', () => {
    it('maps fonts, features and plain tokens', () => {
        const vars = tokensToCssVars(tokens, 'light', schema);

        expect(vars['--app-font-sans']).toBe("'Inter', sans-serif");
        expect(vars['--app-font-mono']).toBe('ui-monospace, monospace');
        expect(vars['--font-features']).toBe("'cv11' 1, 'ss03' 1");
        expect(vars['--radius']).toBe('0.5rem');
        expect(vars['--heading-weight']).toBe('600');
        expect(vars['--background']).toBe('#FFFFFF');
        expect(vars['--destructive']).toBe('#CF5A55');
    });

    it('applies density overrides and the selected mode', () => {
        const vars = tokensToCssVars(tokens, 'dark', schema, 'compact');

        expect(vars['--cell-py']).toBe('0.4375rem');
        expect(vars['--background']).toBe('#12151D');
    });

    it('drops unknown fonts and unsafe values', () => {
        const hostile: ThemeTokens = {
            ...tokens,
            shared: {
                ...tokens.shared,
                'font-sans': 'comic-sans',
                radius: '1rem;}body{display:none',
            },
            light: {
                ...tokens.light,
                background: 'url(https://evil.test/x.png)',
            },
        };
        const vars = tokensToCssVars(hostile, 'light', schema);

        expect(vars['--app-font-sans']).toBeUndefined();
        expect(vars['--radius']).toBeUndefined();
        expect(vars['--background']).toBeUndefined();
    });
});

describe('tokensToCss', () => {
    it('renders a light block and a dark override block', () => {
        const css = tokensToCss(tokens, schema, '.preview');

        expect(css.startsWith('.preview{')).toBe(true);
        expect(css).toContain('--background:#FFFFFF;');
        expect(css).toContain('.preview.dark{--background:#12151D;');
        expect(css.split('.preview.dark{')[1]).not.toContain('--radius');
    });
});
