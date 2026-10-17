import AppLogoIcon from '@/components/app-logo-icon';
import { BrandLogo } from '@/components/brand-logo';

/** Sidebar header brand: full logo, or just the mark when the sidebar is collapsed. */
export default function AppLogo() {
    return (
        <>
            <BrandLogo className="group-data-[collapsible=icon]:hidden" />
            <AppLogoIcon className="hidden size-8 group-data-[collapsible=icon]:block" />
        </>
    );
}
