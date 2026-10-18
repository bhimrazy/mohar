import type { BreadcrumbItem, ServerNavGroup } from '@/types';
import { toUrl } from '@/lib/utils';

/** Sidebar groups that add nothing as a breadcrumb level. */
const SILENT_GROUPS = ['General'];

function pathOf(href: NonNullable<BreadcrumbItem['href']>): string {
    return new URL(toUrl(href), 'http://localhost').pathname.replace(/\/$/, '');
}

/**
 * Breadcrumbs for the page header. Pages may declare their own trail; when they
 * do not, the trail comes from the active sidebar item ("Setup › Products").
 * When the page sits below the last crumb (a record, a create or edit form),
 * the page title is appended so every screen shows where it is and links back.
 */
export function resolveBreadcrumbs({
    declared,
    navigation,
    currentUrl,
    pageTitle,
}: {
    declared: BreadcrumbItem[];
    navigation: ServerNavGroup[];
    currentUrl: string;
    pageTitle: string | null;
}): BreadcrumbItem[] {
    const group = navigation.find((g) => g.items.some((item) => item.active));
    const active = group?.items.find((item) => item.active);

    let trail: BreadcrumbItem[] = declared.length
        ? [...declared]
        : active
          ? [{ title: active.title, href: active.href }]
          : [];

    if (
        group &&
        active &&
        trail.length > 0 &&
        trail[0].title === active.title &&
        !SILENT_GROUPS.includes(group.title) &&
        group.title !== active.title
    ) {
        trail = [{ title: group.title }, ...trail];
    }

    const last = trail.at(-1);
    const title = pageTitle?.trim();

    if (
        last?.href !== undefined &&
        title &&
        title !== last.title &&
        pathOf(last.href) !== pathOf(currentUrl)
    ) {
        trail.push({ title, href: currentUrl });
    }

    return trail;
}
