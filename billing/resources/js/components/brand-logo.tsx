import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';

/**
 * The brand wordmark (light/dark variants) or, for a renamed brand without its own logo,
 * the mark followed by the name. `compact` shows only the mark (collapsed sidebar).
 */
export function BrandLogo({
    className,
    imageClassName = 'h-7',
}: {
    className?: string;
    imageClassName?: string;
}) {
    const { brand, name } = usePage().props;
    const title = brand?.name || name;

    if (brand?.logoLight) {
        return (
            <span className={cn('flex min-w-0 items-center', className)}>
                <img
                    src={brand.logoLight}
                    alt={title}
                    className={cn(
                        'w-auto max-w-44 object-contain object-left dark:hidden',
                        imageClassName,
                    )}
                />
                <img
                    src={brand.logoDark ?? brand.logoLight}
                    alt={title}
                    className={cn(
                        'hidden w-auto max-w-44 object-contain object-left dark:block',
                        imageClassName,
                    )}
                />
            </span>
        );
    }

    return (
        <span className={cn('flex min-w-0 items-center gap-2', className)}>
            <AppLogoIcon
                className={cn('aspect-square w-auto', imageClassName)}
            />
            <span className="truncate font-display text-base">{title}</span>
        </span>
    );
}
