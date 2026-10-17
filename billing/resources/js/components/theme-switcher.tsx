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
import { useDesignTheme } from '@/hooks/use-theme';
import { THEMES } from '@/lib/themes';
import { cn } from '@/lib/utils';

const APPEARANCES: { value: Appearance; label: string; icon: typeof Sun }[] = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
    { value: 'system', label: 'System', icon: Monitor },
];

/** Design-direction + light/dark picker (dev showcase and previews). Persists in localStorage. */
export function ThemeSwitcher({ className }: { className?: string }) {
    const { theme, updateTheme } = useDesignTheme();
    const { appearance, updateAppearance } = useAppearance();
    const current = THEMES.find((item) => item.id === theme) ?? THEMES[0];

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
                    <span className="hidden sm:inline">{current.label}</span>
                    <span className="sr-only sm:hidden">Theme</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-72">
                <DropdownMenuLabel className="text-xs text-muted-foreground">
                    Design direction
                </DropdownMenuLabel>
                {THEMES.map((item) => (
                    <DropdownMenuItem
                        key={item.id}
                        onSelect={() => updateTheme(item.id)}
                        className="items-start"
                    >
                        <Check
                            aria-hidden="true"
                            className={cn(
                                'mt-0.5',
                                item.id !== theme && 'invisible',
                            )}
                        />
                        <span className="grid gap-0.5">
                            <span className="font-medium">{item.label}</span>
                            <span className="text-xs text-muted-foreground">
                                {item.description}
                            </span>
                        </span>
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator />
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
