import { Link, usePage } from '@inertiajs/react';
import { showsPublicPageInClientArea } from '@/lib/layout-area';
import { cn } from '@/lib/utils';
import { index as announcements } from '@/routes/support/announcements';
import { index as kb } from '@/routes/support/kb';
import { index as network } from '@/routes/support/network';
import { create as openTicket } from '@/routes/support/tickets';

const LINKS = [
    { title: 'Knowledge base', href: kb.url(), prefix: '/knowledgebase' },
    {
        title: 'Announcements',
        href: announcements.url(),
        prefix: '/announcements',
    },
    { title: 'Network status', href: network.url(), prefix: '/network-status' },
    {
        title: 'Contact support',
        href: openTicket.url(),
        prefix: '/support/tickets',
    },
];

/**
 * Section tabs for public support pages (guests see no sidebar navigation).
 */
export function PublicSupportNav() {
    const { url, component, props } = usePage();

    // Inside the client area the sidebar already links to these pages.
    if (showsPublicPageInClientArea(component, props)) {
        return null;
    }

    return (
        <nav
            aria-label="Support"
            className="-mx-1 flex gap-1 overflow-x-auto border-b pb-px"
        >
            {LINKS.map((link) => {
                const active = url.startsWith(link.prefix);

                return (
                    <Link
                        key={link.href}
                        href={link.href}
                        aria-current={active ? 'page' : undefined}
                        className={cn(
                            '-mb-px shrink-0 border-b-2 px-3 py-2 text-sm transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                            active
                                ? 'border-foreground font-medium text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {link.title}
                    </Link>
                );
            })}
        </nav>
    );
}
