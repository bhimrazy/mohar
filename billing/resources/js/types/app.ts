/**
 * Shared types for admin + client area pages and the components in
 * `@/components/app`.
 */

export type HttpMethod = 'get' | 'post' | 'put' | 'patch' | 'delete';

/**
 * A URL + method pair. Wayfinder route/action helpers (e.g.
 * `InvoiceController.destroy(invoice)`) return a compatible object.
 */
export type RouteAction = {
    url: string;
    method: HttpMethod;
};

/** Anything an Inertia `<Link href>` / `router.visit()` accepts. */
export type Href = string | RouteAction;

export type SelectOption<TValue extends string = string> = {
    value: TValue;
    label: string;
    /** Extra words a searchable select also matches, e.g. a code or an alias. */
    keywords?: string;
};

/** Money as sent by the backend: integer minor units + ISO 4217 code. */
export type MoneyValue = {
    amount: number;
    currency: string;
};

export type PaginatorLink = {
    url: string | null;
    label: string;
    active: boolean;
    page?: number | null;
};

export type PaginatorMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
    path: string;
    links: PaginatorLink[];
};

/** `LengthAwarePaginator` serialized directly (`->paginate()` as a prop). */
export type LaravelPaginated<T> = PaginatorMeta & {
    data: T[];
    first_page_url: string;
    last_page_url: string;
    next_page_url: string | null;
    prev_page_url: string | null;
};

/** Paginator wrapped in an API Resource collection (`Resource::collection()`). */
export type ResourcePaginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: PaginatorMeta;
};

export type Paginated<T> = LaravelPaginated<T> | ResourcePaginated<T>;

/** `->simplePaginate()`: no total or page count, so the list runs no `count(*)`. */
export type SimplePaginatorMeta = {
    current_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    path: string;
};

export type LaravelSimplePaginated<T> = SimplePaginatorMeta & {
    data: T[];
    next_page_url: string | null;
    prev_page_url: string | null;
};

export type ResourceSimplePaginated<T> = {
    data: T[];
    links: { prev: string | null; next: string | null };
    meta: SimplePaginatorMeta;
};

export type SimplePaginated<T> =
    | LaravelSimplePaginated<T>
    | ResourceSimplePaginated<T>;
