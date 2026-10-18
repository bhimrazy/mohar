import { createContext, useContext } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Width of a `default` page. The public layout sets `max-w-6xl` so pages line up
 * with its header and footer; the app shell keeps `max-w-7xl`.
 */
export const PageWidthContext = createContext<'max-w-7xl' | 'max-w-6xl'>(
    'max-w-7xl',
);

type PageProps = {
    children: ReactNode;
    /** `narrow` for create/edit forms, `default` for lists/detail pages. */
    size?: 'default' | 'narrow';
    className?: string;
};

/** Standard page container: consistent gutters and vertical rhythm. */
export function Page({ children, size = 'default', className }: PageProps) {
    const defaultWidth = useContext(PageWidthContext);

    return (
        <div
            data-slot="page"
            className={cn(
                'mx-auto flex w-full flex-1 flex-col gap-(--page-gap) px-4 py-6 md:px-6 md:py-8',
                size === 'narrow' ? 'max-w-3xl' : defaultWidth,
                className,
            )}
        >
            {children}
        </div>
    );
}
