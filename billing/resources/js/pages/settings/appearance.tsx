import { Head, router, usePage } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { preference } from '@/routes/admin/appearance';
import { edit as editAppearance } from '@/routes/appearance';

const DENSITIES = [
    { value: 'default', label: 'Company default' },
    { value: 'compact', label: 'Compact' },
    { value: 'comfortable', label: 'Comfortable' },
    { value: 'spacious', label: 'Spacious' },
];

export default function Appearance() {
    const { theme } = usePage().props;

    const save = (next: { theme: string | null; density: string | null }) =>
        router.put(preference.url(), next, {
            preserveScroll: true,
            // Theme CSS is rendered by the server, so reload to apply it.
            onSuccess: () => window.location.reload(),
        });

    return (
        <>
            <Head title="Appearance settings" />

            <h1 className="sr-only">Appearance settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Appearance settings"
                    description="Update the appearance settings for your account"
                />
                <AppearanceTabs />

                {theme?.canChoose && theme.preference && (
                    <div className="grid max-w-xl gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="theme-preference">
                                Admin theme
                            </Label>
                            <Select
                                value={theme.preference.theme ?? 'default'}
                                onValueChange={(value) =>
                                    save({
                                        theme:
                                            value === 'default' ? null : value,
                                        density:
                                            theme.preference?.density ?? null,
                                    })
                                }
                            >
                                <SelectTrigger id="theme-preference">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="default">
                                        Company default
                                    </SelectItem>
                                    {theme.options.map((option) => (
                                        <SelectItem
                                            key={option.key}
                                            value={option.key}
                                        >
                                            {option.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="density-preference">Density</Label>
                            <Select
                                value={theme.preference.density ?? 'default'}
                                onValueChange={(value) =>
                                    save({
                                        theme: theme.preference?.theme ?? null,
                                        density:
                                            value === 'default' ? null : value,
                                    })
                                }
                            >
                                <SelectTrigger id="density-preference">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {DENSITIES.map((density) => (
                                        <SelectItem
                                            key={density.value}
                                            value={density.value}
                                        >
                                            {density.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Appearance settings',
            href: editAppearance(),
        },
    ],
};
