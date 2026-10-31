import { Fragment } from 'react';
import type { ReactNode } from 'react';

// ADR 0028: Laravel-compatible translation helper. English text is the key; a missing key returns the key.
// Placeholders and plural syntax follow Laravel's `__()` and `trans_choice()` so one catalogue serves PHP and React.

export type Replacements = Record<string, string | number>;

let locale = 'en';
let messages: Record<string, string> = {};
let brand = '';

/** Sets the active catalogue. */
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
    const sent = props.translations;
    // PHP encodes an empty catalogue as `[]`.
    const catalogue =
        typeof sent === 'object' && sent !== null
            ? Array.isArray(sent)
                ? {}
                : (sent as Record<string, string>)
            : nextLocale === locale
              ? messages
              : {};

    configureTranslations(
        nextLocale,
        catalogue,
        typeof props.name === 'string' ? props.name : brand,
    );
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function lookup(key: string): string {
    return Object.hasOwn(messages, key) ? messages[key] : key;
}

/** Longest name first, like PHP's strtr(), so `:termsLink` wins over `:terms`. */
function alternation(names: string[]): RegExp {
    return new RegExp(
        names
            .sort((a, b) => b.length - a.length)
            .map(escapeRegExp)
            .join('|'),
        'g',
    );
}

function ucfirst(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

/**
 * Mirrors Illuminate\Translation\Translator::makeReplacements(): `:key`, `:Key` and `:KEY` entries, then one
 * case-sensitive strtr pass over the original line, so inserted text is never replaced again. `:brand` is added
 * only when the caller did not pass it.
 */
function applyReplacements(line: string, replace: Replacements): string {
    const given =
        brand && replace.brand === undefined ? { ...replace, brand } : replace;
    const table = new Map<string, string>();

    for (const [key, raw] of Object.entries(given)) {
        const value = String(raw);

        table.set(`:${ucfirst(key)}`, ucfirst(value));
        table.set(`:${key.toUpperCase()}`, value.toUpperCase());
        table.set(`:${key}`, value);
    }

    if (table.size === 0) {
        return line;
    }

    return line.replace(
        alternation([...table.keys()]),
        (match) => table.get(match) ?? match,
    );
}

export function t(key: string, replace: Replacements = {}): string {
    return applyReplacements(lookup(key), replace);
}

/** Marks a key for extraction and returns it untranslated. */
export function tKey(key: string): string {
    return key;
}

// Mirrors Illuminate\Translation\MessageSelector: the same condition character class, applied to untrimmed segments.
const EXPLICIT = /^[{[]([-?\d|*,.]*)[}\]]([\s\S]*)/;
const CONDITION_PREFIX = /^[{[][-?\d|*,.]*[}\]]/;

/** Mirrors MessageSelector::extractFromString(). */
function explicitMatch(part: string, count: number): string | null {
    const match = EXPLICIT.exec(part);

    if (!match) {
        return null;
    }

    const [, condition, value] = match;

    if (condition.includes(',')) {
        const [from, to] = condition.split(',', 2);

        if (to === '*' && count >= Number(from)) {
            return value;
        }

        if (from === '*' && count <= Number(to)) {
            return value;
        }

        if (count >= Number(from) && count <= Number(to)) {
            return value;
        }
    }

    return condition !== '' && Number(condition) === count ? value : null;
}

/** Mirrors MessageSelector::getPluralIndex() for one/other locales; `hi` counts 0 as singular. */
function pluralIndex(count: number, rulesLocale: string): number {
    if (rulesLocale.toLowerCase().startsWith('hi')) {
        return count === 0 || count === 1 ? 0 : 1;
    }

    return new Intl.PluralRules(rulesLocale).select(count) === 'one' ? 0 : 1;
}

/** Mirrors Translator::choice() and MessageSelector::choose(). */
export function tChoice(
    key: string,
    count: number,
    replace: Replacements = {},
): string {
    const translated = Object.hasOwn(messages, key);
    const segments = lookup(key).split('|');
    const withCount = { count, ...replace };

    for (const part of segments) {
        const value = explicitMatch(part, count);

        if (value !== null) {
            return applyReplacements(value.trim(), withCount);
        }
    }

    const plain = segments.map((part) => part.replace(CONDITION_PREFIX, ''));
    // Like Laravel, an untranslated key is pluralised with the English rules.
    const index = pluralIndex(count, translated ? locale : 'en');
    const chosen =
        plain.length === 1 || plain[index] === undefined
            ? plain[0]
            : plain[index];

    return applyReplacements(chosen, withCount);
}

type TransProps = {
    k: string;
    values?: Record<string, ReactNode>;
};

/** Translates a sentence that contains elements: `values` entries replace their `:name` placeholders. */
export function Trans({ k, values = {} }: TransProps) {
    const line = lookup(k);
    const all: Record<string, ReactNode> =
        brand && values.brand === undefined ? { ...values, brand } : values;
    const names = Object.keys(all);

    if (names.length === 0) {
        return <>{line}</>;
    }

    const pattern = alternation(names.map((name) => `:${name}`));
    const nodes: ReactNode[] = [];
    let last = 0;

    for (const match of line.matchAll(pattern)) {
        nodes.push(line.slice(last, match.index));
        nodes.push(
            <Fragment key={match.index}>{all[match[0].slice(1)]}</Fragment>,
        );
        last = match.index + match[0].length;
    }

    nodes.push(line.slice(last));

    return <>{nodes}</>;
}
