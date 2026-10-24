import AppLogoIcon from '@/components/app-logo-icon';
import { BrandLogo } from '@/components/brand-logo';
import { HostBrand, HostMark } from '@/components/host-brand';

/**
 * Sidebar header brand: full logo, or just the mark when the sidebar is collapsed. The client area is a customer
 * surface, so it shows the host's brand only, never the product's default logo or mark.
 */
export default function AppLogo({
    area = 'admin',
}: {
    area?: 'admin' | 'client';
}) {
    if (area === 'client') {
        return (
            <>
                <HostBrand
                    className="text-base group-data-[collapsible=icon]:hidden"
                    imageClassName="h-7"
                />
                <HostMark className="hidden size-8 group-data-[collapsible=icon]:flex" />
            </>
        );
    }

    return (
        <>
            <BrandLogo className="group-data-[collapsible=icon]:hidden" />
            <AppLogoIcon className="hidden size-8 group-data-[collapsible=icon]:block" />
        </>
    );
}
