import { Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import ClientSearchController from '@/actions/App/Modules/Billing/Http/Controllers/Admin/ClientSearchController';
import type { ClientOption } from './types';

type ClientPickerProps = {
    id: string;
    value: ClientOption | null;
    onChange: (client: ClientOption | null) => void;
    invalid?: boolean;
    placeholder?: string;
    /** Search endpoint for the term; defaults to the billing client search. */
    searchUrl?: (query: string) => string;
};

/**
 * Searchable client picker backed by the billing client search endpoint (no limit on how many clients exist).
 */
export function ClientPicker({
    id,
    value,
    onChange,
    invalid = false,
    placeholder = 'Search by name, company or email…',
    searchUrl = (q) => ClientSearchController.url({ query: { q } }),
}: ClientPickerProps) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<ClientOption[]>([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [active, setActive] = useState(-1);
    const timeout = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        if (timeout.current) {
            clearTimeout(timeout.current);
        }

        setLoading(true);
        timeout.current = setTimeout(async () => {
            const response = await fetch(searchUrl(query), {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            setResults(
                response.ok ? ((await response.json()) as ClientOption[]) : [],
            );
            setActive(-1);
            setLoading(false);
        }, 250);

        return () => {
            if (timeout.current) {
                clearTimeout(timeout.current);
            }
        };
    }, [query, open]);

    const choose = (client: ClientOption) => {
        onChange(client);
        setQuery('');
        setOpen(false);
    };

    // Keyboard: arrows move through the results, Enter picks, Escape closes (WCAG 2.1.1).
    const onKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            setOpen(true);
            setActive((current) =>
                results.length === 0
                    ? -1
                    : (current +
                          (event.key === 'ArrowDown' ? 1 : -1) +
                          results.length) %
                      results.length,
            );
        } else if (event.key === 'Enter' && open && results[active]) {
            event.preventDefault();
            choose(results[active]);
        } else if (event.key === 'Escape' && open) {
            event.preventDefault();
            event.stopPropagation();
            setOpen(false);
        }
    };

    if (value) {
        return (
            <div
                id={id}
                className="flex items-center justify-between gap-3 rounded-md border px-3 py-2 text-sm"
            >
                <span className="min-w-0 truncate">
                    {value.display_name}
                    {value.currency && (
                        <span className="ml-2 text-muted-foreground">
                            {value.currency}
                        </span>
                    )}
                </span>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label="Choose another client"
                    onClick={() => onChange(null)}
                >
                    <X />
                </Button>
            </div>
        );
    }

    return (
        <div className="relative">
            <Search
                className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                id={id}
                value={query}
                placeholder={placeholder}
                autoComplete="off"
                className="pl-8"
                aria-invalid={invalid || undefined}
                aria-describedby={`${id}-description`}
                aria-expanded={open}
                aria-controls={`${id}-results`}
                aria-activedescendant={
                    active >= 0 ? `${id}-option-${active}` : undefined
                }
                aria-autocomplete="list"
                role="combobox"
                // Opens when used, not on focus, so it never covers the fields below as a dialog opens.
                onClick={() => setOpen(true)}
                onKeyDown={onKeyDown}
                onBlur={() => setTimeout(() => setOpen(false), 150)}
                onChange={(event) => {
                    setQuery(event.target.value);
                    setOpen(true);
                }}
            />
            {open && (
                <ul
                    id={`${id}-results`}
                    role="listbox"
                    className={cn(
                        'absolute z-50 mt-1 max-h-64 w-full overflow-auto rounded-md border bg-popover p-1 text-sm shadow-md',
                    )}
                >
                    {loading && results.length === 0 ? (
                        <li
                            className="px-2 py-1.5 text-muted-foreground"
                            role="status"
                        >
                            Loading clients…
                        </li>
                    ) : results.length === 0 ? (
                        <li className="px-2 py-1.5 text-muted-foreground">
                            No clients found
                        </li>
                    ) : (
                        results.map((client, index) => (
                            <li
                                key={client.id}
                                id={`${id}-option-${index}`}
                                role="option"
                                aria-selected={index === active}
                            >
                                <button
                                    type="button"
                                    tabIndex={-1}
                                    className={cn(
                                        'flex w-full flex-col items-start rounded-sm px-2 py-1.5 text-left hover:bg-accent focus-visible:bg-accent focus-visible:outline-none',
                                        index === active && 'bg-accent',
                                    )}
                                    onMouseDown={(event) =>
                                        event.preventDefault()
                                    }
                                    onClick={() => choose(client)}
                                >
                                    <span>{client.display_name}</span>
                                    <span className="text-xs text-muted-foreground">
                                        {client.email} · {client.currency}
                                    </span>
                                </button>
                            </li>
                        ))
                    )}
                </ul>
            )}
        </div>
    );
}

/**
 * ClientPicker that keeps its own selection, for forms that only store the client id.
 */
export function ClientPickerField({
    id,
    initial = null,
    onSelect,
    invalid = false,
}: {
    id: string;
    initial?: ClientOption | null;
    onSelect: (client: ClientOption | null) => void;
    invalid?: boolean;
}) {
    const [value, setValue] = useState<ClientOption | null>(initial);

    return (
        <ClientPicker
            id={id}
            value={value}
            invalid={invalid}
            onChange={(client) => {
                setValue(client);
                onSelect(client);
            }}
        />
    );
}
