import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
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

/** Above this many options the list becomes a searchable combobox. */
const SEARCH_FROM = 30;

/** Radix select that works inside a plain Inertia <Form>; long lists get a search box. */
export function SelectField(props: SelectFieldProps) {
    return props.options.length > SEARCH_FROM ? (
        <SearchableSelect {...props} />
    ) : (
        <PlainSelect {...props} />
    );
}

function PlainSelect({
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

function SearchableSelect({
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
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const value = controlled ?? internal;

    const all: SelectOption[] = emptyLabel
        ? [{ value: '', label: emptyLabel }, ...options]
        : options;
    const selected = all.find((option) => option.value === value);
    const needle = query.trim().toLowerCase();
    const shown = needle
        ? all.filter((option) => option.label.toLowerCase().includes(needle))
        : all;

    const choose = (option: SelectOption) => {
        setInternal(option.value);
        onValueChange?.(option.value);
        setQuery('');
        setOpen(false);
    };

    const onKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            setOpen(true);
            setActive((current) =>
                shown.length === 0
                    ? 0
                    : (current +
                          (event.key === 'ArrowDown' ? 1 : -1) +
                          shown.length) %
                      shown.length,
            );
        } else if (event.key === 'Enter' && open && shown[active]) {
            event.preventDefault();
            choose(shown[active]);
        } else if (event.key === 'Escape' && open) {
            event.preventDefault();
            event.stopPropagation();
            setQuery('');
            setOpen(false);
        }
    };

    return (
        <div className={cn('relative', className ?? 'w-full')}>
            <Input
                id={id}
                role="combobox"
                autoComplete="off"
                disabled={disabled}
                className="pr-8"
                value={open ? query : (selected?.label ?? '')}
                placeholder={
                    open ? (selected?.label ?? placeholder) : placeholder
                }
                aria-invalid={invalid || undefined}
                aria-describedby={`${id}-description`}
                aria-expanded={open}
                aria-controls={`${id}-options`}
                aria-autocomplete="list"
                aria-activedescendant={
                    open && shown[active] ? `${id}-option-${active}` : undefined
                }
                onFocus={() => setOpen(true)}
                onClick={() => setOpen(true)}
                onKeyDown={onKeyDown}
                onBlur={() => {
                    setOpen(false);
                    setQuery('');
                }}
                onChange={(event) => {
                    setQuery(event.target.value);
                    setActive(0);
                    setOpen(true);
                }}
            />
            <ChevronDown
                className="pointer-events-none absolute top-1/2 right-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            {open && (
                <ul
                    id={`${id}-options`}
                    role="listbox"
                    className="absolute z-50 mt-1 max-h-64 w-full overflow-auto rounded-md border bg-popover p-1 text-sm text-popover-foreground shadow-md"
                >
                    {shown.length === 0 ? (
                        <li className="px-2 py-1.5 text-muted-foreground">
                            No matches
                        </li>
                    ) : (
                        shown.map((option, index) => (
                            <li
                                key={option.value}
                                id={`${id}-option-${index}`}
                                role="option"
                                aria-selected={option.value === value}
                                className={cn(
                                    'cursor-pointer rounded-sm px-2 py-1.5',
                                    index === active && 'bg-accent',
                                    option.value === value && 'font-medium',
                                )}
                                ref={(element) => {
                                    if (index === active) {
                                        element?.scrollIntoView?.({
                                            block: 'nearest',
                                        });
                                    }
                                }}
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => choose(option)}
                            >
                                {option.label}
                            </li>
                        ))
                    )}
                </ul>
            )}
            {name && <input type="hidden" name={name} value={value} />}
        </div>
    );
}
