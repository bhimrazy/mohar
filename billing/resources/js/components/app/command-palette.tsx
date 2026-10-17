import { router } from '@inertiajs/react';
import { CornerDownLeft, Search } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import SearchController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/SearchController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

type SearchResult = { title: string; subtitle?: string; href: string };
type SearchGroup = { group: string; results: SearchResult[] };

const MIN_LENGTH = 2;

/**
 * Admin global search (⌘K / Ctrl+K). Backed by GET /admin/search, which only
 * returns groups the signed-in staff member may view.
 */
export function CommandPalette() {
    const [open, setOpen] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [query, setQuery] = useState('');
    const [groups, setGroups] = useState<SearchGroup[]>([]);
    const [loading, setLoading] = useState(false);
    const [active, setActive] = useState(0);
    const abort = useRef<AbortController | null>(null);

    useEffect(() => {
        const onKeyDown = (event: globalThis.KeyboardEvent) => {
            if (
                (event.metaKey || event.ctrlKey) &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();
                setOpen((value) => !value);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    useEffect(() => {
        const term = query.trim();

        if (term.length < MIN_LENGTH) {
            abort.current?.abort();

            return;
        }

        const timeout = setTimeout(() => {
            abort.current?.abort();
            const controller = new AbortController();
            abort.current = controller;
            setLoading(true);

            fetch(SearchController.url({ query: { q: term } }), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then(async (response) => {
                    if (!response.ok) {
                        throw new Error(
                            response.status === 429
                                ? 'Too many searches. Wait a moment and try again.'
                                : 'Search failed. Try again.',
                        );
                    }

                    return (await response.json()) as { groups: SearchGroup[] };
                })
                .then((data) => {
                    setError(null);
                    setGroups(data.groups);
                    setActive(0);
                })
                .catch((reason: unknown) => {
                    if (
                        reason instanceof DOMException &&
                        reason.name === 'AbortError'
                    ) {
                        return;
                    }

                    setGroups([]);
                    setError(
                        reason instanceof Error
                            ? reason.message
                            : 'Search failed. Try again.',
                    );
                })
                .finally(() => {
                    if (abort.current === controller) {
                        setLoading(false);
                    }
                });
        }, 200);

        return () => clearTimeout(timeout);
    }, [query]);

    const term = query.trim();
    const shownGroups = useMemo(
        () => (term.length >= MIN_LENGTH ? groups : []),
        [groups, term],
    );
    const flat = useMemo(
        () => shownGroups.flatMap((group) => group.results),
        [shownGroups],
    );

    const close = useCallback(() => {
        setOpen(false);
        setQuery('');
        setGroups([]);
    }, []);

    const go = (result: SearchResult | undefined) => {
        if (!result) {
            return;
        }

        close();
        router.visit(result.href);
    };

    const onInputKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((index) => Math.min(index + 1, flat.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((index) => Math.max(index - 1, 0));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            go(flat[active]);
        }
    };

    let index = -1;

    return (
        <>
            <Button
                variant="outline"
                size="sm"
                onClick={() => setOpen(true)}
                className="w-9 justify-start px-0 text-muted-foreground sm:w-64 sm:px-3"
                aria-label="Search"
                data-test="command-palette-trigger"
            >
                <Search className="mx-auto sm:mx-0" />
                <span className="hidden sm:inline">Search clients, staff…</span>
                <kbd className="pointer-events-none ml-auto hidden rounded border bg-muted px-1.5 font-mono text-[10px] font-medium sm:inline">
                    ⌘K
                </kbd>
            </Button>

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? setOpen(true) : close())}
            >
                <DialogContent className="top-[20%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-xl">
                    <DialogTitle className="sr-only">Search</DialogTitle>
                    <DialogDescription className="sr-only">
                        Search clients, contacts, staff and other records.
                    </DialogDescription>
                    <div className="flex items-center gap-2 border-b px-3">
                        <Search
                            className="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <input
                            autoFocus
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={onInputKeyDown}
                            placeholder="Search by name, email, number…"
                            aria-label="Search"
                            role="combobox"
                            aria-expanded={flat.length > 0}
                            aria-controls="command-palette-results"
                            aria-activedescendant={
                                flat.length
                                    ? `command-option-${active}`
                                    : undefined
                            }
                            className="h-12 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        />
                        {loading && <Spinner />}
                    </div>

                    <div
                        id="command-palette-results"
                        role="listbox"
                        className="max-h-96 overflow-y-auto p-2"
                    >
                        {error && (
                            <p
                                role="alert"
                                className="px-2 py-3 text-center text-sm text-destructive"
                            >
                                {error}
                            </p>
                        )}
                        {term.length < MIN_LENGTH &&
                        shownGroups.length === 0 ? (
                            <p className="px-2 py-6 text-center text-sm text-muted-foreground">
                                Type at least {MIN_LENGTH} characters to search.
                            </p>
                        ) : shownGroups.length === 0 && !loading && !error ? (
                            <p className="px-2 py-6 text-center text-sm text-muted-foreground">
                                No results for “{term}”.
                            </p>
                        ) : (
                            shownGroups.map((group) => (
                                <div key={group.group} className="py-1">
                                    <div className="px-2 py-1.5 text-xs font-medium text-muted-foreground">
                                        {group.group}
                                    </div>
                                    {group.results.map((result) => {
                                        index += 1;
                                        const position = index;

                                        return (
                                            <button
                                                key={`${group.group}-${result.href}-${position}`}
                                                id={`command-option-${position}`}
                                                type="button"
                                                role="option"
                                                aria-selected={
                                                    position === active
                                                }
                                                onMouseEnter={() =>
                                                    setActive(position)
                                                }
                                                onClick={() => go(result)}
                                                className={cn(
                                                    'flex w-full items-center gap-3 rounded-md px-2 py-2 text-left text-sm',
                                                    position === active &&
                                                        'bg-accent text-accent-foreground',
                                                )}
                                            >
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate font-medium">
                                                        {result.title}
                                                    </span>
                                                    {result.subtitle && (
                                                        <span className="block truncate text-xs text-muted-foreground">
                                                            {result.subtitle}
                                                        </span>
                                                    )}
                                                </span>
                                                {position === active && (
                                                    <CornerDownLeft
                                                        className="size-3.5 text-muted-foreground"
                                                        aria-hidden="true"
                                                    />
                                                )}
                                            </button>
                                        );
                                    })}
                                </div>
                            ))
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
