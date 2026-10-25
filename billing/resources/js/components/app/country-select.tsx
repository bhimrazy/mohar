import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { SelectField } from '@/components/app/select-field';

type CountrySelectProps = {
    id: string;
    /** Form field name; the ISO code is submitted. */
    name?: string;
    defaultValue?: string | null;
    value?: string;
    onValueChange?: (value: string) => void;
    invalid?: boolean;
    disabled?: boolean;
    /** Adds a first option that submits an empty value, for optional fields. */
    emptyLabel?: string;
};

/** Shown first, in this order, before the alphabetical list. */
const PINNED = ['NP', 'IN', 'US'];

/** Words people type that the English name does not contain. */
const ALIASES: Record<string, string> = {
    US: 'usa america',
    GB: 'uk britain great britain england',
    AE: 'uae emirates',
    CI: 'ivory coast',
    KR: 'south korea',
    KP: 'north korea',
    CZ: 'czech republic',
    MM: 'burma',
    NL: 'holland',
    TR: 'turkey',
    RU: 'russia',
};

/** Country picker that shows names ("Nepal") and submits the ISO code ("NP"). */
export function CountrySelect({
    defaultValue,
    value,
    ...props
}: CountrySelectProps) {
    const { countries } = usePage().props;
    const options = useMemo(() => {
        const names = new Intl.DisplayNames(['en'], { type: 'region' });
        const known = new Set(countries);
        // A stored code outside the list (an import or an older record) stays selectable instead of blanking.
        for (const code of [value ?? defaultValue]) {
            if (code) {
                known.add(code.toUpperCase());
            }
        }

        const rank = (code: string) => {
            const at = PINNED.indexOf(code);

            return at === -1 ? PINNED.length : at;
        };

        return [...known]
            .map((code) => ({
                value: code,
                label: names.of(code) ?? code,
                keywords: `${code} ${ALIASES[code] ?? ''}`,
            }))
            .sort(
                (a, b) =>
                    rank(a.value) - rank(b.value) ||
                    a.label.localeCompare(b.label),
            );
    }, [countries, value, defaultValue]);

    return (
        <SelectField
            {...props}
            options={options}
            defaultValue={defaultValue?.toUpperCase()}
            value={value?.toUpperCase()}
            placeholder="Choose a country"
            searchable
        />
    );
}
