import { Form, Link, router } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import {
    Copy,
    Download,
    ImageUp,
    MoreHorizontal,
    Pencil,
    Plus,
    Trash2,
    Type,
    Upload,
} from 'lucide-react';
import { useRef, useState } from 'react';
import AppearanceController from '@/actions/App/Modules/Themes/Http/Controllers/Admin/AppearanceController';
import BrandAssetController from '@/actions/App/Modules/Themes/Http/Controllers/Admin/BrandAssetController';
import ThemeController from '@/actions/App/Modules/Themes/Http/Controllers/Admin/ThemeController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { Field } from '@/components/app/field';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import { edit as appearance } from '@/routes/admin/appearance';
import type { Brand, ThemeOption } from '@/types/ui';

type ThemeRow = ThemeOption & {
    id: number | null;
    usages: string[];
    swatches: { light: string[]; dark: string[] };
};

type AppearanceProps = {
    settings: {
        tagline: string;
        default_admin: string;
        default_client: string;
        default_public: string;
        density: string;
        allow_user_choice: boolean;
    };
    themes: ThemeRow[];
    densities: string[];
    brand: Brand;
    companyName: string;
    companySettingsUrl: string | null;
    uploads: Record<BrandKind, boolean>;
    emailLogo: string | null;
};

type BrandKind = 'logo-light' | 'logo-dark' | 'mark' | 'favicon' | 'logo-email';

/**
 * Email clients don't show WebP, and some invert colours: the mark is sent to the server as PNG, and the email logo
 * as PNG flattened onto white (opaque, so it reads in every mode).
 */
async function prepareForEmail(kind: BrandKind, file: File): Promise<File> {
    const flatten = kind === 'logo-email';

    if (!flatten && !(kind === 'mark' && file.type === 'image/webp')) {
        return file;
    }

    const bitmap = await createImageBitmap(file);
    const canvas = document.createElement('canvas');
    canvas.width = bitmap.width;
    canvas.height = bitmap.height;
    const context = canvas.getContext('2d');

    if (!context) {
        return file;
    }

    if (flatten) {
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
    }

    context.drawImage(bitmap, 0, 0);
    const blob = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob(resolve, 'image/png'),
    );

    return blob
        ? new File([blob], file.name.replace(/\.[a-z]+$/i, '') + '.png', {
              type: 'image/png',
          })
        : file;
}

const AREAS = [
    {
        name: 'default_admin',
        label: 'Admin panel',
        hint: 'Staff area. Staff may override it if allowed below.',
    },
    {
        name: 'default_client',
        label: 'Client area',
        hint: 'What your customers see after signing in.',
    },
    {
        name: 'default_public',
        label: 'Storefront & sign-in',
        hint: 'Public pages, login and registration.',
    },
] as const;

const DENSITY_LABELS: Record<string, string> = {
    theme: "Theme's own spacing",
    compact: 'Compact (dense tables)',
    comfortable: 'Comfortable',
    spacious: 'Spacious',
};

function Swatches({
    colors,
    dark = false,
}: {
    colors: string[];
    dark?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex h-9 flex-1 overflow-hidden rounded-md border',
                dark && 'border-transparent',
            )}
            aria-hidden="true"
        >
            {colors.map((color, index) => (
                <span
                    key={index}
                    className={index < 2 ? 'flex-[2]' : 'flex-1'}
                    style={{ background: color }}
                />
            ))}
        </div>
    );
}

function ThemeCard({
    theme,
    onRename,
}: {
    theme: ThemeRow;
    onRename: (theme: ThemeRow) => void;
}) {
    const [confirmDelete, setConfirmDelete] = useState(false);
    const inUse = theme.usages.length > 0;

    return (
        <article className="flex flex-col gap-3 rounded-xl border bg-card p-(--stat-p) text-card-foreground elevation-card">
            <div className="flex gap-2">
                <Swatches colors={theme.swatches.light} />
                <Swatches colors={theme.swatches.dark} dark />
            </div>
            <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                    <h3 className="truncate font-medium">{theme.name}</h3>
                    <p className="line-clamp-2 text-xs text-muted-foreground">
                        {theme.description}
                    </p>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="-mr-1 size-7 shrink-0"
                            aria-label={`Actions for ${theme.name}`}
                        >
                            <MoreHorizontal />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-52">
                        {theme.id !== null && (
                            <>
                                <DropdownMenuItem asChild>
                                    <Link href={ThemeController.edit(theme.id)}>
                                        <Pencil />
                                        Edit tokens
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onSelect={() => onRename(theme)}
                                >
                                    <Type />
                                    Rename
                                </DropdownMenuItem>
                            </>
                        )}
                        <DropdownMenuItem asChild>
                            <Link
                                href={ThemeController.create({
                                    query: { from: theme.key },
                                })}
                            >
                                <Copy />
                                Duplicate & customise
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <a
                                href={ThemeController.exportMethod.url(
                                    theme.key,
                                )}
                            >
                                <Download />
                                Export JSON
                            </a>
                        </DropdownMenuItem>
                        {theme.id !== null && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    disabled={inUse}
                                    onSelect={() => setConfirmDelete(true)}
                                >
                                    <Trash2 />
                                    {inUse
                                        ? 'In use — cannot delete'
                                        : 'Delete'}
                                </DropdownMenuItem>
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
            <div className="mt-auto flex flex-wrap gap-1.5">
                <span className="rounded-badge border px-1.5 py-0.5 text-[0.6875rem] text-muted-foreground">
                    {theme.custom ? 'Custom' : 'Preset'}
                </span>
                {theme.usages.map((usage) => (
                    <span
                        key={usage}
                        className="rounded-badge bg-accent px-1.5 py-0.5 text-[0.6875rem] font-medium text-accent-foreground"
                    >
                        {usage}
                    </span>
                ))}
            </div>
            {theme.id !== null && (
                <ConfirmDialog
                    open={confirmDelete}
                    onOpenChange={setConfirmDelete}
                    title={`Delete ${theme.name}?`}
                    description="The theme and its tokens are removed. Export it first if you may want it back."
                    confirmLabel="Delete theme"
                    action={ThemeController.destroy(theme.id)}
                />
            )}
        </article>
    );
}

function BrandRow({
    kind,
    title,
    hint,
    url,
    uploaded,
    dark = false,
}: {
    kind: BrandKind;
    title: string;
    hint: string;
    url: string | null;
    uploaded: boolean;
    dark?: boolean;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const upload = async (selected: File | undefined) => {
        if (!selected) {
            return;
        }

        setUploading(true);
        const file = await prepareForEmail(kind, selected);
        router.post(
            BrandAssetController.store.url(kind),
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (errors) => setError(errors.file),
                onSuccess: () => setError(undefined),
                onFinish: () => {
                    setUploading(false);

                    if (input.current) {
                        input.current.value = '';
                    }
                },
            },
        );
    };

    return (
        <div className="flex flex-wrap items-center gap-4 py-3 first:pt-0 last:pb-0">
            <div
                className={cn(
                    'grid h-14 w-32 shrink-0 place-items-center overflow-hidden rounded-lg border',
                    dark ? 'bg-[#16181d]' : 'bg-white',
                )}
            >
                {url ? (
                    <img
                        src={url}
                        alt=""
                        className="max-h-10 max-w-28 object-contain"
                    />
                ) : (
                    <ImageUp
                        className={cn(
                            'size-5',
                            dark ? 'text-white/50' : 'text-black/40',
                        )}
                        aria-hidden="true"
                    />
                )}
            </div>
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 font-medium">
                    {title}
                    {!uploaded && (
                        <span className="rounded-badge border px-1.5 py-0.5 text-[0.6875rem] font-normal text-muted-foreground">
                            Default
                        </span>
                    )}
                </div>
                <div className="text-sm text-muted-foreground">{hint}</div>
                <InputError message={error} className="mt-1" />
            </div>
            <div className="flex gap-2">
                <input
                    ref={input}
                    type="file"
                    accept={
                        kind === 'favicon'
                            ? 'image/png,image/x-icon,.ico'
                            : 'image/png,image/jpeg,image/webp'
                    }
                    className="sr-only"
                    id={`brand-${kind}`}
                    onChange={(event) => upload(event.target.files?.[0])}
                />
                <Button
                    variant="outline"
                    size="sm"
                    disabled={uploading}
                    onClick={() => input.current?.click()}
                >
                    {uploading ? <Spinner /> : <Upload />}
                    {uploaded ? 'Replace' : 'Upload'}
                </Button>
                {uploaded && (
                    <ConfirmDialog
                        trigger={
                            <Button variant="ghost" size="sm">
                                Remove
                            </Button>
                        }
                        title={`Remove the ${title.toLowerCase()}?`}
                        description="The default image is used instead."
                        confirmLabel="Remove image"
                        action={BrandAssetController.destroy(kind)}
                    />
                )}
            </div>
        </div>
    );
}

function BrandPreview({ brand }: { brand: Brand }) {
    const logo = (dark: boolean) => {
        const src = dark
            ? (brand.logoDark ?? brand.logoLight)
            : brand.logoLight;

        return src ? (
            <img
                src={src}
                alt={brand.name}
                className="h-7 w-auto max-w-44 object-contain"
            />
        ) : (
            <span className="flex items-center gap-2">
                <img src={brand.mark} alt="" className="size-7 rounded-md" />
                <span className="font-display text-base">{brand.name}</span>
            </span>
        );
    };

    return (
        <div className="space-y-3" aria-label="Brand preview">
            <div className="eyebrow text-muted-foreground">Preview</div>
            {[false, true].map((dark) => (
                <div
                    key={String(dark)}
                    className={cn(
                        'flex flex-col items-center gap-1.5 rounded-xl border px-4 py-6 text-center',
                        dark
                            ? 'bg-[#12151d] text-[#e3e6ee]'
                            : 'bg-white text-[#1a2133]',
                    )}
                >
                    {logo(dark)}
                    {brand.tagline && (
                        <span
                            className={cn(
                                'text-xs',
                                dark ? 'text-[#9aa3b5]' : 'text-[#5b6478]',
                            )}
                        >
                            {brand.tagline}
                        </span>
                    )}
                    <span className={cn('mt-2 text-sm font-medium')}>
                        Log in to your account
                    </span>
                </div>
            ))}
        </div>
    );
}

export default function AppearanceSettings({
    settings,
    themes,
    densities,
    brand,
    companyName,
    companySettingsUrl,
    uploads,
    emailLogo,
}: AppearanceProps) {
    const [values, setValues] = useState(settings);
    const [renaming, setRenaming] = useState<ThemeRow | null>(null);
    const [newName, setNewName] = useState('');
    const importInput = useRef<HTMLInputElement>(null);
    const [importErrors, setImportErrors] = useState<string | undefined>();

    const importTheme = (file: File | undefined) => {
        if (!file) {
            return;
        }

        router.post(
            ThemeController.importMethod.url(),
            { file },
            {
                forceFormData: true,
                onError: (errors) => setImportErrors(errors.file),
                onFinish: () => {
                    if (importInput.current) {
                        importInput.current.value = '';
                    }
                },
            },
        );
    };

    return (
        <>
            <Head title="Appearance" />
            <Page>
                <PageHeader
                    title="Appearance"
                    description="Themes, density and branding for the admin panel, client area and storefront."
                    actions={
                        <>
                            <input
                                ref={importInput}
                                type="file"
                                accept="application/json,.json"
                                className="sr-only"
                                aria-label="Import theme JSON"
                                onChange={(event) =>
                                    importTheme(event.target.files?.[0])
                                }
                            />
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => importInput.current?.click()}
                            >
                                <Upload />
                                Import theme
                            </Button>
                            <Button size="sm" asChild>
                                <Link
                                    href={ThemeController.create({
                                        query: { from: values.default_admin },
                                    })}
                                >
                                    <Plus />
                                    New theme
                                </Link>
                            </Button>
                        </>
                    }
                />

                {importErrors && (
                    <div
                        role="alert"
                        className="rounded-xl border border-danger/30 bg-danger-subtle p-4 text-sm text-danger-text"
                    >
                        <span className="font-medium">Import failed:</span>{' '}
                        {importErrors}
                    </div>
                )}

                <div className="grid items-start gap-(--page-gap) lg:grid-cols-3">
                    <Form
                        {...AppearanceController.update.form()}
                        options={{ preserveScroll: true }}
                        className="lg:col-span-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <SectionCard
                                    className="lg:col-span-2"
                                    title="Defaults"
                                    description="Which theme each area uses. Changes apply on the next page load."
                                    footer={
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            Save appearance
                                        </Button>
                                    }
                                >
                                    <div className="grid gap-6 sm:grid-cols-2">
                                        {AREAS.map((area) => (
                                            <Field
                                                key={area.name}
                                                htmlFor={area.name}
                                                label={area.label}
                                                hint={area.hint}
                                                error={errors[area.name]}
                                            >
                                                <Select
                                                    name={area.name}
                                                    value={values[area.name]}
                                                    onValueChange={(value) =>
                                                        setValues({
                                                            ...values,
                                                            [area.name]: value,
                                                        })
                                                    }
                                                >
                                                    <SelectTrigger
                                                        id={area.name}
                                                        className="w-full"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {themes.map((theme) => (
                                                            <SelectItem
                                                                key={theme.key}
                                                                value={
                                                                    theme.key
                                                                }
                                                            >
                                                                {theme.name}
                                                                {theme.custom
                                                                    ? ' (custom)'
                                                                    : ''}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </Field>
                                        ))}
                                        <Field
                                            htmlFor="density"
                                            label="Default density"
                                            hint="Overrides the theme's table and card spacing."
                                            error={errors.density}
                                        >
                                            <Select
                                                name="density"
                                                value={values.density}
                                                onValueChange={(value) =>
                                                    setValues({
                                                        ...values,
                                                        density: value,
                                                    })
                                                }
                                            >
                                                <SelectTrigger
                                                    id="density"
                                                    className="w-full"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {densities.map(
                                                        (density) => (
                                                            <SelectItem
                                                                key={density}
                                                                value={density}
                                                            >
                                                                {DENSITY_LABELS[
                                                                    density
                                                                ] ?? density}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </Field>
                                        <div className="flex items-start justify-between gap-4 rounded-lg border p-4 sm:col-span-2">
                                            <div>
                                                <label
                                                    htmlFor="allow_user_choice"
                                                    className="font-medium"
                                                >
                                                    Let staff choose their own
                                                    theme and density
                                                </label>
                                                <p className="text-sm text-muted-foreground">
                                                    Each staff member picks
                                                    theirs under Profile →
                                                    Appearance. Clients always
                                                    get the client-area theme.
                                                </p>
                                            </div>
                                            <input
                                                type="hidden"
                                                name="allow_user_choice"
                                                value={
                                                    values.allow_user_choice
                                                        ? '1'
                                                        : '0'
                                                }
                                            />
                                            <Switch
                                                id="allow_user_choice"
                                                checked={
                                                    values.allow_user_choice
                                                }
                                                onCheckedChange={(checked) =>
                                                    setValues({
                                                        ...values,
                                                        allow_user_choice:
                                                            checked,
                                                    })
                                                }
                                            />
                                        </div>
                                    </div>
                                </SectionCard>
                            </>
                        )}
                    </Form>
                    <Form
                        {...AppearanceController.updateBrand.form()}
                        options={{ preserveScroll: true }}
                    >
                        {({ errors, processing }) => (
                            <SectionCard
                                title="Brand"
                                description="Shown in the sidebar, sign-in pages, browser titles, emails and invoices."
                                footer={
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        {processing && <Spinner />}
                                        Save brand
                                    </Button>
                                }
                            >
                                <div className="grid gap-5">
                                    <div className="grid gap-1.5">
                                        <span className="text-sm font-medium">
                                            Company name
                                        </span>
                                        <div className="flex items-center justify-between gap-3 rounded-md border bg-muted/40 px-3 py-2 text-sm">
                                            <span className="truncate">
                                                {companyName}
                                            </span>
                                            {companySettingsUrl && (
                                                <a
                                                    href={companySettingsUrl}
                                                    className="shrink-0 text-sm font-medium text-primary underline-offset-4 hover:underline"
                                                >
                                                    Edit in Settings
                                                </a>
                                            )}
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            Managed in Settings → Company, so
                                            invoices, emails and this page
                                            always agree.
                                        </p>
                                    </div>
                                    <Field
                                        htmlFor="tagline"
                                        label="Tagline"
                                        optional
                                        hint="Under the logo on sign-in pages and in email footers."
                                        error={errors.tagline}
                                    >
                                        <Input
                                            id="tagline"
                                            name="tagline"
                                            defaultValue={settings.tagline}
                                            maxLength={120}
                                        />
                                    </Field>
                                </div>
                            </SectionCard>
                        )}
                    </Form>
                </div>

                <SectionCard
                    title="Themes"
                    description="Presets ship with the platform. Duplicate one to make a custom theme; every save must pass WCAG AA contrast."
                >
                    <div className="grid gap-(--page-gap) sm:grid-cols-2 xl:grid-cols-4">
                        {themes.map((theme) => (
                            <ThemeCard
                                key={theme.key}
                                theme={theme}
                                onRename={(row) => {
                                    setRenaming(row);
                                    setNewName(row.name);
                                }}
                            />
                        ))}
                    </div>
                </SectionCard>

                <SectionCard
                    title="Brand assets"
                    description="PNG, JPG or WebP (logos up to 512 KB, mark up to 256 KB; favicon PNG or ICO up to 128 KB). SVG uploads are not accepted."
                    actions={
                        brand.customised ? (
                            <ConfirmDialog
                                trigger={
                                    <Button variant="outline" size="sm">
                                        Reset to default
                                    </Button>
                                }
                                title="Reset the brand to the default?"
                                description="Removes the uploaded logos, mark and favicon and restores the default tagline. The company name is unchanged."
                                confirmLabel="Reset brand"
                                action={BrandAssetController.reset()}
                            />
                        ) : undefined
                    }
                >
                    <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                        <div className="divide-y">
                            <BrandRow
                                kind="logo-light"
                                title="Logo for light backgrounds"
                                hint="Up to 2000×1000 px; shown about 28 px high."
                                url={brand.logoLight}
                                uploaded={uploads['logo-light']}
                            />
                            <BrandRow
                                kind="logo-dark"
                                title="Logo for dark backgrounds"
                                hint="Optional; falls back to the light logo."
                                url={brand.logoDark}
                                uploaded={uploads['logo-dark']}
                                dark
                            />
                            <BrandRow
                                kind="mark"
                                title="Mark"
                                hint="Square icon for the collapsed sidebar and app tiles, 32–1024 px."
                                url={brand.mark}
                                uploaded={uploads.mark}
                            />
                            <BrandRow
                                kind="favicon"
                                title="Favicon"
                                hint="Square, up to 512×512 px."
                                url={brand.favicon}
                                uploaded={uploads.favicon}
                            />
                            <BrandRow
                                kind="logo-email"
                                title="Email logo (optional)"
                                hint="Shown at the top of every email instead of your mark and name. Transparent areas are filled with white so it stays readable in dark mode."
                                url={emailLogo}
                                uploaded={uploads['logo-email']}
                            />
                        </div>
                        <BrandPreview brand={brand} />
                    </div>
                </SectionCard>

                <Dialog
                    open={renaming !== null}
                    onOpenChange={(open) => !open && setRenaming(null)}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Rename theme</DialogTitle>
                            <DialogDescription>
                                The theme key stays the same, so areas and staff
                                using it are unaffected.
                            </DialogDescription>
                        </DialogHeader>
                        <Field htmlFor="rename" label="Name">
                            <Input
                                id="rename"
                                value={newName}
                                maxLength={60}
                                onChange={(event) =>
                                    setNewName(event.target.value)
                                }
                            />
                        </Field>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setRenaming(null)}
                            >
                                Cancel
                            </Button>
                            <Button
                                disabled={newName.trim() === ''}
                                onClick={() =>
                                    renaming?.id &&
                                    router.patch(
                                        ThemeController.rename.url(renaming.id),
                                        { name: newName },
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => setRenaming(null),
                                        },
                                    )
                                }
                            >
                                Rename theme
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </Page>
        </>
    );
}

AppearanceSettings.layout = {
    breadcrumbs: [{ title: 'Appearance', href: appearance() }],
};
