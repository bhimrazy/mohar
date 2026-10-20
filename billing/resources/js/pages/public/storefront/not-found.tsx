import { Link } from '@inertiajs/react';
import { DomainSearch } from '@/components/storefront/pricing';
import { SiteHead } from '@/components/storefront/site-head';
import { Button } from '@/components/ui/button';
import type { PageSeo } from '@/types/storefront';

type Props = {
    path: string;
    links: { label: string; url: string }[];
    search_url: string | null;
    seo: PageSeo;
};

/** Public 404 (HTTP 404, noindex) inside the public layout, with ways back in. */
export default function NotFound({ path, links, search_url, seo }: Props) {
    return (
        <>
            <SiteHead seo={seo} />
            <section className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-16 md:px-6 md:py-24">
                <p className="text-sm font-semibold text-muted-foreground">
                    Error 404
                </p>
                <h1 className="font-display text-4xl font-semibold tracking-tight">
                    We couldn't find that page
                </h1>
                <p className="text-lg text-muted-foreground">
                    Nothing lives at{' '}
                    <code className="rounded bg-muted px-1.5 py-0.5 text-base break-all text-foreground">
                        {path}
                    </code>
                    . It may have moved, or the link may have a typo.
                </p>
                {search_url && (
                    <div className="space-y-2">
                        <p className="font-medium">Looking for a domain?</p>
                        <DomainSearch action={search_url} />
                    </div>
                )}
                <div className="flex flex-wrap gap-3">
                    {links.map((link, index) => (
                        <Button
                            key={link.url}
                            asChild
                            variant={index === 0 ? 'default' : 'outline'}
                        >
                            {link.url.startsWith('/') ||
                            link.url.startsWith(window.location.origin) ? (
                                <Link href={link.url}>{link.label}</Link>
                            ) : (
                                <a href={link.url}>{link.label}</a>
                            )}
                        </Button>
                    ))}
                </div>
            </section>
        </>
    );
}
