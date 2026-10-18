/**
 * Public help pages (knowledge base, announcements, network status, support
 * tickets) stay inside the client area for a signed-in customer, so the
 * sidebar links to them do not drop the customer out of their account.
 */
export function showsPublicPageInClientArea(
    pageName: string,
    props: Record<string, unknown>,
): boolean {
    const auth = props.auth as { user?: unknown } | null | undefined;
    const accounts = props.clientAccounts as unknown[] | null | undefined;

    return (
        pageName.startsWith('public/support/') &&
        Boolean(auth?.user) &&
        (accounts?.length ?? 0) > 0
    );
}
