import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { HostBrand } from '@/components/host-brand';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
    backHref,
    backLabel,
}: AuthLayoutProps) {
    const { brand } = usePage().props;

    return (
        <div className="relative flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <Link
                href={backHref ?? home()}
                className="absolute top-4 left-4 inline-flex items-center gap-1 rounded-md px-2 py-1 text-sm text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none md:top-6 md:left-6"
            >
                <ArrowLeft className="size-4" aria-hidden="true" />
                {backLabel ?? `Back to ${brand.name}`}
            </Link>
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2 font-medium"
                        >
                            <HostBrand imageClassName="h-9" />
                            {brand?.tagline && (
                                <span className="text-xs font-normal text-muted-foreground">
                                    {brand.tagline}
                                </span>
                            )}
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">{title}</h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
