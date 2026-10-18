import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { navIcon } from '@/lib/nav-icons';
import { cn } from '@/lib/utils';
import type { QuickAction } from '@/types';

/** Admin "New" menu fed by Navigation::quickActions() (admin-create items). */
export function QuickCreate({
    actions,
    className,
    label = 'New',
    iconOnly = false,
}: {
    actions: QuickAction[];
    className?: string;
    label?: string;
    iconOnly?: boolean;
}) {
    if (actions.length === 0) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    size={iconOnly ? 'icon' : 'sm'}
                    aria-label={iconOnly ? `${label}…` : undefined}
                    className={cn(iconOnly ? 'size-8' : 'gap-1.5', className)}
                >
                    <Plus aria-hidden="true" />
                    {!iconOnly && <span>{label}</span>}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-52">
                <DropdownMenuLabel className="text-xs text-muted-foreground">
                    Create
                </DropdownMenuLabel>
                {actions.map((action) => {
                    const Icon = navIcon(action.icon);

                    return (
                        <DropdownMenuItem key={action.href} asChild>
                            <Link href={action.href}>
                                <Icon aria-hidden="true" />
                                {action.title}
                            </Link>
                        </DropdownMenuItem>
                    );
                })}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
