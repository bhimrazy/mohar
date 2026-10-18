import { router } from '@inertiajs/react';
import { CornerDownLeft, Plus, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Kbd } from '@/components/app/kbd';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { SidebarMenuButton } from '@/components/ui/sidebar';
import { navIcon } from '@/lib/nav-icons';
import { cn } from '@/lib/utils';
import type { QuickAction, ServerNavGroup } from '@/types';

type Command = {
    id: string;
    title: string;
    group: string;
    href: string;
    icon: string;
    create?: boolean;
};

/**
 * ⌘K / Ctrl+K command palette: jump to any page in the sidebar or run a quick-create
 * action. Lightweight (no extra dependency); records search can plug in later.
 */
export function NavSearch({
    groups,
    quickActions = [],
}: {
    groups: ServerNavGroup[];
    quickActions?: QuickAction[];
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);

    useEffect(() => {
        const onKey = (event: globalThis.KeyboardEvent) => {
            if (
                event.key.toLowerCase() === 'k' &&
                (event.metaKey || event.ctrlKey)
            ) {
                event.preventDefault();
                setOpen((value) => !value);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const commands = useMemo<Command[]>(
        () => [
            ...quickActions.map((action) => ({
                id: `create:${action.href}`,
                title: action.title,
                group: 'Create',
                href: action.href,
                icon: action.icon,
                create: true,
            })),
            ...groups.flatMap((group) =>
                group.items.map((item) => ({
                    id: item.href,
                    title: item.title,
                    group: group.title,
                    href: item.href,
                    icon: item.icon,
                })),
            ),
        ],
        [groups, quickActions],
    );

    const results = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return needle === ''
            ? commands
            : commands.filter((command) =>
                  `${command.title} ${command.group}`
                      .toLowerCase()
                      .includes(needle),
              );
    }, [commands, query]);

    const go = (command: Command | undefined) => {
        if (!command) {
            return;
        }

        setOpen(false);
        router.visit(command.href);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((index) => Math.min(index + 1, results.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((index) => Math.max(index - 1, 0));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            go(results[active]);
        }
    };

    return (
        <>
            <SidebarMenuButton
                onClick={() => setOpen(true)}
                tooltip={{ children: 'Search (⌘K)' }}
                className="h-8 border border-sidebar-border bg-sidebar-accent/40 text-sidebar-foreground/80 shadow-none hover:bg-sidebar-accent"
            >
                <Search />
                <span className="flex-1">Search…</span>
                <Kbd className="bg-sidebar group-data-[collapsible=icon]:hidden">
                    ⌘K
                </Kbd>
            </SidebarMenuButton>

            <Dialog
                open={open}
                onOpenChange={(value) => {
                    setOpen(value);
                    setQuery('');
                    setActive(0);
                }}
            >
                <DialogContent className="top-[20%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-xl [&>button:last-child]:hidden">
                    <DialogTitle className="sr-only">Search</DialogTitle>
                    <DialogDescription className="sr-only">
                        Jump to a page or create a record.
                    </DialogDescription>
                    <div className="flex items-center gap-2 border-b px-4">
                        <Search
                            className="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <input
                            autoFocus
                            value={query}
                            onChange={(event) => {
                                setQuery(event.target.value);
                                setActive(0);
                            }}
                            onKeyDown={onKeyDown}
                            placeholder="Search pages and actions…"
                            aria-label="Search pages and actions"
                            className="h-12 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        />
                        <Kbd>Esc</Kbd>
                    </div>
                    <ul
                        role="listbox"
                        className="max-h-80 overflow-y-auto p-2"
                        aria-label="Results"
                    >
                        {results.length === 0 && (
                            <li className="px-3 py-6 text-center text-sm text-muted-foreground">
                                No pages match “{query}”.
                            </li>
                        )}
                        {results.map((command, index) => {
                            const Icon = command.create
                                ? Plus
                                : navIcon(command.icon);

                            return (
                                <li
                                    key={command.id}
                                    role="option"
                                    aria-selected={index === active}
                                    onMouseEnter={() => setActive(index)}
                                    onClick={() => go(command)}
                                    className={cn(
                                        'flex cursor-pointer items-center gap-3 rounded-md px-3 py-2 text-sm',
                                        index === active &&
                                            'bg-accent text-accent-foreground',
                                    )}
                                >
                                    <Icon
                                        className="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <span className="flex-1 truncate">
                                        {command.title}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {command.group}
                                    </span>
                                    {index === active && (
                                        <CornerDownLeft
                                            className="size-3.5 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </DialogContent>
            </Dialog>
        </>
    );
}
