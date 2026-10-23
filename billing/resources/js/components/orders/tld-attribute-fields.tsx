import { Field } from '@/components/app/field';
import {
    SettingFieldControl,
    type SettingFieldData,
} from '@/components/app/setting-field';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

/** extension (with its dot) => the details the registrar asks for; only extensions that have some. */
export type TldAttributeFieldMap = Record<string, SettingFieldData[]>;

/** The extension (longest match, so .com.au wins over .au) a domain name falls under, if it has fields. */
export function tldMatch(
    map: TldAttributeFieldMap,
    domain: string,
): string | null {
    const name = domain.trim().toLowerCase();

    return (
        Object.keys(map)
            .filter((tld) => name.endsWith(tld) && name.length > tld.length)
            .sort((a, b) => b.length - a.length)[0] ?? null
    );
}

/** The fields for a domain name, by its extension. */
export function tldFieldsFor(
    map: TldAttributeFieldMap,
    domain: string,
): SettingFieldData[] {
    const match = tldMatch(map, domain);

    return match ? map[match] : [];
}

type Props = {
    /** The extension, for the heading ("Details the .us registry needs"). */
    extension: string;
    fields: SettingFieldData[];
    values: Record<string, string>;
    onChange: (values: Record<string, string>) => void;
    /** Server errors keyed `tld_attributes.<key>`. */
    errors?: Record<string, string | undefined>;
    /** Called when a field changes, so its error can be cleared. */
    onClearError?: (name: string) => void;
    idPrefix?: string;
    /** Also submit the values as `tld_attributes[key]` inputs, for forms that post their own markup. */
    nativeInputs?: boolean;
    className?: string;
};

/**
 * The extra details a registry asks for, rendered with the shared integration-setting controls. Each field's help
 * stays visible while an error shows: the help says who qualifies, which is what a person needs when it fails.
 */
export function TldAttributeFields({
    extension,
    fields,
    values,
    onChange,
    errors = {},
    onClearError,
    idPrefix = 'tld-attribute',
    nativeInputs = false,
    className,
}: Props) {
    if (fields.length === 0) {
        return null;
    }

    const change = (key: string, value: string) => {
        onChange({ ...values, [key]: value });
        onClearError?.(`tld_attributes.${key}`);
    };

    return (
        <fieldset
            className={
                className ??
                'grid gap-4 rounded-lg border bg-muted/30 p-4 sm:col-span-2'
            }
        >
            <legend className="px-1 text-sm font-medium">
                Details the {extension} registry needs
            </legend>
            {fields.map((field) => {
                const id = `${idPrefix}-${field.key}`;
                const error = errors[`tld_attributes.${field.key}`];
                const value = values[field.key] ?? '';

                return (
                    <div key={field.key} className="grid gap-2">
                        {field.type === 'boolean' ? (
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id={id}
                                    checked={value === '1' || value === 'true'}
                                    onCheckedChange={(checked) =>
                                        change(
                                            field.key,
                                            checked === true ? '1' : '0',
                                        )
                                    }
                                    aria-invalid={!!error}
                                    aria-describedby={`${id}-description`}
                                />
                                <Label
                                    htmlFor={id}
                                    className="leading-snug font-normal"
                                >
                                    {field.label}
                                </Label>
                            </div>
                        ) : (
                            <Field
                                htmlFor={id}
                                label={field.label}
                                optional={!field.required}
                            >
                                <SettingFieldControl
                                    id={id}
                                    field={field}
                                    value={value}
                                    invalid={!!error}
                                    onChange={(next) => change(field.key, next)}
                                />
                            </Field>
                        )}
                        <InputError id={`${id}-description`} message={error} />
                        {field.help && (
                            <p className="text-sm text-muted-foreground">
                                {field.help}
                            </p>
                        )}
                        {nativeInputs && (
                            <input
                                type="hidden"
                                name={`tld_attributes[${field.key}]`}
                                value={value}
                            />
                        )}
                    </div>
                );
            })}
        </fieldset>
    );
}
