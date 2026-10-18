import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Download,
    RotateCcw,
    Save,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import ThemeController from '@/actions/App/Modules/Themes/Http/Controllers/Admin/ThemeController';
import { Field } from '@/components/app/field';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { ThemePreview } from '@/components/themes/theme-preview';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { checkContrast, HEX_COLOR } from '@/lib/theme-tokens';
import type {
    ThemeMode,
    ThemeSchemaDescription,
    ThemeTokens,
} from '@/lib/theme-tokens';
import { cn } from '@/lib/utils';
import { edit as appearance } from '@/routes/admin/appearance';

type Preset = { key: string; name: string; tokens: ThemeTokens };

type ThemeEditorProps = {
    theme: {
        id: number;
        key: string;
        version: number;
        usages: string[];
    } | null;
    name: string;
    basePreset: string | null;
    tokens: ThemeTokens;
    presets: Preset[];
    schema: ThemeSchemaDescription;
};

const COLOR_GROUPS: { title: string; tokens: string[] }[] = [
    {
        title: 'Surfaces & text',
        tokens: [
            'background',
            'foreground',
            'card',
            'card-foreground',
            'popover',
            'popover-foreground',
            'muted',
            'muted-foreground',
            'border',
            'input',
            'ring',
        ],
    },
    {
        title: 'Brand & actions',
        tokens: [
            'primary',
            'primary-foreground',
            'secondary',
            'secondary-foreground',
            'accent',
            'accent-foreground',
            'destructive',
            'destructive-foreground',
        ],
    },
    {
        title: 'Sidebar',
        tokens: [
            'sidebar',
            'sidebar-foreground',
            'sidebar-primary',
            'sidebar-primary-foreground',
            'sidebar-accent',
            'sidebar-accent-foreground',
            'sidebar-border',
            'sidebar-ring',
        ],
    },
    {
        title: 'Status',
        tokens: [
            'success',
            'success-subtle',
            'success-text',
            'success-foreground',
            'warning',
            'warning-subtle',
            'warning-text',
            'warning-foreground',
            'danger',
            'danger-subtle',
            'danger-text',
            'danger-foreground',
            'info',
            'info-subtle',
            'info-text',
            'info-foreground',
        ],
    },
    {
        title: 'Charts',
        tokens: [
            'chart-1',
            'chart-2',
            'chart-3',
            'chart-4',
            'chart-5',
            'chart-6',
            'chart-grid',
        ],
    },
    {
        title: 'Shell & elevation',
        tokens: [
            'shell-border',
            'elevation-card',
            'elevation-hover',
            'shell-shadow',
        ],
    },
];

const SHAPE_TOKENS = ['radius', 'badge-radius', 'shell-gap', 'shell-radius'];
const DENSITY_TOKENS = [
    'cell-py',
    'table-head-h',
    'card-p',
    'stat-p',
    'page-gap',
    'nav-item-h',
    'root-font-size',
];
const TYPE_TOKENS = ['heading-tracking', 'eyebrow-size', 'eyebrow-tracking'];
const WEIGHTS = [300, 400, 500, 600, 700, 800];

function label(token: string): string {
    const text = token.replace(/-/g, ' ');

    return text.charAt(0).toUpperCase() + text.slice(1);
}

function clone(tokens: ThemeTokens): ThemeTokens {
    return {
        shared: { ...tokens.shared },
        light: { ...tokens.light },
        dark: { ...tokens.dark },
    };
}

function ColorRow({
    token,
    value,
    onChange,
    worst,
    error,
}: {
    token: string;
    value: string;
    onChange: (value: string) => void;
    worst?: { ratio: number; minimum: number; against: string };
    error?: string;
}) {
    const valid = HEX_COLOR.test(value);
    const id = `token-${token}`;

    return (
        <div className="flex items-center gap-2 py-1">
            <input
                type="color"
                aria-label={`${label(token)} colour picker`}
                value={valid ? value.toLowerCase() : '#000000'}
                onChange={(event) => onChange(event.target.value.toUpperCase())}
                className="size-8 shrink-0 cursor-pointer rounded-md border bg-transparent p-0.5"
            />
            <label htmlFor={id} className="min-w-0 flex-1 truncate text-sm">
                {label(token)}
            </label>
            {worst && (
                <span
                    title={`Against ${worst.against}`}
                    className={cn(
                        'rounded-badge px-1.5 py-0.5 text-[0.6875rem] font-medium tabular-nums',
                        worst.ratio >= worst.minimum
                            ? 'bg-success-subtle text-success-text'
                            : 'bg-danger-subtle text-danger-text',
                    )}
                >
                    {worst.ratio.toFixed(2)}:1
                </span>
            )}
            <Input
                id={id}
                value={value}
                onChange={(event) => onChange(event.target.value.trim())}
                aria-invalid={!valid || !!error}
                className="h-8 w-24 font-mono text-xs uppercase"
                maxLength={7}
                spellCheck={false}
            />
        </div>
    );
}

function TextToken({
    token,
    value,
    onChange,
    error,
}: {
    token: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
}) {
    return (
        <Field htmlFor={`token-${token}`} label={label(token)} error={error}>
            <Input
                id={`token-${token}`}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                aria-invalid={!!error}
                className="h-8 font-mono text-xs"
                spellCheck={false}
            />
        </Field>
    );
}

function SelectToken({
    token,
    value,
    options,
    onChange,
}: {
    token: string;
    value: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
}) {
    return (
        <Field htmlFor={`token-${token}`} label={label(token)}>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger id={`token-${token}`} className="h-8 w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </Field>
    );
}

function Group({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="space-y-1">
            <h3 className="pt-2 eyebrow text-muted-foreground">{title}</h3>
            {children}
        </div>
    );
}

export default function ThemeEditor({
    theme,
    name: initialName,
    basePreset,
    tokens: initialTokens,
    presets,
    schema,
}: ThemeEditorProps) {
    const [name, setName] = useState(initialName);
    const [base, setBase] = useState(basePreset);
    const [tokens, setTokens] = useState<ThemeTokens>(() =>
        clone(initialTokens),
    );
    const [mode, setMode] = useState<ThemeMode>('light');
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const results = useMemo(
        () => checkContrast(tokens, schema.contrastPairs),
        [tokens, schema.contrastPairs],
    );
    const failures = results.filter((result) => !result.passes);
    const invalidColors = (['light', 'dark'] as const).flatMap((m) =>
        Object.entries(tokens[m])
            .filter(
                ([token, value]) =>
                    schema.mode[token] === 'color' && !HEX_COLOR.test(value),
            )
            .map(([token]) => `${m}.${token}`),
    );
    const canSave =
        failures.length === 0 &&
        invalidColors.length === 0 &&
        name.trim() !== '';

    /** Lowest ratio per foreground token in the current mode (for the inline badge). */
    const worstFor = (token: string) => {
        const own = results
            .filter(
                (result) => result.mode === mode && result.foreground === token,
            )
            .sort((a, b) => a.ratio / a.minimum - b.ratio / b.minimum)[0];

        return own
            ? {
                  ratio: own.ratio,
                  minimum: own.minimum,
                  against: own.background,
              }
            : undefined;
    };

    const setModeToken = (token: string, value: string) =>
        setTokens((current) => ({
            ...current,
            [mode]: { ...current[mode], [token]: value },
        }));
    const setShared = (token: string, value: string | number) =>
        setTokens((current) => ({
            ...current,
            shared: { ...current.shared, [token]: value },
        }));

    const fonts = (kind?: 'sans' | 'mono') =>
        Object.entries(schema.fonts)
            .filter(([, font]) => !kind || font.kind === kind)
            .map(([value, font]) => ({ value, label: font.label }));

    const save = () => {
        setSaving(true);
        const payload = { name, base_preset: base, tokens };
        const options = {
            preserveScroll: true,
            onError: (bag: Record<string, string>) => setErrors(bag),
            onSuccess: () => setErrors({}),
            onFinish: () => setSaving(false),
        };

        if (theme) {
            router.put(ThemeController.update.url(theme.id), payload, options);
        } else {
            router.post(ThemeController.store.url(), payload, options);
        }
    };

    const errorFor = (path: string) => errors[`tokens.${path}`];
    const errorList = Object.entries(errors);

    return (
        <>
            <Head title={theme ? `Edit ${name}` : 'New theme'} />
            <Page className="max-w-[1500px]">
                <PageHeader
                    back={{ href: appearance(), label: 'Appearance' }}
                    title={theme ? name || 'Untitled theme' : 'New theme'}
                    meta={
                        theme ? (
                            <>
                                <span className="font-mono">{theme.key}</span>
                                <span aria-hidden="true">·</span>
                                <span>Version {theme.version}</span>
                                {theme.usages.length > 0 && (
                                    <>
                                        <span aria-hidden="true">·</span>
                                        <span>
                                            In use: {theme.usages.join(', ')}
                                        </span>
                                    </>
                                )}
                            </>
                        ) : (
                            <span>
                                Start from a preset, adjust tokens, check
                                contrast, then save.
                            </span>
                        )
                    }
                    actions={
                        <>
                            {theme && (
                                <Button variant="outline" size="sm" asChild>
                                    <a
                                        href={ThemeController.exportMethod.url(
                                            theme.key,
                                        )}
                                    >
                                        <Download />
                                        Export JSON
                                    </a>
                                </Button>
                            )}
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setTokens(clone(initialTokens))}
                            >
                                <RotateCcw />
                                Reset
                            </Button>
                            <Button
                                size="sm"
                                onClick={save}
                                disabled={!canSave || saving}
                            >
                                {saving ? <Spinner /> : <Save />}
                                Save theme
                            </Button>
                        </>
                    }
                />

                {errorList.length > 0 && (
                    <div
                        role="alert"
                        className="rounded-xl border border-danger/30 bg-danger-subtle p-4 text-sm text-danger-text"
                    >
                        <p className="font-medium">The theme was not saved:</p>
                        <ul className="mt-1 list-disc pl-5">
                            {errorList.slice(0, 8).map(([key, message]) => (
                                <li key={key}>{message}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="grid items-start gap-(--page-gap) xl:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
                    <div className="flex flex-col gap-(--page-gap)">
                        <SectionCard title="Basics">
                            <div className="grid gap-4">
                                <Field
                                    htmlFor="theme-name"
                                    label="Name"
                                    error={errors.name}
                                >
                                    <Input
                                        id="theme-name"
                                        value={name}
                                        maxLength={60}
                                        onChange={(event) =>
                                            setName(event.target.value)
                                        }
                                    />
                                </Field>
                                {!theme && (
                                    <Field
                                        htmlFor="theme-base"
                                        label="Start from"
                                        hint="Replaces all tokens with the preset's values."
                                    >
                                        <Select
                                            value={base ?? ''}
                                            onValueChange={(key) => {
                                                const preset = presets.find(
                                                    (item) => item.key === key,
                                                );

                                                if (preset) {
                                                    setBase(key);
                                                    setTokens(
                                                        clone(preset.tokens),
                                                    );
                                                }
                                            }}
                                        >
                                            <SelectTrigger
                                                id="theme-base"
                                                className="w-full"
                                            >
                                                <SelectValue placeholder="Choose a preset" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {presets.map((preset) => (
                                                    <SelectItem
                                                        key={preset.key}
                                                        value={preset.key}
                                                    >
                                                        {preset.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </Field>
                                )}
                            </div>
                        </SectionCard>

                        <SectionCard
                            title="Colours"
                            description="Hex values. Badges show the weakest contrast for that colour."
                            actions={
                                <div
                                    role="tablist"
                                    aria-label="Mode"
                                    className="inline-flex rounded-lg border bg-muted/50 p-0.5"
                                >
                                    {(['light', 'dark'] as const).map(
                                        (value) => (
                                            <button
                                                key={value}
                                                type="button"
                                                role="tab"
                                                aria-selected={mode === value}
                                                onClick={() => setMode(value)}
                                                className={cn(
                                                    'rounded-md px-3 py-1 text-xs font-medium text-muted-foreground capitalize',
                                                    mode === value &&
                                                        'bg-card text-foreground shadow-xs ring-1 ring-border',
                                                )}
                                            >
                                                {value}
                                            </button>
                                        ),
                                    )}
                                </div>
                            }
                        >
                            <div className="divide-y">
                                {COLOR_GROUPS.map((group) => (
                                    <Group
                                        key={group.title}
                                        title={group.title}
                                    >
                                        {group.tokens.map((token) =>
                                            schema.mode[token] === 'shadow' ? (
                                                <TextToken
                                                    key={token}
                                                    token={token}
                                                    value={
                                                        tokens[mode][token] ??
                                                        ''
                                                    }
                                                    onChange={(value) =>
                                                        setModeToken(
                                                            token,
                                                            value,
                                                        )
                                                    }
                                                    error={errorFor(
                                                        `${mode}.${token}`,
                                                    )}
                                                />
                                            ) : (
                                                <ColorRow
                                                    key={token}
                                                    token={token}
                                                    value={
                                                        tokens[mode][token] ??
                                                        ''
                                                    }
                                                    onChange={(value) =>
                                                        setModeToken(
                                                            token,
                                                            value,
                                                        )
                                                    }
                                                    worst={worstFor(token)}
                                                    error={errorFor(
                                                        `${mode}.${token}`,
                                                    )}
                                                />
                                            ),
                                        )}
                                    </Group>
                                ))}
                            </div>
                        </SectionCard>

                        <SectionCard title="Typography">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <SelectToken
                                    token="font-sans"
                                    value={String(tokens.shared['font-sans'])}
                                    options={fonts('sans')}
                                    onChange={(v) => setShared('font-sans', v)}
                                />
                                <SelectToken
                                    token="font-display"
                                    value={String(
                                        tokens.shared['font-display'],
                                    )}
                                    options={fonts('sans')}
                                    onChange={(v) =>
                                        setShared('font-display', v)
                                    }
                                />
                                <SelectToken
                                    token="font-numeric"
                                    value={String(
                                        tokens.shared['font-numeric'],
                                    )}
                                    options={fonts()}
                                    onChange={(v) =>
                                        setShared('font-numeric', v)
                                    }
                                />
                                <SelectToken
                                    token="font-mono"
                                    value={String(tokens.shared['font-mono'])}
                                    options={fonts('mono')}
                                    onChange={(v) => setShared('font-mono', v)}
                                />
                                <SelectToken
                                    token="font-features"
                                    value={String(
                                        tokens.shared['font-features'],
                                    )}
                                    options={Object.keys(
                                        schema.fontFeatures,
                                    ).map((value) => ({ value, label: value }))}
                                    onChange={(v) =>
                                        setShared('font-features', v)
                                    }
                                />
                                <SelectToken
                                    token="heading-weight"
                                    value={String(
                                        tokens.shared['heading-weight'],
                                    )}
                                    options={WEIGHTS.map((w) => ({
                                        value: String(w),
                                        label: String(w),
                                    }))}
                                    onChange={(v) =>
                                        setShared('heading-weight', Number(v))
                                    }
                                />
                                <SelectToken
                                    token="eyebrow-case"
                                    value={String(
                                        tokens.shared['eyebrow-case'],
                                    )}
                                    options={[
                                        {
                                            value: 'none',
                                            label: 'Sentence case',
                                        },
                                        {
                                            value: 'uppercase',
                                            label: 'Uppercase',
                                        },
                                    ]}
                                    onChange={(v) =>
                                        setShared('eyebrow-case', v)
                                    }
                                />
                                <SelectToken
                                    token="eyebrow-weight"
                                    value={String(
                                        tokens.shared['eyebrow-weight'],
                                    )}
                                    options={WEIGHTS.map((w) => ({
                                        value: String(w),
                                        label: String(w),
                                    }))}
                                    onChange={(v) =>
                                        setShared('eyebrow-weight', Number(v))
                                    }
                                />
                                {TYPE_TOKENS.map((token) => (
                                    <TextToken
                                        key={token}
                                        token={token}
                                        value={String(tokens.shared[token])}
                                        onChange={(v) => setShared(token, v)}
                                        error={errorFor(`shared.${token}`)}
                                    />
                                ))}
                            </div>
                        </SectionCard>

                        <SectionCard title="Shape & density">
                            <div className="grid gap-4 sm:grid-cols-2">
                                {SHAPE_TOKENS.map((token) => (
                                    <TextToken
                                        key={token}
                                        token={token}
                                        value={String(tokens.shared[token])}
                                        onChange={(v) => setShared(token, v)}
                                        error={errorFor(`shared.${token}`)}
                                    />
                                ))}
                            </div>
                            <div className="mt-5 flex flex-wrap items-center gap-2">
                                <span className="text-sm text-muted-foreground">
                                    Density preset:
                                </span>
                                {Object.entries(schema.densities).map(
                                    ([key, values]) => (
                                        <Button
                                            key={key}
                                            variant="outline"
                                            size="sm"
                                            className="capitalize"
                                            onClick={() =>
                                                setTokens((current) => ({
                                                    ...current,
                                                    shared: {
                                                        ...current.shared,
                                                        ...values,
                                                    },
                                                }))
                                            }
                                        >
                                            {key}
                                        </Button>
                                    ),
                                )}
                            </div>
                            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                {DENSITY_TOKENS.map((token) => (
                                    <TextToken
                                        key={token}
                                        token={token}
                                        value={String(tokens.shared[token])}
                                        onChange={(v) => setShared(token, v)}
                                        error={errorFor(`shared.${token}`)}
                                    />
                                ))}
                            </div>
                        </SectionCard>
                    </div>

                    <div className="flex flex-col gap-(--page-gap) xl:sticky xl:top-20">
                        <section
                            aria-live="polite"
                            className={cn(
                                'flex items-start gap-3 rounded-xl border p-(--stat-p)',
                                failures.length === 0
                                    ? 'border-success/30 bg-success-subtle text-success-text'
                                    : 'border-danger/30 bg-danger-subtle text-danger-text',
                            )}
                        >
                            {failures.length === 0 ? (
                                <CheckCircle2
                                    className="mt-0.5 size-5 shrink-0"
                                    aria-hidden="true"
                                />
                            ) : (
                                <AlertTriangle
                                    className="mt-0.5 size-5 shrink-0"
                                    aria-hidden="true"
                                />
                            )}
                            <div className="min-w-0 flex-1 text-sm">
                                <p className="font-medium">
                                    {failures.length === 0
                                        ? `All ${results.length} key pairs meet WCAG AA in light and dark.`
                                        : `${failures.length} of ${results.length} key pairs are below WCAG AA — saving is blocked.`}
                                </p>
                                {failures.length > 0 && (
                                    <ul className="mt-1.5 grid gap-x-6 gap-y-0.5 sm:grid-cols-2">
                                        {failures
                                            .slice(0, 10)
                                            .map((failure) => (
                                                <li
                                                    key={`${failure.mode}-${failure.foreground}-${failure.background}`}
                                                    className="tabular-nums"
                                                >
                                                    <span className="capitalize">
                                                        {failure.mode}
                                                    </span>
                                                    : {failure.foreground} on{' '}
                                                    {failure.background}{' '}
                                                    <strong>
                                                        {Number.isFinite(
                                                            failure.ratio,
                                                        )
                                                            ? failure.ratio.toFixed(
                                                                  2,
                                                              )
                                                            : '—'}
                                                        :1
                                                    </strong>{' '}
                                                    (needs {failure.minimum})
                                                </li>
                                            ))}
                                    </ul>
                                )}
                                {invalidColors.length > 0 && (
                                    <p className="mt-1.5">
                                        Invalid colours:{' '}
                                        {invalidColors.join(', ')}
                                    </p>
                                )}
                            </div>
                        </section>

                        <div className="space-y-2">
                            <h2 className="eyebrow text-muted-foreground">
                                Live preview · light
                            </h2>
                            <ThemePreview
                                tokens={tokens}
                                mode="light"
                                schema={schema}
                            />
                        </div>
                        <div className="space-y-2">
                            <h2 className="eyebrow text-muted-foreground">
                                Live preview · dark
                            </h2>
                            <ThemePreview
                                tokens={tokens}
                                mode="dark"
                                schema={schema}
                            />
                        </div>
                    </div>
                </div>
            </Page>
        </>
    );
}

ThemeEditor.layout = {
    breadcrumbs: [
        { title: 'Appearance', href: appearance() },
        { title: 'Theme editor', href: appearance() },
    ],
};
