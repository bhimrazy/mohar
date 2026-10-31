import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it } from 'vitest';
import { configureTranslations, t, tChoice, Trans } from '../i18n';

// Expected values are what Laravel 12's __() / trans_choice() return for the same input (checked in tinker).
beforeEach(() => configureTranslations('en', {}, 'Acme'));

describe('replacements follow strtr: one pass, case-sensitive', () => {
    it('does not substitute inside an inserted value', () => {
        expect(
            t('Ticket :subject by :name', {
                subject: 'Price :name?',
                name: 'Ram',
            }),
        ).toBe('Ticket Price :name? by Ram');
    });

    it('does not fill :brand inside user data', () => {
        expect(t('Subject: :subject', { subject: 'see :brand docs' })).toBe(
            'Subject: see :brand docs',
        );
    });

    it('leaves a mixed-case placeholder alone', () => {
        expect(t('Hi :nAME', { name: 'asha' })).toBe('Hi :nAME');
    });
});

describe('tChoice follows MessageSelector', () => {
    it("keeps the caller's :count", () => {
        expect(
            tChoice('{1} :count thing|[2,*] :count things', 3, {
                count: 'three',
            }),
        ).toBe('three things');
    });

    it('does not treat non-numeric brackets as a condition', () => {
        expect(tChoice('[Draft] one item|[Draft] :count items', 3)).toBe(
            '[Draft] 3 items',
        );
    });
});

describe('Trans', () => {
    it('matches the longest placeholder first', () => {
        expect(
            renderToStaticMarkup(
                <Trans
                    k="See :terms and :termsLink"
                    values={{ terms: <b>T</b>, termsLink: <i>L</i> }}
                />,
            ),
        ).toBe('See <b>T</b> and <i>L</i>');
    });
});
