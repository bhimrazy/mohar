import { createInertiaApp, router } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import PreviewLayout from '@/layouts/preview-layout';
import PublicLayout from '@/layouts/public-layout';
import { showsPublicPageInClientArea } from '@/lib/layout-area';
import { applySharedFormatting } from '@/lib/shared-formatting';
import SettingsLayout from '@/layouts/settings/layout';

// The brand name comes from Settings → Appearance (shared `brand` prop); the
// server-rendered <title> seeds it before the first page is known.
let brandName: string = document.title || import.meta.env.VITE_APP_NAME || '';

router.on('navigate', (event) => {
    const brand = event.detail.page.props.brand as
        | { name?: string }
        | undefined;
    brandName = brand?.name || brandName;
});

router.on('beforeUpdate', (event) => {
    applySharedFormatting(event.detail.page.props);
});

void createInertiaApp({
    title: (title) => (title ? `${title} · ${brandName}` : brandName),
    layout: (name, page) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('dev/') && name.endsWith('-preview'):
                return PreviewLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case showsPublicPageInClientArea(name, page.props):
                return AppLayout;
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
        applySharedFormatting(page.props);

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
