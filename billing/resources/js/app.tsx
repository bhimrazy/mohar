import { createInertiaApp, router } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { configureCurrencies } from '@/lib/money';
import type { CurrencyFormat } from '@/lib/money';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import PreviewLayout from '@/layouts/preview-layout';
import PublicLayout from '@/layouts/public-layout';
import SettingsLayout from '@/layouts/settings/layout';

// The brand name comes from Settings → Appearance (shared `brand` prop); the
// server-rendered <title> seeds it before the first page is known.
let brandName: string = document.title || import.meta.env.VITE_APP_NAME || '';

router.on('navigate', (event) => {
    const brand = event.detail.page.props.brand as
        | { name?: string }
        | undefined;
    brandName = brand?.name || brandName;
    configureCurrencies(
        event.detail.page.props.currencyFormats as CurrencyFormat[] | undefined,
    );
});

// Currency display settings (shared `currencyFormats` prop) must be in place before a page renders,
// so formatMoney() matches the server's formatting on the very first paint and after edits.
router.on('beforeUpdate', (event) => {
    configureCurrencies(
        event.detail.page.props.currencyFormats as CurrencyFormat[] | undefined,
    );
});

void createInertiaApp({
    title: (title) => (title ? `${title} · ${brandName}` : brandName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('dev/') && name.endsWith('-preview'):
                return PreviewLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('public/'):
                return PublicLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app, { page }) {
        configureCurrencies(
            page.props.currencyFormats as CurrencyFormat[] | undefined,
        );

        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
