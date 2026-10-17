import { Link, usePage } from '@inertiajs/react';
import { AccountSwitcher } from '@/components/account-switcher';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavSearch } from '@/components/nav-search';
import { NavUser } from '@/components/nav-user';
import { QuickCreate } from '@/components/quick-create';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { QuickAction, ServerNavGroup } from '@/types';

type AppSidebarProps = {
    /** Overrides for previews/tests; default to the shared Inertia props. */
    navigation?: ServerNavGroup[];
    quickActions?: QuickAction[];
    area?: 'admin' | 'client';
};

export function AppSidebar(overrides: AppSidebarProps = {}) {
    const props = usePage().props;
    const navigation = overrides.navigation ?? props.navigation;
    const quickActions = overrides.quickActions ?? props.quickActions ?? [];
    const area = overrides.area ?? props.area;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader className="gap-3">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                {area === 'client' && <AccountSwitcher />}
                <div className="flex items-center gap-1.5 group-data-[collapsible=icon]:flex-col">
                    <div className="min-w-0 flex-1 group-data-[collapsible=icon]:w-8 group-data-[collapsible=icon]:flex-none">
                        <NavSearch
                            groups={navigation}
                            quickActions={quickActions}
                        />
                    </div>
                    {area === 'admin' && (
                        <QuickCreate actions={quickActions} iconOnly />
                    )}
                </div>
            </SidebarHeader>

            <SidebarContent className="gap-1">
                <NavMain groups={navigation} />
            </SidebarContent>

            <SidebarFooter className="border-t border-sidebar-border">
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
