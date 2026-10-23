import { ChevronDown } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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
    /** A search box that filters the options; for long lists such as time zones. */
    searchable?: boolean;
};

/** Radix select that works inside a plain Inertia <Form>; `searchable` swaps in a filterable combobox for long lists. */
export function SelectField(props: SelectFieldProps) {
    return props.searchable ? (
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
    const [box, setBox] = useState<{
        top: number;
        left: number;
        width: number;
        height: number;
    }>();
    const input = useRef<HTMLInputElement>(null);
    const value = controlled ?? internal;

    const all: SelectOption[] = emptyLabel
        ? [{ value: '', label: emptyLabel }, ...options]
        : options;
    const selected = all.find((option) => option.value === value);
    const needle = query.trim().toLowerCase();
    const shown = needle
        ? all.filter((option) => option.label.toLowerCase().includes(needle))
        : all;

    const show = (text: string) => {
        const list = text.trim()
            ? all.filter((option) =>
                  option.label
                      .toLowerCase()
                      .includes(text.trim().toLowerCase()),
              )
            : all;
        const at = list.findIndex((option) => option.value === value);

        setActive(Math.max(at, 0));
        setOpen(true);
    };

    // The list is fixed-positioned under the input, so a dialog or an overflow-hidden card does not clip it.
    useEffect(() => {
        if (!open) {
            return;
        }

        const place = () => {
            const rect = input.current?.getBoundingClientRect();

            if (rect) {
                setBox({
                    top: rect.bottom + 4,
                    left: rect.left,
                    width: rect.width,
                    height: Math.min(
                        256,
                        window.innerHeight - rect.bottom - 12,
                    ),
                });
            }
        };

        place();
        window.addEventListener('resize', place);
        window.addEventListener('scroll', place, true);

        return () => {
            window.removeEventListener('resize', place);
            window.removeEventListener('scroll', place, true);
        };
    }, [open]);

    useEffect(() => {
        if (open) {
            document
                .getElementById(`${id}-option-${active}`)
                ?.scrollIntoView({ block: 'nearest' });
        }
    }, [open, active, id, box]);

    const close = () => {
        setOpen(false);
        setQuery('');
    };

    const choose = (option: SelectOption) => {
        setInternal(option.value);
        onValueChange?.(option.value);
        close();
    };

    const onKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();

            if (!open) {
                show(query);

                return;
            }

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
            close();
        }
    };

    return (
        <div className={cn('relative', className ?? 'w-full')}>
            <Input
                ref={input}
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
                aria-controls={open ? `${id}-options` : undefined}
                aria-autocomplete="list"
                aria-activedescendant={
                    open && shown[active] ? `${id}-option-${active}` : undefined
                }
                onClick={() => !open && show(query)}
                onKeyDown={onKeyDown}
                onBlur={close}
                onChange={(event) => {
                    setQuery(event.target.value);
                    show(event.target.value);
                    setActive(0);
                }}
            />
            <ChevronDown
                className="pointer-events-none absolute top-1/2 right-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <span role="status" aria-live="polite" className="sr-only">
                {open
                    ? shown.length === 0
                        ? 'No matches'
                        : `${shown.length} ${shown.length === 1 ? 'result' : 'results'}`
                    : ''}
            </span>
            {open && box && (
                <ul
                    id={`${id}-options`}
                    role="listbox"
                    aria-label={placeholder ?? 'Options'}
                    style={{
                        top: box.top,
                        left: box.left,
                        width: box.width,
                        maxHeight: Math.max(box.height, 96),
                    }}
                    className="fixed z-50 overflow-auto rounded-md border bg-popover p-1 text-sm text-popover-foreground shadow-md"
                    onMouseDown={(event) => event.preventDefault()}
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
                                onMouseMove={() => setActive(index)}
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
