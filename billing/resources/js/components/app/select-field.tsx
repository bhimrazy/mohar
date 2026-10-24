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

const fold = (text: string) =>
    text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();

/** Options matching the query in label or keywords, ignoring case and accents; names starting with it come first. */
export function filterOptions(
    options: SelectOption[],
    query: string,
): SelectOption[] {
    const needle = fold(query.trim());

    if (!needle) {
        return options;
    }

    const rank = (option: SelectOption) => {
        const label = fold(option.label);
        const words = fold(option.keywords ?? '').split(/\s+/);

        if (label.startsWith(needle)) {
            return 0;
        }

        if (words.some((word) => word === needle)) {
            return 1;
        }

        if (
            label.split(/[\s-]+/).some((word) => word.startsWith(needle)) ||
            words.some((word) => word.startsWith(needle))
        ) {
            return 2;
        }

        return label.includes(needle) ? 3 : -1;
    };

    return options
        .map((option, index) => ({ option, index, rank: rank(option) }))
        .filter((entry) => entry.rank >= 0)
        .sort((a, b) => a.rank - b.rank || a.index - b.index)
        .map((entry) => entry.option);
}

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
        top?: number;
        bottom?: number;
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
    const shown = filterOptions(all, query);

    const show = (text: string) => {
        const list = filterOptions(all, text);
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

            if (!rect) {
                return;
            }

            // Scrolled under a sticky header or out of its scroll container: close rather than draw over it.
            const centre = document.elementFromPoint(
                rect.left + rect.width / 2,
                rect.top + rect.height / 2,
            );

            if (centre !== input.current) {
                setOpen(false);
                setQuery('');

                return;
            }

            const below = window.innerHeight - rect.bottom - 12;
            const above = rect.top - 12;
            // Open upward when the list would not fit below and there is more room above.
            const up = below < 256 && above > below;
            const height = Math.min(256, up ? above : below);

            setBox({
                ...(up
                    ? { bottom: window.innerHeight - rect.top + 4 }
                    : { top: rect.bottom + 4 }),
                left: rect.left,
                width: rect.width,
                height,
            });
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
                    aria-label={
                        document
                            .querySelector(`label[for="${id}"]`)
                            ?.textContent?.replace(/\s*\*$/, '')
                            .trim() ||
                        placeholder ||
                        'Options'
                    }
                    style={{
                        top: box.top,
                        bottom: box.bottom,
                        left: box.left,
                        width: box.width,
                        maxHeight: box.height,
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
