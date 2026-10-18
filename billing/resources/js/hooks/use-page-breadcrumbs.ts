import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { pageTitleFromDocument, resolveBreadcrumbs } from '@/lib/breadcrumbs';
import type { BreadcrumbItem } from '@/types';

/** The document title, kept in sync as Inertia's <Head> updates it. */
function useDocumentTitle(): string {
    const [title, setTitle] = useState(() =>
        typeof document === 'undefined' ? '' : document.title,
    );

    useEffect(() => {
        const update = () => setTitle(document.title);
        const observer = new MutationObserver(update);
        observer.observe(document.head, {
            childList: true,
            subtree: true,
            characterData: true,
        });
        update();

        return () => observer.disconnect();
    }, []);

    return title;
}

/** Declared breadcrumbs, completed from the sidebar and the page title. */
export function usePageBreadcrumbs(
    declared: BreadcrumbItem[],
): BreadcrumbItem[] {
    const { url, props } = usePage();
    const documentTitle = useDocumentTitle();

    return resolveBreadcrumbs({
        declared,
        navigation: props.navigation ?? [],
        currentUrl: url,
        pageTitle: pageTitleFromDocument(documentTitle, props.brand?.name),
    });
}
