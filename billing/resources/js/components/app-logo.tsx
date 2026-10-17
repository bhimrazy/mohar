import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

/** Brand mark + company name; uses the uploaded logos (light/dark) when set. */
export default function AppLogo() {
    const { name, brand } = usePage().props;
    const title = brand?.name || name;
    const light = brand?.logoLight;
    const dark = brand?.logoDark ?? light;

    if (light) {
        return (
            <div className="flex h-8 min-w-0 flex-1 items-center">
                <img
                    src={light}
                    alt={title}
                    className="h-7 max-w-40 object-contain object-left dark:hidden"
                />
                <img
                    src={dark ?? light}
                    alt={title}
                    className="hidden h-7 max-w-40 object-contain object-left dark:block"
                />
            </div>
        );
    }

    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                <AppLogoIcon className="size-5 fill-current" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-semibold">
                    {title}
                </span>
            </div>
        </>
    );
}
