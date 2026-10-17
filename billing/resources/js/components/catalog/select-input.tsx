import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { SelectOption } from '@/types/app';

type SelectInputProps = {
    id: string;
    value: string;
    onChange: (value: string) => void;
    options: SelectOption[];
    placeholder?: string;
    disabled?: boolean;
    invalid?: boolean;
};

/** Controlled select for useForm-driven forms. */
export function SelectInput({
    id,
    value,
    onChange,
    options,
    placeholder,
    disabled,
    invalid,
}: SelectInputProps) {
    return (
        <Select value={value} onValueChange={onChange} disabled={disabled}>
            <SelectTrigger
                id={id}
                className="w-full"
                aria-invalid={invalid || undefined}
                aria-describedby={`${id}-description`}
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
