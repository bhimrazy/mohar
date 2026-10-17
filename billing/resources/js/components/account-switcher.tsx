import { router, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronsUpDown } from 'lucide-react';
import AccountController from '@/actions/App/Modules/Core/Http/Controllers/Client/AccountController';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

/**
 * Lets a login that manages several client accounts choose which one it acts for.
 */
export function AccountSwitcher() {
    const { clientAccounts, currentClientId } = usePage().props;

    if (clientAccounts.length < 2) {
        return null;
    }

    const current = clientAccounts.find((account) => account.id === currentClientId) ?? clientAccounts[0];

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton data-test="account-switcher">
                            <Building2 />
                            <span className="truncate">{current.name}</span>
                            <ChevronsUpDown className="ml-auto" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent className="w-64" align="start">
                        <DropdownMenuLabel>Switch account</DropdownMenuLabel>
                        {clientAccounts.map((account) => (
                            <DropdownMenuItem
                                key={account.id}
                                onSelect={() => router.post(AccountController.switch.url(), { client_id: account.id })}
                            >
                                <span className="truncate">{account.name}</span>
                                {account.id === current.id && <Check className="ml-auto" />}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
