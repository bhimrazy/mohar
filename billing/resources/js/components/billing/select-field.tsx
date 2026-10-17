import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { SelectOption } from '@/types/app';

type SelectFieldProps = {
    id: string;
    value: string;
    onChange: (value: string) => void;
    options: SelectOption[];
    placeholder?: string;
    invalid?: boolean;
    disabled?: boolean;
    className?: string;
};

/** Controlled select for useForm-driven forms (pair with <Field htmlFor={id}>). */
export function SelectField({
    id,
    value,
    onChange,
    options,
    placeholder = 'Select…',
    invalid = false,
    disabled = false,
    className,
}: SelectFieldProps) {
    return (
        <Select value={value} onValueChange={onChange} disabled={disabled}>
            <SelectTrigger
                id={id}
                aria-invalid={invalid || undefined}
                aria-describedby={`${id}-description`}
                className={className ?? 'w-full'}
            >
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
