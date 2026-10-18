import { Head as InertiaHead, usePage } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { useLayoutEffect } from 'react';
import { setPageTitle } from '@/lib/page-title';

/**
 * Inertia's <Head> that also records the page title for the header breadcrumb.
 * The layout effect runs before paint, so the breadcrumb updates in the same frame.
 */
export function Head(props: ComponentProps<typeof InertiaHead>) {
    const { url } = usePage();
    const title = props.title ?? null;

    useLayoutEffect(() => {
        setPageTitle(title, url);
    }, [title, url]);

    return <InertiaHead {...props} />;
}
