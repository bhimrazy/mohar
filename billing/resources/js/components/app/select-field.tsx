import { useState } from 'react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { SelectOption } from '@/types/app';

const EMPTY = '__none__';

type SelectFieldProps = {
    id: string;
    /** Form field name, submitted through a hidden input ('' when empty). */
    name?: string;
    options: SelectOption[];
    defaultValue?: string | null;
    value?: string;
    onValueChange?: (value: string) => void;
    placeholder?: string;
    /** Adds a first option that submits an empty value, e.g. "No group". */
    emptyLabel?: string;
    invalid?: boolean;
    disabled?: boolean;
    className?: string;
};

/** Radix select that works inside a plain Inertia <Form>. */
export function SelectField({
    id,
    name,
    options,
    defaultValue,
    value: controlled,
    onValueChange,
    placeholder,
    emptyLabel,
    invalid,
    disabled,
    className,
}: SelectFieldProps) {
    const [internal, setInternal] = useState(defaultValue ?? '');
    const value = controlled ?? internal;

    return (
        <>
            <Select
                value={value === '' ? (emptyLabel ? EMPTY : undefined) : value}
                disabled={disabled}
                onValueChange={(next) => {
                    const normalized = next === EMPTY ? '' : next;
                    setInternal(normalized);
                    onValueChange?.(normalized);
                }}
            >
                <SelectTrigger
                    id={id}
                    className={className ?? 'w-full'}
                    aria-invalid={invalid || undefined}
                    aria-describedby={`${id}-description`}
                >
                    <SelectValue placeholder={placeholder} />
                </SelectTrigger>
                <SelectContent>
                    {emptyLabel && (
                        <SelectItem value={EMPTY}>{emptyLabel}</SelectItem>
                    )}
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            {name && <input type="hidden" name={name} value={value} />}
        </>
    );
}
