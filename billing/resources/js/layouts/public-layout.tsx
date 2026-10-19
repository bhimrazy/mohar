import { Link, router, usePage } from '@inertiajs/react';
import { Mail, MapPin, Menu, Phone, ShoppingCart } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { PageWidthContext } from '@/components/app/page';
import { HostBrand } from '@/components/host-brand';
import {
    FromPriceText,
    PaymentMarks,
    TaxNote,
} from '@/components/storefront/pricing';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    NavigationMenu,
    NavigationMenuContent,
    NavigationMenuItem,
    NavigationMenuLink,
    NavigationMenuList,
    NavigationMenuTrigger,
    navigationMenuTriggerStyle,
} from '@/components/ui/navigation-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import type { Site, SiteLink } from '@/types/storefront';

/**
 * The one public layout (storefront spec §4): home, store, domains, knowledge base, announcements, network status,
 * cart, checkout and the 404 page. It speaks for the host: the brand comes from the host's settings, the header lists
 * what the host sells, and the footer carries the host's company details. Data is the shared `site` prop.
 */
export default function PublicLayout({ children }: { children: ReactNode }) {
    const { site } = usePage().props;

    return (
        <div className="flex min-h-svh flex-col bg-background text-foreground">
            <a
                href="#main"
                className="sr-only z-50 rounded-md bg-primary px-3 py-2 text-primary-foreground focus:not-sr-only focus:fixed focus:top-2 focus:left-2"
            >
                Skip to content
            </a>
            <header className="sticky top-0 z-30 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
                <div className="mx-auto flex h-16 w-full max-w-6xl items-center gap-2 px-4 md:px-6">
                    <HomeLink />
                    {site && <DesktopNav site={site} />}
                    <div className="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
                        {site && (
                            <CurrencySwitcher
                                site={site}
                                className="hidden lg:flex"
                            />
                        )}
                        {site?.cart && (
                            <Button
                                asChild
                                variant="ghost"
                                size="sm"
                                className="h-10"
                            >
                                <Link
                                    href={site.cart.url}
                                    aria-label={`Cart, ${site.cart.count} ${site.cart.count === 1 ? 'item' : 'items'}`}
                                >
                                    <ShoppingCart aria-hidden="true" />
                                    <span className="hidden sm:inline">
                                        Cart
                                    </span>
                                    {site.cart.count > 0 && (
                                        <span className="inline-flex min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-semibold text-primary-foreground tabular-nums">
                                            {site.cart.count}
                                        </span>
                                    )}
                                </Link>
                            </Button>
                        )}
                        <AccountLinks site={site} className="hidden lg:flex" />
                        {site && <MobileNav site={site} />}
                    </div>
                </div>
            </header>

            <main id="main" className="flex-1" tabIndex={-1}>
                <PageWidthContext.Provider value="max-w-6xl">
                    {children}
                </PageWidthContext.Provider>
            </main>

            {site && <SiteFooter site={site} />}
        </div>
    );
}

/** The host's brand links home; the product's own mark never shows on the public site. */
function HomeLink() {
    return (
        <Link
            href="/"
            className="flex min-w-0 shrink items-center rounded-md focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
        >
            <HostBrand />
        </Link>
    );
}

function DesktopNav({ site }: { site: Site }) {
    const { products } = site;
    const linkClass = cn(navigationMenuTriggerStyle(), 'bg-transparent px-3');

    return (
        <NavigationMenu
            viewport={false}
            className="ml-4 hidden lg:flex"
            aria-label="Main"
        >
            <NavigationMenuList>
                {products.mode === 'links'
                    ? products.groups.map((group) => (
                          <NavigationMenuItem key={group.id}>
                              <NavigationMenuLink asChild className={linkClass}>
                                  <Link href={group.url}>{group.name}</Link>
                              </NavigationMenuLink>
                          </NavigationMenuItem>
                      ))
                    : products.groups.length > 0 && (
                          <NavigationMenuItem>
                              <NavigationMenuTrigger className="bg-transparent px-3">
                                  {products.label}
                              </NavigationMenuTrigger>
                              <NavigationMenuContent>
                                  <ul className="grid w-[26rem] gap-1">
                                      {products.groups.map((group) => (
                                          <li key={group.id}>
                                              <NavigationMenuLink asChild>
                                                  <Link
                                                      href={group.url}
                                                      className="flex-row items-start justify-between gap-4"
                                                  >
                                                      <span className="min-w-0">
                                                          <span className="block font-medium">
                                                              {group.name}
                                                          </span>
                                                          {group.tagline && (
                                                              <span className="block text-muted-foreground">
                                                                  {
                                                                      group.tagline
                                                                  }
                                                              </span>
                                                          )}
                                                      </span>
                                                      {group.from && (
                                                          <FromPriceText
                                                              from={group.from}
                                                              currency={
                                                                  site.currency
                                                              }
                                                              className="shrink-0 text-right text-xs"
                                                          />
                                                      )}
                                                  </Link>
                                              </NavigationMenuLink>
                                          </li>
                                      ))}
                                      {products.compare_url && (
                                          <li className="border-t pt-1">
                                              <NavigationMenuLink asChild>
                                                  <Link
                                                      href={
                                                          products.compare_url
                                                      }
                                                      className="font-medium text-primary"
                                                  >
                                                      Compare all plans
                                                  </Link>
                                              </NavigationMenuLink>
                                          </li>
                                      )}
                                  </ul>
                              </NavigationMenuContent>
                          </NavigationMenuItem>
                      )}
                <MenuOfLinks label="Domains" links={site.domains} />
                <MenuOfLinks label="Support" links={site.support} />
            </NavigationMenuList>
        </NavigationMenu>
    );
}

function MenuOfLinks({ label, links }: { label: string; links: SiteLink[] }) {
    if (links.length === 0) {
        return null;
    }

    return (
        <NavigationMenuItem>
            <NavigationMenuTrigger className="bg-transparent px-3">
                {label}
            </NavigationMenuTrigger>
            <NavigationMenuContent>
                <ul className="grid w-56 gap-1">
                    {links.map((link) => (
                        <li key={link.label}>
                            <NavigationMenuLink asChild>
                                <Link
                                    href={link.url}
                                    className="flex-row items-center justify-between"
                                >
                                    {link.label}
                                    {link.badge && (
                                        <Badge
                                            variant="secondary"
                                            className="bg-warning-subtle text-warning-text"
                                        >
                                            {link.badge} open
                                        </Badge>
                                    )}
                                </Link>
                            </NavigationMenuLink>
                        </li>
                    ))}
                </ul>
            </NavigationMenuContent>
        </NavigationMenuItem>
    );
}

function CurrencySwitcher({
    site,
    className,
}: {
    site: Site;
    className?: string;
}) {
    if (!site.currency_url || site.currencies.length < 2) {
        return null;
    }

    return (
        <Select
            value={site.currency}
            onValueChange={(next) =>
                router.put(
                    site.currency_url as string,
                    { currency: next },
                    { preserveScroll: true },
                )
            }
        >
            <SelectTrigger
                aria-label="Currency"
                size="sm"
                className={cn('h-10 w-[5.5rem]', className)}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent align="end">
                {site.currencies.map((code) => (
                    <SelectItem key={code} value={code}>
                        {code}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function AccountLinks({
    site,
    className,
}: {
    site?: Site;
    className?: string;
}) {
    const { auth } = usePage().props;
    const account = site?.account;

    if (auth.user) {
        return (
            <Button
                asChild
                size="sm"
                variant="outline"
                className={cn('h-10', className)}
            >
                <Link href={account?.client_area_url ?? '/dashboard'}>
                    Client area
                </Link>
            </Button>
        );
    }

    return (
        <div className={cn('items-center gap-2', className)}>
            <Button asChild size="sm" variant="ghost" className="h-10">
                <Link href={account?.login_url ?? '/login'}>Log in</Link>
            </Button>
            {account?.register_url && (
                <Button asChild size="sm" className="h-10">
                    <Link href={account.register_url}>Create account</Link>
                </Button>
            )}
        </div>
    );
}

function MobileNav({ site }: { site: Site }) {
    const section =
        'text-xs font-medium tracking-wide text-muted-foreground uppercase';
    const item =
        'flex min-h-10 items-center justify-between gap-3 rounded-md px-2 text-sm hover:bg-muted';

    const [open, setOpen] = useState(false);

    // The layout persists across visits, so close the sheet once a link in it navigates.
    useEffect(() => router.on('navigate', () => setOpen(false)), []);

    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-10 lg:hidden"
                    aria-label="Open menu"
                >
                    <Menu aria-hidden="true" />
                </Button>
            </SheetTrigger>
            <SheetContent
                side="right"
                className="w-full max-w-sm gap-0 overflow-y-auto"
            >
                <SheetHeader>
                    <SheetTitle>Menu</SheetTitle>
                </SheetHeader>
                <nav
                    aria-label="Mobile"
                    className="flex flex-col gap-5 px-4 pb-6"
                >
                    {site.products.groups.length > 0 && (
                        <div className="space-y-1">
                            <p className={section}>{site.products.label}</p>
                            {site.products.groups.map((group) => (
                                <Link
                                    key={group.id}
                                    href={group.url}
                                    className={item}
                                >
                                    {group.name}
                                    {group.from && (
                                        <FromPriceText
                                            from={group.from}
                                            currency={site.currency}
                                            className="text-xs"
                                        />
                                    )}
                                </Link>
                            ))}
                        </div>
                    )}
                    {[
                        { label: 'Domains', links: site.domains },
                        { label: 'Support', links: site.support },
                    ].map(
                        (group) =>
                            group.links.length > 0 && (
                                <div key={group.label} className="space-y-1">
                                    <p className={section}>{group.label}</p>
                                    {group.links.map((link) => (
                                        <Link
                                            key={link.label}
                                            href={link.url}
                                            className={item}
                                        >
                                            {link.label}
                                            {link.badge && (
                                                <Badge variant="secondary">
                                                    {link.badge} open
                                                </Badge>
                                            )}
                                        </Link>
                                    ))}
                                </div>
                            ),
                    )}
                    <CurrencySwitcher site={site} className="w-full" />
                    <AccountLinks site={site} className="flex [&>*]:flex-1" />
                </nav>
            </SheetContent>
        </Sheet>
    );
}

function SiteFooter({ site }: { site: Site }) {
    const { auth } = usePage().props;
    const { company, account } = site;
    const columns: {
        title: string;
        links: { label: string; url: string | null }[];
    }[] = [
        {
            title: site.products.label,
            links: [
                ...site.products.groups.map((group) => ({
                    label: group.name,
                    url: group.url,
                })),
                { label: 'Compare all plans', url: site.products.compare_url },
            ],
        },
        { title: 'Domains', links: site.domains },
        {
            title: 'Support',
            links: [
                ...site.support,
                {
                    label: 'Contact us',
                    url: company.email ? `mailto:${company.email}` : null,
                },
            ],
        },
        {
            title: 'Account',
            links: auth.user
                ? [{ label: 'Client area', url: account.client_area_url }]
                : [
                      { label: 'Log in', url: account.login_url },
                      { label: 'Create account', url: account.register_url },
                      { label: 'Reset password', url: account.reset_url },
                  ],
        },
        { title: 'Legal', links: site.legal },
    ];

    return (
        <footer className="border-t bg-muted/40 text-sm">
            <div className="mx-auto grid w-full max-w-6xl gap-10 px-4 py-12 md:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,2.2fr)]">
                <address className="space-y-3 not-italic">
                    <p className="font-display text-lg font-semibold">
                        {company.name}
                    </p>
                    {(company.legal_name || company.address) && (
                        <p className="flex gap-2 text-muted-foreground">
                            <MapPin
                                className="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span>
                                {company.legal_name && (
                                    <span className="block font-medium text-foreground">
                                        {company.legal_name}
                                    </span>
                                )}
                                {company.address && (
                                    <span className="whitespace-pre-line">
                                        {company.address}
                                    </span>
                                )}
                            </span>
                        </p>
                    )}
                    {company.email && (
                        <p className="flex items-center gap-2">
                            <Mail
                                className="size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <a
                                href={`mailto:${company.email}`}
                                className="hover:underline"
                            >
                                {company.email}
                            </a>
                        </p>
                    )}
                    {company.phone && (
                        <p className="flex items-center gap-2">
                            <Phone
                                className="size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <a
                                href={`tel:${company.phone.replace(/[^\d+]/g, '')}`}
                                className="hover:underline"
                            >
                                {company.phone}
                            </a>
                        </p>
                    )}
                    {company.ids.length > 0 && (
                        <dl className="grid grid-cols-[auto_1fr] gap-x-2 gap-y-1 pl-6 text-muted-foreground">
                            {company.ids.map((id) => (
                                <div key={id.label} className="contents">
                                    <dt>{id.label}</dt>
                                    <dd className="font-medium text-foreground tabular-nums">
                                        {id.value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}
                </address>
                <nav
                    aria-label="Footer"
                    className="grid grid-cols-2 gap-8 sm:grid-cols-[repeat(auto-fit,minmax(7rem,1fr))]"
                >
                    {columns.map((column) => {
                        const links = column.links.filter(
                            (link): link is { label: string; url: string } =>
                                Boolean(link.url),
                        );

                        return (
                            links.length > 0 && (
                                <div key={column.title} className="space-y-3">
                                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        {column.title}
                                    </p>
                                    <ul className="space-y-2">
                                        {links.map((link) => (
                                            <li key={link.label}>
                                                {link.url.startsWith('/') ||
                                                link.url.startsWith(
                                                    window.location.origin,
                                                ) ? (
                                                    <Link
                                                        href={link.url}
                                                        className="hover:underline"
                                                    >
                                                        {link.label}
                                                    </Link>
                                                ) : (
                                                    <a
                                                        href={link.url}
                                                        className="hover:underline"
                                                    >
                                                        {link.label}
                                                    </a>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )
                        );
                    })}
                </nav>
            </div>
            {(site.payments.length > 0 || site.tax_note) && (
                <div className="mx-auto flex w-full max-w-6xl flex-col gap-4 border-t px-4 py-6 md:flex-row md:items-center md:justify-between md:px-6">
                    {site.payments.length > 0 && (
                        <div className="flex flex-wrap items-center gap-3">
                            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                We accept
                            </p>
                            <PaymentMarks marks={site.payments} />
                        </div>
                    )}
                    {site.tax_note && (
                        <TaxNote
                            note={`Prices in ${site.currency}: ${site.tax_note}`}
                            className="text-sm"
                        />
                    )}
                </div>
            )}
            <div className="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-4 border-t px-4 py-5 text-muted-foreground md:px-6">
                <p>
                    © {new Date().getFullYear()}{' '}
                    {company.legal_name ?? company.name}
                </p>
                <CurrencySwitcher site={site} />
            </div>
        </footer>
    );
}
