import { Fragment } from 'react';
import type { ReactNode } from 'react';

// ADR 0028: Laravel-compatible translation helper. English text is the key; a missing key returns the key.
// Placeholders and plural syntax follow Laravel's `__()` and `trans_choice()` so one catalogue serves PHP and React.

export type Replacements = Record<string, string | number>;

let locale = 'en';
let messages: Record<string, string> = {};
let brand = '';

/** Sets the active catalogue. English sends `{}`. */
export function configureTranslations(
    nextLocale: string,
    nextMessages: Record<string, string>,
    nextBrand = '',
): void {
    locale = nextLocale;
    messages = nextMessages;
    brand = nextBrand;
}

/**
 * Applies shared Inertia props. `translations` is a once prop, so a visit that
 * omits it keeps the catalogue already loaded for the same locale.
 */
export function applySharedTranslations(props: Record<string, unknown>): void {
    const nextLocale = typeof props.locale === 'string' ? props.locale : locale;
    const sent =
        typeof props.translations === 'object' && props.translations !== null
            ? (props.translations as Record<string, string>)
            : null;

    configureTranslations(
        nextLocale,
        sent ?? (nextLocale === locale ? messages : {}),
        typeof props.name === 'string' ? props.name : brand,
    );
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function withBrand(replace: Replacements): Replacements {
    return brand && replace.brand === undefined
        ? { ...replace, brand }
        : replace;
}

function applyReplacements(line: string, replace: Replacements): string {
    const full = withBrand(replace);

    // Longest first so ":counter" is not eaten by ":count".
    return Object.keys(full)
        .sort((a, b) => b.length - a.length)
        .reduce((text, name) => {
            const value = String(full[name]);
            const pattern = new RegExp(`:${escapeRegExp(name)}`, 'gi');

            return text.replace(pattern, (match) => {
                if (
                    match.slice(1) === name.toUpperCase() &&
                    name !== name.toUpperCase()
                ) {
                    return value.toUpperCase();
                }

                if (
                    match[1] === match[1].toUpperCase() &&
                    match[1] !== match[1].toLowerCase()
                ) {
                    return value.charAt(0).toUpperCase() + value.slice(1);
                }

                return value;
            });
        }, line);
}

export function t(key: string, replace: Replacements = {}): string {
    return applyReplacements(messages[key] ?? key, replace);
}

/** Marks a key for extraction and returns it untranslated. */
export function tKey(key: string): string {
    return key;
}

const EXPLICIT = /^[{[]([^[\]{}]*)[}\]]([\s\S]*)$/;

function inRange(count: number, condition: string): boolean {
    const [from, to] = condition.split(',');

    if (to === undefined) {
        return Number(from) === count;
    }

    if (to === '*') {
        return count >= Number(from);
    }

    if (from === '*') {
        return count <= Number(to);
    }

    return count >= Number(from) && count <= Number(to);
}

/** Laravel's MessageSelector index: one/other, with 0 counting as singular in hi. */
function pluralIndex(count: number, rulesLocale: string): number {
    if (rulesLocale.toLowerCase().startsWith('hi')) {
        return count === 0 || count === 1 ? 0 : 1;
    }

    return new Intl.PluralRules(rulesLocale).select(count) === 'one' ? 0 : 1;
}

export function tChoice(
    key: string,
    count: number,
    replace: Replacements = {},
): string {
    const translated = key in messages;
    const parts = (messages[key] ?? key).split('|').map((part) => part.trim());
    const withCount = { ...replace, count };

    for (const part of parts) {
        const match = EXPLICIT.exec(part);

        if (match && inRange(count, match[1])) {
            return applyReplacements(match[2].trim(), withCount);
        }
    }

    if (parts.length === 1) {
        return applyReplacements(parts[0], withCount);
    }

    const plain = parts.map((part) => EXPLICIT.exec(part)?.[2].trim() ?? part);
    // Like Laravel, an untranslated key is pluralised with the English rules.
    const chosen =
        plain[pluralIndex(count, translated ? locale : 'en')] ??
        plain[plain.length - 1];

    return applyReplacements(chosen, withCount);
}

type TransProps = {
    k: string;
    values?: Record<string, ReactNode>;
};

/** Translates a sentence that contains elements: `values` entries replace their `:name` placeholders. */
export function Trans({ k, values = {} }: TransProps) {
    const line = applyReplacements(messages[k] ?? k, {});
    const names = Object.keys(values);

    if (names.length === 0) {
        return <>{line}</>;
    }

    const pattern = new RegExp(`:(${names.map(escapeRegExp).join('|')})`, 'g');
    const nodes: ReactNode[] = [];
    let last = 0;

    for (const match of line.matchAll(pattern)) {
        nodes.push(line.slice(last, match.index));
        nodes.push(<Fragment key={match.index}>{values[match[1]]}</Fragment>);
        last = match.index + match[0].length;
    }

    nodes.push(line.slice(last));

    return <>{nodes}</>;
}
