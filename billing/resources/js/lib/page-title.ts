/**
 * The current page's own title (the `title` given to <Head>), keyed by the URL it
 * was set for, so the header breadcrumb never shows the previous page's title.
 */
export type PageTitle = { title: string | null; url: string | null };

let current: PageTitle = { title: null, url: null };
const listeners = new Set<() => void>();

export function setPageTitle(title: string | null, url: string): void {
    if (current.title === title && current.url === url) {
        return;
    }

    current = { title, url };
    listeners.forEach((listener) => listener());
}

export function getPageTitle(): PageTitle {
    return current;
}

export function subscribePageTitle(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}
