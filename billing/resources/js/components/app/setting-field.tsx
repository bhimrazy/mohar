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
};

type ControlProps = {
    id: string;
    field: SettingFieldData;
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
    invalid?: boolean;
};

/** The input for one integration setting. Secret values are write-only: the form never receives them. */
export function SettingFieldControl({
    id,
    field,
    value,
    onChange,
    disabled,
    invalid,
}: ControlProps) {
    const common = {
        id,
        disabled,
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
    /** A secret value is stored for this field; an empty input keeps it. */
    secretStored?: boolean;
    className?: string;
};

/** Label, control and hint for one integration setting. */
export function SettingFieldInput({
    error,
    secretStored = false,
    className,
    ...control
}: InputProps) {
    const { field, id } = control;

    return (
        <Field
            className={className}
            htmlFor={id}
            label={field.label}
            optional={!field.required}
            hint={
                field.secret && secretStored
                    ? 'Stored encrypted. Leave blank to keep it.'
                    : (field.help ?? undefined)
            }
            error={error}
        >
            <SettingFieldControl {...control} invalid={!!error} />
        </Field>
    );
}
