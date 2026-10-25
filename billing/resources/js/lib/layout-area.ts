import { usePage } from '@inertiajs/react';

/**
 * Public pages the client sidebar links to. For a signed-in customer they render
 * inside the client area so those links do not drop the customer out of their
 * account: help pages, and the store with its cart, checkout and domain search.
 * Access-key ticket pages (public/support/tickets/*) are not on the list: the
 * ticket may belong to someone else, so they always use the public layout.
 */
const CLIENT_AREA_PUBLIC_PAGES = [
    'public/support/kb/',
    'public/support/announcements/',
    'public/support/network',
    'public/store/',
    'public/cart/',
    'public/checkout/',
    'public/domains/',
];

export function showsPublicPageInClientArea(
    pageName: string,
    props: Record<string, unknown>,
): boolean {
    const auth = props.auth as { user?: unknown } | null | undefined;
    const accounts = props.clientAccounts as unknown[] | null | undefined;

    return (
        CLIENT_AREA_PUBLIC_PAGES.some((prefix) =>
            pageName.startsWith(prefix),
        ) &&
        Boolean(auth?.user) &&
        (accounts?.length ?? 0) > 0
    );
}

/** Whether the current public page is shown inside the client area (see above). */
export function useInClientArea(): boolean {
    const page = usePage();

    return showsPublicPageInClientArea(
        page.component,
        page.props as Record<string, unknown>,
    );
}
