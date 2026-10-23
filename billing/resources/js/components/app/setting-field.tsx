import { useState } from 'react';
import { Field } from '@/components/app/field';
import { SelectField } from '@/components/app/select-field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';

/** Shape of App\Data\Integrations\SettingField::toArray(), shared by gateways, products and servers. */
export type SettingFieldData = {
    key: string;
    label: string;
    type:
        | 'string'
        | 'text'
        | 'number'
        | 'boolean'
        | 'select'
        | 'url'
        | 'secret'
        | 'secret_text';
    secret: boolean;
    required: boolean;
    help: string | null;
    /** value => label */
    options: Record<string, string>;
    default: string | number | boolean | null;
    /** "remote": options come from the server; the form shows a text input until they are loaded. */
    options_source: string | null;
    /** Shortest new value in bytes, for secret fields; null otherwise. */
    min_length: number | null;
};

/** The save refusal for a secret under its minimum, mirroring SecretMinimumLength. */
export function secretLengthError(
    field: SettingFieldData,
    value: string,
): string | undefined {
    const trimmed = value.trim();

    if (
        !field.secret ||
        field.min_length === null ||
        trimmed === '' ||
        new TextEncoder().encode(trimmed).length >= field.min_length
    ) {
        return undefined;
    }

    return `${field.label} must be at least ${field.min_length} characters. A shorter secret cannot be kept out of logs.`;
}

/** Hint for a stored secret saved before its minimum existed. */
export function shortSecretHint(minLength: number | null) {
    return (
        <span className="text-warning-text">
            The saved value is too short to keep out of logs. Replace it with
            one of at least {minLength} characters.
        </span>
    );
}

type ControlProps = {
    id: string;
    field: SettingFieldData;
    value: string;
    onChange: (value: string) => void;
    onBlur?: () => void;
    disabled?: boolean;
    invalid?: boolean;
};

/** The input for one integration setting. Secret values are write-only: the form never receives them. */
export function SettingFieldControl({
    id,
    field,
    value,
    onChange,
    onBlur,
    disabled,
    invalid,
}: ControlProps) {
    const common = {
        id,
        disabled,
        onBlur,
        'aria-invalid': invalid,
        'aria-describedby': `${id}-description`,
    };

    if (field.type === 'boolean') {
        return (
            <Switch
                {...common}
                checked={value === '1' || value === 'true'}
                onCheckedChange={(checked) => onChange(checked ? '1' : '0')}
            />
        );
    }

    if (field.type === 'select' && field.options_source === null) {
        return (
            <SelectField
                id={id}
                placeholder="Select…"
                value={value}
                onValueChange={onChange}
                disabled={disabled}
                invalid={invalid}
                options={Object.entries(field.options).map(
                    ([option, label]) => ({ value: option, label }),
                )}
            />
        );
    }

    if (field.type === 'text' || field.type === 'secret_text') {
        return (
            <Textarea
                {...common}
                rows={4}
                autoComplete="off"
                spellCheck={false}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        );
    }

    return (
        <Input
            {...common}
            type={
                field.type === 'secret'
                    ? 'password'
                    : field.type === 'number'
                      ? 'number'
                      : field.type === 'url'
                        ? 'url'
                        : 'text'
            }
            autoComplete={field.secret ? 'new-password' : 'off'}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        />
    );
}

type InputProps = ControlProps & {
    error?: string;
    /** A secret value is stored for this field (declared secret, or masked by name); an empty input keeps it. */
    secretStored?: boolean;
    /** The stored secret is shorter than the field's minimum: staff should replace it. */
    secretShort?: boolean;
    /** Show the minimum-length message once the field loses focus; on forms whose save refuses short secrets. */
    checkMinLength?: boolean;
    className?: string;
};

/** Label, control and hint for one integration setting. */
export function SettingFieldInput({
    error,
    secretStored = false,
    secretShort = false,
    checkMinLength = false,
    className,
    ...control
}: InputProps) {
    const { field, id } = control;
    const [touched, setTouched] = useState(false);
    const shown =
        error ??
        (checkMinLength && touched
            ? secretLengthError(field, control.value)
            : undefined);

    return (
        <Field
            className={className}
            htmlFor={id}
            label={field.label}
            optional={!field.required && field.type !== 'boolean'}
            hint={
                secretStored && secretShort
                    ? shortSecretHint(field.min_length)
                    : secretStored
                      ? 'Stored encrypted. Leave blank to keep it.'
                      : (field.help ?? undefined)
            }
            error={shown}
        >
            <SettingFieldControl
                {...control}
                onBlur={() => setTouched(true)}
                invalid={!!shown}
            />
        </Field>
    );
}
