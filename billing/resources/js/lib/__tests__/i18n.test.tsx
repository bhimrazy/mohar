import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it } from 'vitest';
import replacements from '../../../../tests/Fixtures/i18n/replacements.json';
import plurals from '../../../../tests/Fixtures/i18n/plurals.json';
import {
    applySharedTranslations,
    configureTranslations,
    type Replacements,
    t,
    tChoice,
    tKey,
    Trans,
} from '../i18n';

beforeEach(() => configureTranslations('en', {}));

describe('t', () => {
    it('returns the key when the catalogue has no entry, with replacements applied', () => {
        expect(t('Hello :name', { name: 'Asha' })).toBe('Hello Asha');
        expect(t('Plain text')).toBe('Plain text');
    });

    it('uses the catalogue entry and the :Name / :NAME variants', () => {
        configureTranslations('ne', { 'Hi :name': 'नमस्ते :name, :Name, :NAME' });

        expect(t('Hi :name', { name: 'asha' })).toBe('नमस्ते asha, Asha, ASHA');
    });

    it('does not let a short placeholder eat a longer one', () => {
        expect(t(':count of :counter', { count: 1, counter: 'x' })).toBe(
            '1 of x',
        );
    });

    it('fills :brand from the shared name unless the caller passes it', () => {
        configureTranslations('en', {}, 'Acme Hosting');

        expect(t('Welcome to :brand')).toBe('Welcome to Acme Hosting');
        expect(t('Welcome to :brand', { brand: 'Other' })).toBe(
            'Welcome to Other',
        );
    });

    it('tKey returns the key untouched', () => {
        configureTranslations('ne', { Total: 'जम्मा' });

        expect(tKey('Total')).toBe('Total');
    });
});

describe('tChoice matches the PHP fixture', () => {
    it.each(plurals)('$locale "$line" with $count', (row) => {
        configureTranslations(row.locale, { [row.line]: row.line });

        expect(tChoice(row.line, row.count, row.replace as Replacements)).toBe(
            row.expected,
        );
    });
});

describe('applySharedTranslations', () => {
    it('keeps the loaded catalogue when a visit omits the once prop', () => {
        applySharedTranslations({
            locale: 'ne-NP',
            translations: { Total: 'जम्मा' },
        });
        applySharedTranslations({ locale: 'ne-NP' });

        expect(t('Total')).toBe('जम्मा');
    });

    it('drops the catalogue when the locale changes and none is sent', () => {
        applySharedTranslations({
            locale: 'ne-NP',
            translations: { Total: 'जम्मा' },
        });
        applySharedTranslations({ locale: 'en', translations: {} });

        expect(t('Total')).toBe('Total');
    });
});

describe('lookups', () => {
    it('never returns an inherited property', () => {
        expect(t('constructor')).toBe('constructor');
        expect(tChoice('toString|many', 1)).toBe('toString');
    });

    it('treats an empty array catalogue as empty', () => {
        applySharedTranslations({ locale: 'en', translations: [] });

        expect(t('Total')).toBe('Total');
    });
});

describe('Trans', () => {
    it('lets values.brand win over the shared brand', () => {
        configureTranslations('en', {}, 'Acme');

        expect(
            renderToStaticMarkup(
                <Trans k="By :brand" values={{ brand: <b>X</b> }} />,
            ),
        ).toBe('By <b>X</b>');
    });

    it('puts elements into their placeholders and escapes the translation', () => {
        configureTranslations('en', {
            'Read the :terms before you pay.': 'पढ्नुहोस् :terms <b>x</b>',
        });

        const html = renderToStaticMarkup(
            <Trans
                k="Read the :terms before you pay."
                values={{ terms: <a href="/terms">terms</a> }}
            />,
        );

        expect(html).toBe(
            'पढ्नुहोस् <a href="/terms">terms</a> &lt;b&gt;x&lt;/b&gt;',
        );
    });

    it('falls back to the key', () => {
        expect(
            renderToStaticMarkup(
                <Trans k="See :link now" values={{ link: <i>here</i> }} />,
            ),
        ).toBe('See <i>here</i> now');
    });
});

describe('t matches the PHP replacement fixture', () => {
    it.each(replacements)('$line', (row) => {
        configureTranslations('en', {});

        expect(t(row.line, row.replace as unknown as Replacements)).toBe(
            row.expected,
        );
    });
});
