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

        return [...known]
            .map((code) => ({ value: code, label: names.of(code) ?? code }))
            .sort((a, b) => a.label.localeCompare(b.label));
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
