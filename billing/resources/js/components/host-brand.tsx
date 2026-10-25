import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

/**
 * The host's brand for customer surfaces (public site, client login and sign-up): the host's uploaded logo, else the
 * host's uploaded mark with the name, else the name as text. The product's own logo and mark never show here
 * (product standards, Brand and visuals).
 */
export function HostBrand({
    className,
    imageClassName = 'h-8',
}: {
    className?: string;
    imageClassName?: string;
}) {
    const { brand } = usePage().props;
    const logo = brand.hasUploadedLogo ? brand.logoLight : null;
    const mark = brand.hasUploadedMark ? brand.mark : null;

    if (logo) {
        return (
            <span className={cn('flex min-w-0 items-center', className)}>
                <img
                    src={logo}
                    alt={brand.name}
                    className={cn(
                        'w-auto max-w-44 object-contain dark:hidden',
                        imageClassName,
                    )}
                />
                <img
                    src={brand.logoDark ?? logo}
                    alt={brand.name}
                    className={cn(
                        'hidden w-auto max-w-44 object-contain dark:block',
                        imageClassName,
                    )}
                />
            </span>
        );
    }

    return (
        <span
            className={cn(
                'flex min-w-0 items-center gap-2 font-display text-lg font-semibold',
                className,
            )}
        >
            {mark && (
                <img
                    src={mark}
                    alt=""
                    aria-hidden="true"
                    className={cn(
                        'aspect-square w-auto rounded-md object-contain',
                        imageClassName,
                    )}
                />
            )}
            <span className="truncate">{brand.name}</span>
        </span>
    );
}

/** The host's uploaded mark, else the first letter of the host's name on the accent colour. */
export function HostMark({ className }: { className?: string }) {
    const { brand } = usePage().props;

    if (brand.hasUploadedMark) {
        return (
            <img
                src={brand.mark}
                alt=""
                aria-hidden="true"
                className={cn('shrink-0 rounded-md object-contain', className)}
            />
        );
    }

    return (
        <span
            aria-hidden="true"
            className={cn(
                'shrink-0 items-center justify-center rounded-md bg-primary font-display text-sm font-semibold text-primary-foreground',
                className,
            )}
        >
            {brand.name.trim().charAt(0).toUpperCase()}
        </span>
    );
}
