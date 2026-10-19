import { Link, router, usePage } from '@inertiajs/react';
import { ShoppingCart } from 'lucide-react';
import type { ReactNode } from 'react';
import { PageWidthContext } from '@/components/app/page';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { dashboard, home, login, register } from '@/routes';
import store from '@/routes/store';
import { index as kb } from '@/routes/support/kb';

/**
 * Optional props a public page may send to enrich the header (store pages
 * send them; other public pages such as the knowledge base can omit them).
 */
type PublicPageProps = {
    cart_count?: number;
    currency?: string;
    currencies?: string[];
    can_switch_currency?: boolean;
    support_email?: string | null;
};

type NavLink = {
    label: string;
    href: string;
    active: boolean;
    /** Hidden on phones to keep the header on one line (the footer links to it). */
    wide?: boolean;
};

const SUPPORT_PATHS = [
    '/knowledgebase',
    '/announcements',
    '/network-status',
    '/support/',
];

function useNavLinks(): NavLink[] {
    const { url } = usePage();
    const path = new URL(url, 'http://localhost').pathname;

    return [
        {
            label: 'Store',
            href: store.index.url(),
            active:
                path === store.index.url() ||
                path.startsWith('/store/products'),
        },
        {
            label: 'Domains',
            href: store.domains.url(),
            active:
                path.startsWith(store.domains.url()) ||
                path.startsWith('/domains/'),
        },
        {
            label: 'Support',
            href: kb.url(),
            active: SUPPORT_PATHS.some((prefix) => path.startsWith(prefix)),
            wide: true,
        },
    ];
}

function CurrencySwitcher({
    currency,
    currencies,
    className,
}: {
    currency: string;
    currencies: string[];
    className?: string;
}) {
    return (
        <Select
            value={currency}
            onValueChange={(next) =>
                router.put(
                    store.currency.url(),
                    { currency: next },
                    { preserveScroll: true },
                )
            }
        >
            <SelectTrigger
                aria-label="Currency"
                size="sm"
                className={cn('w-[5.5rem]', className)}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent align="end">
                {currencies.map((code) => (
                    <SelectItem key={code} value={code}>
                        {code}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

export default function PublicLayout({ children }: { children: ReactNode }) {
    const { brand, auth, ...props } = usePage<PublicPageProps>().props;
    const links = useNavLinks();
    const cartCount = props.cart_count;
    const showCurrency =
        props.can_switch_currency &&
        props.currency &&
        (props.currencies?.length ?? 0) > 1;

    return (
        <div className="flex min-h-svh flex-col bg-background text-foreground">
            <header className="sticky top-0 z-30 border-b bg-background/90 backdrop-blur supports-[backdrop-filter]:bg-background/75">
                <div className="mx-auto flex h-16 w-full max-w-6xl items-center gap-2 px-4 sm:gap-4 md:px-6">
                    <Link
                        href={home()}
                        aria-label={`${brand.name} home`}
                        className="flex shrink-0 items-center gap-2 rounded-md font-semibold focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    >
                        <span className="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
                            <AppLogoIcon className="size-5 fill-current" />
                        </span>
                        <span className="hidden md:inline">{brand.name}</span>
                    </Link>

                    <nav
                        aria-label="Main"
                        className="flex min-w-0 items-center text-sm"
                    >
                        {links.map((link) => (
                            <Link
                                key={link.label}
                                href={link.href}
                                aria-current={link.active ? 'page' : undefined}
                                className={cn(
                                    'rounded-md px-2 py-2 font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none sm:px-3',
                                    link.active && 'text-foreground',
                                    link.wide && 'hidden sm:inline',
                                )}
                            >
                                {link.label}
                            </Link>
                        ))}
                    </nav>

                    <div className="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
                        {showCurrency && (
                            <CurrencySwitcher
                                currency={props.currency as string}
                                currencies={props.currencies ?? []}
                                className="hidden sm:flex"
                            />
                        )}

                        {cartCount !== undefined && (
                            <Button
                                asChild
                                variant="ghost"
                                size="sm"
                                className="relative"
                            >
                                <Link
                                    href={store.cart.url()}
                                    aria-label={`Cart, ${cartCount} ${cartCount === 1 ? 'item' : 'items'}`}
                                >
                                    <ShoppingCart aria-hidden="true" />
                                    <span className="hidden sm:inline">
                                        Cart
                                    </span>
                                    {cartCount > 0 && (
                                        <span className="ml-0.5 inline-flex min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-semibold text-primary-foreground tabular-nums">
                                            {cartCount}
                                        </span>
                                    )}
                                </Link>
                            </Button>
                        )}

                        {auth.user ? (
                            <Button asChild size="sm" variant="outline">
                                <Link href={dashboard()}>Client area</Link>
                            </Button>
                        ) : (
                            <>
                                <Button
                                    asChild
                                    size="sm"
                                    variant="ghost"
                                    className="hidden sm:inline-flex"
                                >
                                    <Link href={login()}>Log in</Link>
                                </Button>
                                {auth.signup_open && (
                                    <Button asChild size="sm">
                                        <Link href={register()}>
                                            Create account
                                        </Link>
                                    </Button>
                                )}
                            </>
                        )}
                    </div>
                </div>
            </header>

            <main className="flex-1">
                <PageWidthContext.Provider value="max-w-6xl">
                    {children}
                </PageWidthContext.Provider>
            </main>

            <footer className="border-t bg-muted/30">
                <div className="mx-auto flex w-full max-w-6xl flex-col gap-4 px-4 py-8 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between md:px-6">
                    <div className="space-y-1">
                        <p>
                            © {new Date().getFullYear()} {brand.name}. All
                            prices exclude tax unless stated.
                        </p>
                        {props.support_email && (
                            <p>
                                Questions?{' '}
                                <a
                                    href={`mailto:${props.support_email}`}
                                    className="font-medium text-foreground underline-offset-4 hover:underline"
                                >
                                    {props.support_email}
                                </a>
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                        {showCurrency && (
                            <CurrencySwitcher
                                currency={props.currency as string}
                                currencies={props.currencies ?? []}
                                className="sm:hidden"
                            />
                        )}
                        <nav
                            aria-label="Footer"
                            className="flex flex-wrap gap-x-5 gap-y-2"
                        >
                            <Link
                                href={store.index.url()}
                                className="hover:text-foreground"
                            >
                                Store
                            </Link>
                            <Link
                                href={store.domains.url()}
                                className="hover:text-foreground"
                            >
                                Domains
                            </Link>
                            <Link
                                href={kb.url()}
                                className="hover:text-foreground"
                            >
                                Support
                            </Link>
                            <Link
                                href={auth.user ? dashboard() : login()}
                                className="hover:text-foreground"
                            >
                                {auth.user ? 'Client area' : 'Log in'}
                            </Link>
                        </nav>
                    </div>
                </div>
            </footer>
        </div>
    );
}
