import { router, usePage } from '@inertiajs/react';
import { Check, Monitor, Moon, Palette, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/hooks/use-appearance';
import type { Appearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { preference } from '@/routes/admin/appearance';

const APPEARANCES: { value: Appearance; label: string; icon: typeof Sun }[] = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
    { value: 'system', label: 'System', icon: Monitor },
];

/**
 * Theme + light/dark picker for staff. Saves the staff member's own admin theme
 * (Settings → Appearance must allow it); on /dev previews it switches via ?theme= instead.
 * Theme CSS is server-rendered, so a change reloads the page.
 */
export function ThemeSwitcher({ className }: { className?: string }) {
    const { theme } = usePage().props;
    const { appearance, updateAppearance } = useAppearance();
    const isPreview = window.location.pathname.startsWith('/dev/');

    const choose = (key: string) => {
        if (isPreview) {
            const url = new URL(window.location.href);
            url.searchParams.set('theme', key);
            window.location.assign(url.toString());

            return;
        }

        router.put(
            preference.url(),
            { theme: key, density: theme.preference?.density ?? null },
            {
                preserveScroll: true,
                onSuccess: () => window.location.reload(),
            },
        );
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className={cn('gap-1.5', className)}
                    data-test="theme-switcher"
                >
                    <Palette aria-hidden="true" />
                    <span className="hidden sm:inline">{theme.name}</span>
                    <span className="sr-only sm:hidden">Theme</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-72">
                {theme.canChoose && theme.options.length > 0 && (
                    <>
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            Theme
                        </DropdownMenuLabel>
                        {theme.options.map((item) => (
                            <DropdownMenuItem
                                key={item.key}
                                onSelect={() => choose(item.key)}
                                className="items-start"
                            >
                                <Check
                                    aria-hidden="true"
                                    className={cn(
                                        'mt-0.5',
                                        item.key !== theme.key && 'invisible',
                                    )}
                                />
                                <span className="grid gap-0.5">
                                    <span className="font-medium">
                                        {item.name}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {item.description}
                                    </span>
                                </span>
                            </DropdownMenuItem>
                        ))}
                        <DropdownMenuSeparator />
                    </>
                )}
                <div
                    className="grid grid-cols-3 gap-1 p-1"
                    role="group"
                    aria-label="Appearance"
                >
                    {APPEARANCES.map(({ value, label, icon: Icon }) => (
                        <button
                            key={value}
                            type="button"
                            aria-pressed={appearance === value}
                            onClick={() => updateAppearance(value)}
                            className={cn(
                                'flex items-center justify-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground',
                                appearance === value &&
                                    'bg-accent text-accent-foreground',
                            )}
                        >
                            <Icon className="size-3.5" aria-hidden="true" />
                            {label}
                        </button>
                    ))}
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
