import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { CommandPalette } from '@/components/app/command-palette';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { usePageBreadcrumbs } from '@/hooks/use-page-breadcrumbs';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs: declared = [],
    actions,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    /** Right-aligned header tools (quick create, notifications, …). */
    actions?: ReactNode;
}) {
    const { area } = usePage().props;
    const breadcrumbs = usePageBreadcrumbs(declared);

    return (
        <header className="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-2 border-b bg-background/85 px-4 backdrop-blur-sm transition-[width,height] ease-linear md:rounded-t-(--shell-radius) md:px-6">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                {breadcrumbs.length > 0 && (
                    <Separator
                        orientation="vertical"
                        className="mr-1 data-[orientation=vertical]:h-4"
                    />
                )}
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            {(actions || area === 'admin') && (
                <div className="ml-auto flex items-center gap-1.5">
                    {area === 'admin' && <CommandPalette />}
                    {actions}
                </div>
            )}
        </header>
    );
}
