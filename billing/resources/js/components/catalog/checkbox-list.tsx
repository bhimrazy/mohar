import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';

export type CheckboxListItem = {
    id: number;
    label: string;
    /** Secondary text, e.g. the product group. */
    hint?: string;
    /** Extra inline content, e.g. a status badge. */
    meta?: ReactNode;
};

type CheckboxListProps = {
    items: CheckboxListItem[];
    selected: number[];
    onChange: (selected: number[]) => void;
    /** Accessible name of the list. */
    label: string;
    idPrefix: string;
    disabled?: boolean;
    empty?: ReactNode;
    searchable?: boolean;
};

/** Scrollable multi-select list with an optional filter box. */
export function CheckboxList({
    items,
    selected,
    onChange,
    label,
    idPrefix,
    disabled,
    empty,
    searchable = items.length > 8,
}: CheckboxListProps) {
    const [filter, setFilter] = useState('');
    const visible = useMemo(() => {
        const term = filter.trim().toLowerCase();

        return term
            ? items.filter((item) =>
                  `${item.label} ${item.hint ?? ''}`
                      .toLowerCase()
                      .includes(term),
              )
            : items;
    }, [filter, items]);

    const toggle = (id: number, checked: boolean) =>
        onChange(
            checked
                ? [...selected, id]
                : selected.filter((current) => current !== id),
        );

    if (items.length === 0) {
        return <>{empty}</>;
    }

    return (
        <div className="grid gap-2">
            {searchable && (
                <div className="relative">
                    <Search
                        className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        type="search"
                        value={filter}
                        onChange={(event) => setFilter(event.target.value)}
                        placeholder={`Filter ${label.toLowerCase()}…`}
                        aria-label={`Filter ${label.toLowerCase()}`}
                        className="pl-8"
                    />
                </div>
            )}
            <ul
                aria-label={label}
                className="max-h-80 divide-y overflow-y-auto rounded-lg border"
            >
                {visible.map((item) => {
                    const id = `${idPrefix}-${item.id}`;

                    return (
                        <li
                            key={item.id}
                            className="flex items-center gap-3 px-3 py-2.5"
                        >
                            <Checkbox
                                id={id}
                                checked={selected.includes(item.id)}
                                disabled={disabled}
                                onCheckedChange={(checked) =>
                                    toggle(item.id, checked === true)
                                }
                            />
                            <label
                                htmlFor={id}
                                className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 text-sm"
                            >
                                <span className="font-medium">
                                    {item.label}
                                </span>
                                {item.hint && (
                                    <span className="text-muted-foreground">
                                        {item.hint}
                                    </span>
                                )}
                            </label>
                            {item.meta}
                        </li>
                    );
                })}
                {visible.length === 0 && (
                    <li className="px-3 py-6 text-center text-sm text-muted-foreground">
                        Nothing matches “{filter}”.
                    </li>
                )}
            </ul>
            <p className="text-xs text-muted-foreground">
                {selected.length} selected
            </p>
        </div>
    );
}
