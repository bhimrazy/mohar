import {
    SettingFieldInput,
    type SettingFieldData,
} from '@/components/app/setting-field';

/** extension (with its dot) => the details the registrar asks for; only extensions that have some. */
export type TldAttributeFieldMap = Record<string, SettingFieldData[]>;

/** The fields for a domain name, by its extension (longest match, so .com.au wins over .au). */
export function tldFieldsFor(
    map: TldAttributeFieldMap,
    domain: string,
): SettingFieldData[] {
    const name = domain.trim().toLowerCase();
    const match = Object.keys(map)
        .filter((tld) => name.endsWith(tld) && name.length > tld.length)
        .sort((a, b) => b.length - a.length)[0];

    return match ? map[match] : [];
}

type Props = {
    fields: SettingFieldData[];
    values: Record<string, string>;
    onChange: (values: Record<string, string>) => void;
    /** Server errors keyed `tld_attributes.<key>`. */
    errors?: Record<string, string | undefined>;
    idPrefix?: string;
    /** Also submit the values as `tld_attributes[key]` inputs, for forms that post their own markup. */
    nativeInputs?: boolean;
};

/** The extra details a registry asks for, rendered with the shared integration-setting controls. */
export function TldAttributeFields({
    fields,
    values,
    onChange,
    errors = {},
    idPrefix = 'tld-attribute',
    nativeInputs = false,
}: Props) {
    if (fields.length === 0) {
        return null;
    }

    return (
        <>
            {fields.map((field) => (
                <div key={field.key}>
                    <SettingFieldInput
                        id={`${idPrefix}-${field.key}`}
                        field={field}
                        value={values[field.key] ?? ''}
                        error={errors[`tld_attributes.${field.key}`]}
                        onChange={(value) =>
                            onChange({ ...values, [field.key]: value })
                        }
                    />
                    {nativeInputs && (
                        <input
                            type="hidden"
                            name={`tld_attributes[${field.key}]`}
                            value={values[field.key] ?? ''}
                        />
                    )}
                </div>
            ))}
        </>
    );
}
