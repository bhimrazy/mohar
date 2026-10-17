import { router, usePage } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';

export type QueryChanges = Record<string, string | number | null | undefined>;

/**
 * Reads the current page's query string (SSR-safe: uses the Inertia page URL,
 * not `window.location`) and returns helpers to build/apply changes.
 */
export function useQueryParams() {
    const { url } = usePage();

    const { path, params } = useMemo(() => {
        const parsed = new URL(url, 'http://localhost');

        return { path: parsed.pathname, params: parsed.searchParams };
    }, [url]);

    /** Current query merged with changes; null/'' removes a key. */
    const merge = useCallback(
        (changes: QueryChanges): Record<string, string> => {
            const next: Record<string, string> = {};

            params.forEach((value, key) => {
                next[key] = value;
            });

            for (const [key, value] of Object.entries(changes)) {
                if (value === null || value === undefined || value === '') {
                    delete next[key];
                } else {
                    next[key] = String(value);
                }
            }

            return next;
        },
        [params],
    );

    /** Build a href for the current path with the query changes applied. */
    const hrefWith = useCallback(
        (changes: QueryChanges): string => {
            const query = new URLSearchParams(merge(changes)).toString();

            return query ? `${path}?${query}` : path;
        },
        [merge, path],
    );

    /**
     * Apply filter changes: resets pagination, keeps component state and
     * scroll, and replaces the history entry so Back leaves the page.
     */
    const apply = useCallback(
        (changes: QueryChanges, options: { only?: string[] } = {}) => {
            router.get(path, merge({ page: null, ...changes }), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                ...(options.only ? { only: options.only } : {}),
            });
        },
        [merge, path],
    );

    return { path, params, merge, hrefWith, apply };
}
