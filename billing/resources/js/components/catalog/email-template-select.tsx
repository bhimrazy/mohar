import { SelectField } from '@/components/app/select-field';
import { Input } from '@/components/ui/input';
import type { SelectOption } from '@/types/app';

type EmailTemplateSelectProps = {
    id: string;
    value: string;
    onChange: (value: string) => void;
    /** Client templates from the Notifications module; empty when it is not installed. */
    templates: SelectOption[];
    disabled?: boolean;
    invalid?: boolean;
};

/**
 * Pick an email template by name ("Default" = the built-in choice). Keeps a stored key that no longer
 * matches a template visible, and falls back to a free-text key without the Notifications module.
 */
export function EmailTemplateSelect({
    id,
    value,
    onChange,
    templates,
    disabled,
    invalid,
}: EmailTemplateSelectProps) {
    if (templates.length === 0) {
        return (
            <Input
                id={id}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                disabled={disabled}
                aria-invalid={invalid}
                className="font-mono"
            />
        );
    }

    const options =
        value !== '' && !templates.some((template) => template.value === value)
            ? [...templates, { value, label: `${value} (template not found)` }]
            : templates;

    return (
        <SelectField
            id={id}
            options={options}
            value={value}
            onValueChange={onChange}
            emptyLabel="Default"
            placeholder="Default"
            disabled={disabled}
            invalid={invalid}
        />
    );
}
