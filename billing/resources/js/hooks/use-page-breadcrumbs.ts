import { usePage } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';
import { resolveBreadcrumbs } from '@/lib/breadcrumbs';
import { getPageTitle, subscribePageTitle } from '@/lib/page-title';
import type { BreadcrumbItem } from '@/types';

/** Declared breadcrumbs, completed from the sidebar and the page's own title. */
export function usePageBreadcrumbs(
    declared: BreadcrumbItem[],
): BreadcrumbItem[] {
    const { url, props } = usePage();
    const pageTitle = useSyncExternalStore(
        subscribePageTitle,
        getPageTitle,
        getPageTitle,
    );

    return resolveBreadcrumbs({
        declared,
        navigation: props.navigation ?? [],
        currentUrl: url,
        // A title recorded for another URL belongs to the previous page.
        pageTitle: pageTitle.url === url ? pageTitle.title : null,
    });
}
