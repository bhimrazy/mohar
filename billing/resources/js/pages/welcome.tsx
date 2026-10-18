import { Head, Link, usePage } from '@inertiajs/react';
import { BrandLogo } from '@/components/brand-logo';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

/** Public landing until the storefront ships: brand, tagline and the way in. */
export default function Welcome() {
    const { auth, brand } = usePage().props;

    return (
        <>
            <Head title="Welcome" />
            <div className="flex min-h-svh flex-col bg-background text-foreground">
                <header className="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 p-6">
                    <BrandLogo />
                    <nav className="flex items-center gap-2">
                        {auth.user ? (
                            <Button asChild size="sm">
                                <Link href={dashboard()}>Dashboard</Link>
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
                    <BrandLogo imageClassName="h-12" />
                    <h1 className="max-w-2xl font-display text-4xl sm:text-5xl">
                        {brand?.tagline || brand?.name}
                    </h1>
                    <p className="max-w-xl text-lg text-muted-foreground">
                        Hosting, domains, invoices and support in one place.
                        Sign in to manage your services or pay an invoice.
                    </p>
                    <div className="flex gap-3">
                        <Button asChild size="lg">
                            <Link href={auth.user ? dashboard() : login()}>
                                {auth.user ? 'Go to dashboard' : 'Log in'}
                            </Link>
                        </Button>
                    </div>
                </main>
            </div>
        </>
    );
}
