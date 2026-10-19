import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

/** Host uploads are served by GET /branding/{kind}; the product's own defaults live in /brand and /favicon. */
function uploaded(url: string | null | undefined): string | null {
    return url && url.includes('/branding/') ? url : null;
}

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
    const logo = uploaded(brand.logoLight);
    const mark = uploaded(brand.mark);

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
                    src={uploaded(brand.logoDark) ?? logo}
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
