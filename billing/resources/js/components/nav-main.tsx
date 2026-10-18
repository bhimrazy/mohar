import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { navIcon } from '@/lib/nav-icons';
import type { ServerNavGroup } from '@/types';

const STORAGE_KEY = 'sidebar_collapsed_groups';

function readCollapsed(): string[] {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);

        return raw ? (JSON.parse(raw) as string[]) : [];
    } catch {
        return [];
    }
}

function NavGroup({
    group,
    collapsible,
    open,
    onOpenChange,
}: {
    group: ServerNavGroup;
    collapsible: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const menu = (
        <SidebarMenu className="gap-0.5">
            {group.items.map((item) => {
                const Icon = navIcon(item.icon);

                return (
                    <SidebarMenuItem key={item.href}>
                        <SidebarMenuButton
                            asChild
                            isActive={item.active}
                            tooltip={{ children: item.title }}
                            className="h-(--nav-item-h)"
                        >
                            <Link href={item.href} prefetch>
                                <Icon />
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                        {!!item.badge && (
                            <SidebarMenuBadge className="top-1/2! -translate-y-1/2 rounded-badge bg-sidebar-accent px-1.5 text-[0.6875rem] text-sidebar-accent-foreground ring-1 ring-sidebar-border">
                                {item.badge > 99 ? '99+' : item.badge}
                                <span className="sr-only"> pending</span>
                            </SidebarMenuBadge>
                        )}
                    </SidebarMenuItem>
                );
            })}
        </SidebarMenu>
    );

    if (!collapsible) {
        return (
            <SidebarGroup className="px-2 py-1">
                <SidebarGroupLabel className="eyebrow">
                    {group.title}
                </SidebarGroupLabel>
                <SidebarGroupContent>{menu}</SidebarGroupContent>
            </SidebarGroup>
        );
    }

    const total = group.items.reduce((sum, item) => sum + (item.badge ?? 0), 0);

    return (
        <Collapsible open={open} onOpenChange={onOpenChange} asChild>
            <SidebarGroup className="px-2 py-1">
                <SidebarGroupLabel asChild className="eyebrow">
                    <CollapsibleTrigger className="group/label w-full hover:text-sidebar-foreground">
                        {group.title}
                        {!open && total > 0 && (
                            <span className="ml-1.5 rounded-badge bg-sidebar-accent px-1 text-[0.625rem] text-sidebar-accent-foreground normal-case tabular-nums">
                                {total}
                            </span>
                        )}
                        <ChevronDown
                            aria-hidden="true"
                            className="ml-auto size-3.5! opacity-0 transition-[transform,opacity] group-hover/label:opacity-100 group-focus-visible/label:opacity-100 group-data-[state=closed]/label:-rotate-90 group-data-[state=closed]/label:opacity-100"
                        />
                    </CollapsibleTrigger>
                </SidebarGroupLabel>
                <CollapsibleContent>
                    <SidebarGroupContent>{menu}</SidebarGroupContent>
                </CollapsibleContent>
            </SidebarGroup>
        </Collapsible>
    );
}

/**
 * Server-driven sidebar navigation: grouped, collapsible sections (state remembered per
 * browser) and count badges from NavItem badges. The first group is always open.
 */
export function NavMain({ groups }: { groups: ServerNavGroup[] }) {
    const [collapsed, setCollapsed] = useState<string[]>(readCollapsed);

    const toggle = (title: string, open: boolean) => {
        const next = open
            ? collapsed.filter((item) => item !== title)
            : [...collapsed, title];
        setCollapsed(next);

        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
        } catch {
            // Ignore: collapsing still works for this page view.
        }
    };

    return (
        <>
            {groups.map((group, index) => {
                const hasActive = group.items.some((item) => item.active);

                return (
                    <NavGroup
                        key={group.title}
                        group={group}
                        collapsible={index > 0}
                        open={hasActive || !collapsed.includes(group.title)}
                        onOpenChange={(open) => toggle(group.title, open)}
                    />
                );
            })}
        </>
    );
}
