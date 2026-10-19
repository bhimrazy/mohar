import { Head } from '@/components/app/head';
import {
    Clock,
    CreditCard,
    Globe,
    Headset,
    Lock,
    MapPin,
    RefreshCw,
    Server,
    ShieldCheck,
    Zap,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { PageSeo } from '@/types/storefront';

/** Icons the host can pick for "Why choose us" points (SiteContent::ICONS). */
export const WHY_US_ICONS: Record<string, LucideIcon> = {
    clock: Clock,
    shield: ShieldCheck,
    refresh: RefreshCw,
    card: CreditCard,
    headset: Headset,
    server: Server,
    globe: Globe,
    lock: Lock,
    zap: Zap,
    'map-pin': MapPin,
};

/**
 * Client-side copy of the server-rendered head (PageHead): the same keys, so Inertia replaces the server tags
 * instead of duplicating them, and client-side visits carry the right description and canonical.
 */
export function SiteHead({ seo }: { seo: PageSeo }) {
    return (
        <Head title={seo.title}>
            <meta
                head-key="description"
                name="description"
                content={seo.description}
            />
            <meta head-key="robots" name="robots" content={seo.robots} />
            <link head-key="canonical" rel="canonical" href={seo.canonical} />
            <meta head-key="og:type" property="og:type" content="website" />
            <meta head-key="og:title" property="og:title" content={seo.title} />
            <meta
                head-key="og:description"
                property="og:description"
                content={seo.description}
            />
            <meta head-key="og:url" property="og:url" content={seo.canonical} />
            {seo.image && (
                <meta
                    head-key="og:image"
                    property="og:image"
                    content={seo.image}
                />
            )}
        </Head>
    );
}
