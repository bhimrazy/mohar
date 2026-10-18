import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** Page intro band for public store pages. */
export function StorefrontHero({
    eyebrow,
    title,
    description,
    children,
    className,
}: {
    eyebrow?: string;
    title: string;
    description?: ReactNode;
    children?: ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('border-b bg-muted/30', className)}>
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-4 px-4 py-10 md:px-6 md:py-14">
                {eyebrow && (
                    <p className="text-sm font-medium text-muted-foreground">
                        {eyebrow}
                    </p>
                )}
                <h1 className="max-w-3xl text-3xl font-semibold tracking-tight text-balance md:text-4xl">
                    {title}
                </h1>
                {description && (
                    <p className="max-w-2xl text-base text-muted-foreground">
                        {description}
                    </p>
                )}
                {children}
            </div>
        </section>
    );
}
