import { cn } from '@/lib/utils';

export type GatewayLogoData = {
    url: string;
    dark_url: string | null;
    alt: string;
};

/**
 * A payment provider's official logo (shipped by its driver, used unmodified and only scaled), with the
 * provider's dark-background variant when it publishes one. Without a logo it falls back to the text name.
 */
export function GatewayLogo({
    name,
    logo,
    className,
}: {
    name: string;
    logo?: GatewayLogoData | null;
    className?: string;
}) {
    if (!logo) {
        return <span className={className}>{name}</span>;
    }

    const size = cn('h-7 w-auto shrink-0 object-contain', className);

    return (
        <>
            <img
                src={logo.url}
                alt={logo.alt}
                className={cn(size, logo.dark_url && 'dark:hidden')}
            />
            {logo.dark_url && (
                <img
                    src={logo.dark_url}
                    alt={logo.alt}
                    className={cn(size, 'hidden dark:block')}
                />
            )}
        </>
    );
}
