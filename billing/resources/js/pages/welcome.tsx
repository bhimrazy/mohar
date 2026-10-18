import { Head, Link, usePage } from '@inertiajs/react';
import { BrandLogo } from '@/components/brand-logo';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';
import store from '@/routes/store';
import { index as kb } from '@/routes/support/kb';

/** Public landing: brand, tagline and the ways in (store, domains, support, client area). */
export default function Welcome() {
    const { auth, brand } = usePage().props;

    return (
        <>
            <Head title="Welcome" />
            <div className="flex min-h-svh flex-col bg-background text-foreground">
                <header className="mx-auto flex w-full max-w-5xl items-center gap-4 p-6">
                    <BrandLogo />
                    <nav
                        aria-label="Main"
                        className="hidden items-center gap-1 text-sm sm:flex"
                    >
                        {[
                            { label: 'Store', href: store.index.url() },
                            { label: 'Domains', href: store.domains.url() },
                            { label: 'Support', href: kb.url() },
                        ].map((link) => (
                            <Button key={link.label} asChild variant="ghost" size="sm">
                                <Link href={link.href}>{link.label}</Link>
                            </Button>
                        ))}
                    </nav>
                    <nav
                        aria-label="Account"
                        className="ml-auto flex items-center gap-2"
                    >
                        {auth.user ? (
                            <Button asChild size="sm">
                                <Link href={dashboard()}>Client area</Link>
                            </Button>
                        ) : (
                            <>
                                <Button asChild variant="ghost" size="sm">
                                    <Link href={login()}>Log in</Link>
                                </Button>
                                <Button asChild size="sm">
                                    <Link href={register()}>
                                        Create account
                                    </Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </header>
                <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col items-start justify-center gap-6 p-6">
                    <h1 className="max-w-2xl font-display text-4xl sm:text-5xl">
                        {brand?.tagline || brand?.name}
                    </h1>
                    <p className="max-w-xl text-lg text-muted-foreground">
                        Hosting, domains, invoices and support in one place.
                        Pick a plan, find a domain, or log in to manage your
                        services and pay an invoice.
                    </p>
                    <div className="flex flex-wrap gap-3">
                        <Button asChild size="lg">
                            <Link href={store.index.url()}>Browse plans</Link>
                        </Button>
                        <Button asChild size="lg" variant="outline">
                            <Link href={store.domains.url()}>
                                Search domains
                            </Link>
                        </Button>
                        <Button asChild size="lg" variant="ghost">
                            <Link href={auth.user ? dashboard() : login()}>
                                {auth.user ? 'Go to client area' : 'Log in'}
                            </Link>
                        </Button>
                    </div>
                </main>
            </div>
        </>
    );
}
