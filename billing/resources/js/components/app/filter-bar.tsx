import { Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { useQueryParams } from '@/components/app/use-query-params';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { SelectOption } from '@/types/app';

const ALL = '__all__';

type FilterBarProps = {
    /** Query-string key for the search box. Pass `null` to hide search. */
    searchParam?: string | null;
    searchPlaceholder?: string;
    /** <FilterSelect> elements (or any control using useQueryParams). */
    filters?: ReactNode;
    /** Right-aligned extras, e.g. an "Export CSV" button. */
    actions?: ReactNode;
    /** Partial reload: only refetch these props (e.g. ['invoices']). */
    only?: string[];
    /** Query keys that count as "active filters" for the Clear button. */
    filterKeys?: string[];
    debounceMs?: number;
    className?: string;
};

export function FilterBar({
    searchParam = 'search',
    searchPlaceholder = 'Search…',
    filters,
    actions,
    only,
    filterKeys = [],
    debounceMs = 300,
    className,
}: FilterBarProps) {
    const { params, apply } = useQueryParams();
    const urlSearch = searchParam ? (params.get(searchParam) ?? '') : '';
    const [search, setSearch] = useState(urlSearch);
    const timeout = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        return () => {
            if (timeout.current) {
                clearTimeout(timeout.current);
            }
        };
    }, []);

    const onSearchChange = (value: string) => {
        setSearch(value);

        if (timeout.current) {
            clearTimeout(timeout.current);
        }

        timeout.current = setTimeout(() => {
            if (searchParam) {
                apply({ [searchParam]: value.trim() }, { only });
            }
        }, debounceMs);
    };

    const activeKeys = [...(searchParam ? [searchParam] : []), ...filterKeys];
    const hasActiveFilters = activeKeys.some((key) => params.get(key));

    const clear = () => {
        setSearch('');
        apply(Object.fromEntries(activeKeys.map((key) => [key, null])), {
            only,
        });
    };

    return (
        <div
            data-slot="filter-bar"
            role="search"
            className={cn(
                'flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center',
                className,
            )}
        >
            {searchParam && (
                <div className="relative w-full sm:max-w-xs">
                    <Search
                        className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        type="search"
                        value={search}
                        onChange={(event) => onSearchChange(event.target.value)}
                        placeholder={searchPlaceholder}
                        aria-label={searchPlaceholder.replace(/…$/, '')}
                        className="pl-8"
                    />
                </div>
            )}

            {filters}

            {hasActiveFilters && (
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={clear}
                    className="w-fit text-muted-foreground"
                >
                    <X />
                    Clear filters
                </Button>
            )}

            {actions && (
                <div className="flex items-center gap-2 sm:ml-auto">
                    {actions}
                </div>
            )}
        </div>
    );
}

type FilterSelectProps = {
    /** Query-string key, e.g. "status". */
    name: string;
    /** Accessible label, also used for the "All …" option. */
    label: string;
    options: SelectOption[];
    /** Text of the reset option. Defaults to "All {label}". */
    allLabel?: string;
    only?: string[];
    className?: string;
};

export function FilterSelect({
    name,
    label,
    options,
    allLabel,
    only,
    className,
}: FilterSelectProps) {
    const { params, apply } = useQueryParams();
    const value = params.get(name) ?? ALL;

    return (
        <Select
            value={value}
            onValueChange={(next) =>
                apply({ [name]: next === ALL ? null : next }, { only })
            }
        >
            <SelectTrigger
                aria-label={label}
                className={cn(
                    'w-full sm:w-44',
                    value !== ALL && 'border-foreground/30',
                    className,
                )}
            >
                <SelectValue placeholder={label} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>
                    {allLabel ?? `All ${label.toLowerCase()}`}
                </SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
