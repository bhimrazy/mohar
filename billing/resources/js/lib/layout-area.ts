type LayoutProps = {
    auth?: { user?: unknown } | null;
    clientAccounts?: unknown[] | null;
};

/**
 * Public help pages (knowledge base, announcements, network status, support
 * tickets) stay inside the client area for a signed-in customer, so the
 * sidebar links to them do not drop the customer out of their account.
 */
export function showsPublicPageInClientArea(
    pageName: string,
    props: LayoutProps,
): boolean {
    return (
        pageName.startsWith('public/support/') &&
        Boolean(props.auth?.user) &&
        (props.clientAccounts?.length ?? 0) > 0
    );
}
