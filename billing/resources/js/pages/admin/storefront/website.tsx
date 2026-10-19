import { useForm, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ExternalLink,
    Eye,
    Plus,
    Trash2,
} from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import WebsiteController from '@/actions/App/Modules/Storefront/Http/Controllers/Admin/WebsiteController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DateTime } from '@/components/app/date';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Head } from '@/components/app/head';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { SwitchField } from '@/components/app/switch-field';
import { UnsavedChangesGuard } from '@/components/app/unsaved-changes-guard';
import { WHY_US_ICONS } from '@/components/storefront/site-head';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { cycleLabel } from '@/lib/billing-cycles';

type Content = {
    hero: {
        eyebrow: string;
        headline: string;
        subtext: string;
        domain_search: boolean;
    };
    sections: { key: string; enabled: boolean }[];
    trust_facts: { label: string; value: string }[];
    why_us: { icon: string; title: string; text: string }[];
    cta: { headline: string; button: string };
    featured: { group_ids: number[]; cycle: string };
    news: { announcements: number; articles: number; status: boolean };
    header: { products: 'auto' | 'menu' | 'links'; menu_label: string };
    seo: { title: string; description: string; noindex: boolean };
    legal: { terms_url: string; privacy_url: string; refund_url: string };
    prices: { tax_inclusive: string[]; notes: Record<string, string> };
};

/** Content parts that are plain objects (the lists have their own setters). */
type GroupKey = Exclude<keyof Content, 'sections' | 'why_us' | 'trust_facts'>;

type Props = {
    content: Content;
    draft: {
        saved_at: string | null;
        saved_by: string | null;
        changes: string[];
        preview_url: string;
        /** Fingerprint of the draft shown here; publishing refuses a draft saved again since. */
        review_token: string;
    } | null;
    published_at: string | null;
    needs_text: boolean;
    placeholder_headline: string;
    can_publish: boolean;
    home_url: string;
    options: {
        groups: { id: number; name: string }[];
        icons: string[];
        sections: Record<string, string>;
        currencies: {
            code: string;
            exclusive_note: string | null;
            inclusive_note: string | null;
            /** ISO country the currency is priced for; null for a currency sold across markets. */
            market: string | null;
        }[];
        tax_rules_url: string | null;
        company_url: string | null;
    };
};

const CYCLES = [
    'monthly',
    'quarterly',
    'semiannually',
    'annually',
    'biennially',
    'triennially',
];

/** Admin → Website: home page and public layout content as a draft, with preview and publish. */
export default function Website({
    content,
    draft,
    published_at,
    needs_text,
    placeholder_headline,
    can_publish,
    home_url,
    options,
}: Props) {
    const { brand } = usePage().props;
    const form = useForm<Content>(content);
    const { data, errors } = form;
    const error = (key: string) =>
        (errors as Record<string, string | undefined>)[key];
    const set = <K extends GroupKey>(key: K, value: Partial<Content[K]>) =>
        form.setData((previous) => ({
            ...previous,
            [key]: { ...previous[key], ...value },
        }));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(WebsiteController.update(), { preserveScroll: true });
    };

    const move = (index: number, offset: number) => {
        const sections = [...data.sections];
        const [section] = sections.splice(index, 1);
        sections.splice(index + offset, 0, section);
        form.setData('sections', sections);
    };

    const publishBlocked = form.isDirty
        ? 'Save the draft before publishing.'
        : !draft
          ? 'There is no draft. Change something and save it first.'
          : !can_publish
            ? 'You can edit and preview the draft. Publishing needs the "Publish the website" permission.'
            : null;

    return (
        <>
            <Head title="Website" />
            <Page size="narrow">
                <PageHeader
                    title="Website"
                    description="What visitors see on your site before they log in: the home page and the header and footer on every public page."
                    badges={
                        draft ? (
                            <Badge variant="secondary">Draft</Badge>
                        ) : (
                            <Badge variant="secondary">Published</Badge>
                        )
                    }
                    actions={
                        <Button asChild variant="outline">
                            <a href={home_url} target="_blank" rel="noreferrer">
                                <ExternalLink aria-hidden="true" />
                                View home page
                            </a>
                        </Button>
                    }
                />

                {needs_text && (
                    <Alert>
                        <AlertTitle>Needs your text</AlertTitle>
                        <AlertDescription>
                            Your home page shows a placeholder headline, “
                            {placeholder_headline}”. Write your own below, save
                            the draft and publish it.
                        </AlertDescription>
                    </Alert>
                )}

                <SectionCard
                    title={draft ? 'Unpublished draft' : 'Published'}
                    description={
                        draft ? (
                            <>
                                Saved{' '}
                                <DateTime value={draft.saved_at} relative />
                                {draft.saved_by && ` by ${draft.saved_by}`}.
                                Visitors still see the published version.
                            </>
                        ) : published_at ? (
                            <>
                                Visitors see the version published{' '}
                                <DateTime value={published_at} relative />.
                            </>
                        ) : (
                            'Visitors see the default home page. Save a draft to change it.'
                        )
                    }
                    footer={
                        draft && (
                            <>
                                <ConfirmDialog
                                    trigger={
                                        <Button variant="ghost">
                                            Discard draft
                                        </Button>
                                    }
                                    title="Discard this draft?"
                                    description="Your unpublished changes are deleted. The published version stays as it is."
                                    confirmLabel="Discard draft"
                                    action={WebsiteController.discard()}
                                />
                                <Button asChild variant="outline">
                                    <a
                                        href={draft.preview_url}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <Eye aria-hidden="true" />
                                        Open preview
                                    </a>
                                </Button>
                                <ConfirmDialog
                                    trigger={
                                        <Button
                                            disabled={publishBlocked !== null}
                                        >
                                            Publish…
                                        </Button>
                                    }
                                    title="Publish these changes?"
                                    description={
                                        <span className="block space-y-2">
                                            <span className="block">
                                                Visitors see them as soon as you
                                                confirm:
                                            </span>
                                            <span className="block">
                                                {draft.changes.length === 0
                                                    ? 'No visible changes.'
                                                    : draft.changes.map(
                                                          (change) => (
                                                              <span
                                                                  key={change}
                                                                  className="block"
                                                              >
                                                                  • {change}
                                                              </span>
                                                          ),
                                                      )}
                                            </span>
                                        </span>
                                    }
                                    confirmLabel="Publish"
                                    destructive={false}
                                    method="post"
                                    data={{ reviewed: draft.review_token }}
                                    action={WebsiteController.publish()}
                                />
                            </>
                        )
                    }
                >
                    {draft && draft.changes.length > 0 && (
                        <ul className="list-inside list-disc space-y-1 text-sm">
                            {draft.changes.map((change) => (
                                <li key={change}>{change}</li>
                            ))}
                        </ul>
                    )}
                    {draft && publishBlocked && (
                        <p className="mt-3 text-sm text-muted-foreground">
                            {publishBlocked}
                        </p>
                    )}
                </SectionCard>

                <form onSubmit={submit} className="flex flex-col gap-6">
                    <UnsavedChangesGuard
                        dirty={form.isDirty && !form.processing}
                    />

                    <SectionCard
                        title="Headline"
                        description="The first words on your home page. Write about your hosting, not about this software."
                    >
                        <div className="grid gap-5">
                            <Counted
                                id="eyebrow"
                                label="Small label above the headline"
                                optional
                                max={40}
                                value={data.hero.eyebrow}
                                error={error('hero.eyebrow')}
                                onChange={(eyebrow) => set('hero', { eyebrow })}
                            />
                            <Counted
                                id="headline"
                                label="Headline"
                                max={80}
                                value={data.hero.headline}
                                placeholder={placeholder_headline}
                                error={error('hero.headline')}
                                hint="Keep it under 60 characters so it fits on two lines on phones."
                                onChange={(headline) =>
                                    set('hero', { headline })
                                }
                            />
                            <Counted
                                id="subtext"
                                label="Text under the headline"
                                optional
                                max={200}
                                multiline
                                value={data.hero.subtext}
                                error={error('hero.subtext')}
                                onChange={(subtext) => set('hero', { subtext })}
                            />
                            <SwitchField
                                id="domain_search"
                                label="Domain search"
                                description="A search box and featured extensions with first-year and renewal prices."
                                checked={data.hero.domain_search}
                                onCheckedChange={(domain_search) =>
                                    set('hero', { domain_search })
                                }
                            />
                        </div>
                    </SectionCard>

                    <SectionCard
                        title="Sections"
                        description="Turn sections on or off and put them in order. The headline and domain search always come first; sections with nothing to show stay hidden."
                    >
                        <ol className="divide-y rounded-lg border">
                            {data.sections.map((section, index) => (
                                <li
                                    key={section.key}
                                    className="flex items-center gap-2 px-3 py-2"
                                >
                                    <span className="flex-1 text-sm font-medium">
                                        {options.sections[section.key]}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-9"
                                        disabled={index === 0}
                                        onClick={() => move(index, -1)}
                                        aria-label={`Move ${options.sections[section.key]} up`}
                                    >
                                        <ArrowUp aria-hidden="true" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-9"
                                        disabled={
                                            index === data.sections.length - 1
                                        }
                                        onClick={() => move(index, 1)}
                                        aria-label={`Move ${options.sections[section.key]} down`}
                                    >
                                        <ArrowDown aria-hidden="true" />
                                    </Button>
                                    <Switch
                                        checked={section.enabled}
                                        aria-label={`Show ${options.sections[section.key]}`}
                                        onCheckedChange={(enabled) =>
                                            form.setData(
                                                'sections',
                                                data.sections.map((item) =>
                                                    item.key === section.key
                                                        ? { ...item, enabled }
                                                        : item,
                                                ),
                                            )
                                        }
                                    />
                                </li>
                            ))}
                        </ol>
                    </SectionCard>

                    <SectionCard
                        title="Why choose us"
                        description="Up to six short points. Only claim what you offer; the section stays hidden until you add one."
                    >
                        <div className="grid gap-3">
                            {data.why_us.map((point, index) => (
                                <div
                                    key={index}
                                    className="grid gap-2 rounded-lg border p-3 sm:grid-cols-[8rem_minmax(0,1fr)_auto]"
                                >
                                    <Select
                                        value={point.icon}
                                        onValueChange={(icon) =>
                                            form.setData(
                                                'why_us',
                                                data.why_us.map((item, i) =>
                                                    i === index
                                                        ? { ...item, icon }
                                                        : item,
                                                ),
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            aria-label={`Icon for point ${index + 1}`}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {options.icons.map((icon) => {
                                                const Icon = WHY_US_ICONS[icon];

                                                return (
                                                    <SelectItem
                                                        key={icon}
                                                        value={icon}
                                                    >
                                                        {Icon && (
                                                            <Icon aria-hidden="true" />
                                                        )}
                                                        <span className="capitalize">
                                                            {icon.replace(
                                                                '-',
                                                                ' ',
                                                            )}
                                                        </span>
                                                    </SelectItem>
                                                );
                                            })}
                                        </SelectContent>
                                    </Select>
                                    <div className="grid gap-2">
                                        <Input
                                            aria-label={`Title of point ${index + 1}`}
                                            maxLength={40}
                                            value={point.title}
                                            placeholder="Help every day"
                                            aria-invalid={
                                                !!error(`why_us.${index}.title`)
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'why_us',
                                                    data.why_us.map(
                                                        (item, i) =>
                                                            i === index
                                                                ? {
                                                                      ...item,
                                                                      title: event
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                        />
                                        <Input
                                            aria-label={`Text of point ${index + 1}`}
                                            maxLength={140}
                                            value={point.text}
                                            placeholder="Tickets answered around the clock."
                                            aria-invalid={
                                                !!error(`why_us.${index}.text`)
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'why_us',
                                                    data.why_us.map(
                                                        (item, i) =>
                                                            i === index
                                                                ? {
                                                                      ...item,
                                                                      text: event
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                        />
                                        {(error(`why_us.${index}.title`) ||
                                            error(`why_us.${index}.text`)) && (
                                            <p className="text-sm text-danger-text">
                                                {error(
                                                    `why_us.${index}.title`,
                                                ) ??
                                                    error(
                                                        `why_us.${index}.text`,
                                                    )}
                                            </p>
                                        )}
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-9"
                                        aria-label={`Remove point ${index + 1}`}
                                        onClick={() =>
                                            form.setData(
                                                'why_us',
                                                data.why_us.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 aria-hidden="true" />
                                    </Button>
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="outline"
                                className="w-fit"
                                disabled={data.why_us.length >= 6}
                                onClick={() =>
                                    form.setData('why_us', [
                                        ...data.why_us,
                                        { icon: 'shield', title: '', text: '' },
                                    ])
                                }
                            >
                                <Plus aria-hidden="true" />
                                Add a point
                            </Button>
                        </div>
                    </SectionCard>

                    <SectionCard
                        title="Trust facts"
                        description="Up to four short facts under the headline, such as “Hosting since · 2014”. Only state what you can show."
                    >
                        <div className="grid gap-3">
                            {data.trust_facts.map((fact, index) => (
                                <div
                                    key={index}
                                    className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]"
                                >
                                    <Input
                                        aria-label={`Label of fact ${index + 1}`}
                                        maxLength={30}
                                        placeholder="Hosting since"
                                        value={fact.label}
                                        aria-invalid={
                                            !!error(
                                                `trust_facts.${index}.label`,
                                            )
                                        }
                                        onChange={(event) =>
                                            form.setData(
                                                'trust_facts',
                                                data.trust_facts.map(
                                                    (item, i) =>
                                                        i === index
                                                            ? {
                                                                  ...item,
                                                                  label: event
                                                                      .target
                                                                      .value,
                                                              }
                                                            : item,
                                                ),
                                            )
                                        }
                                    />
                                    <Input
                                        aria-label={`Value of fact ${index + 1}`}
                                        maxLength={40}
                                        placeholder="2014"
                                        value={fact.value}
                                        aria-invalid={
                                            !!error(
                                                `trust_facts.${index}.value`,
                                            )
                                        }
                                        onChange={(event) =>
                                            form.setData(
                                                'trust_facts',
                                                data.trust_facts.map(
                                                    (item, i) =>
                                                        i === index
                                                            ? {
                                                                  ...item,
                                                                  value: event
                                                                      .target
                                                                      .value,
                                                              }
                                                            : item,
                                                ),
                                            )
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-9"
                                        aria-label={`Remove fact ${index + 1}`}
                                        onClick={() =>
                                            form.setData(
                                                'trust_facts',
                                                data.trust_facts.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 aria-hidden="true" />
                                    </Button>
                                    {(error(`trust_facts.${index}.label`) ||
                                        error(
                                            `trust_facts.${index}.value`,
                                        )) && (
                                        <p className="text-sm text-danger-text sm:col-span-3">
                                            {error(
                                                `trust_facts.${index}.label`,
                                            ) ??
                                                error(
                                                    `trust_facts.${index}.value`,
                                                )}
                                        </p>
                                    )}
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="outline"
                                className="w-fit"
                                disabled={data.trust_facts.length >= 4}
                                onClick={() =>
                                    form.setData('trust_facts', [
                                        ...data.trust_facts,
                                        { label: '', value: '' },
                                    ])
                                }
                            >
                                <Plus aria-hidden="true" />
                                Add a fact
                            </Button>
                        </div>
                    </SectionCard>

                    <SectionCard
                        title="Call to action band"
                        description="A band near the end of the page with your lowest plan price and a button to all plans. An empty headline hides it."
                    >
                        <div className="grid gap-5 sm:grid-cols-2">
                            <Counted
                                id="cta_headline"
                                label="Headline"
                                optional
                                max={80}
                                value={data.cta.headline}
                                error={error('cta.headline')}
                                onChange={(headline) =>
                                    set('cta', { headline })
                                }
                            />
                            <Counted
                                id="cta_button"
                                label="Button"
                                max={24}
                                value={data.cta.button}
                                error={error('cta.button')}
                                onChange={(button) => set('cta', { button })}
                            />
                        </div>
                    </SectionCard>

                    <SectionCard
                        title="Featured plans"
                        description="One to three product groups shown as plan cards. A plan marked Featured in the catalog is the hero's most popular plan."
                    >
                        {options.groups.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Add a visible product group in the catalog to
                                show plans.
                            </p>
                        ) : (
                            <div className="grid gap-5">
                                <fieldset className="grid gap-2">
                                    <legend className="mb-2 text-sm font-medium">
                                        Product groups
                                    </legend>
                                    {options.groups.map((group) => {
                                        const checked =
                                            data.featured.group_ids.includes(
                                                group.id,
                                            );

                                        return (
                                            <Label
                                                key={group.id}
                                                className="flex items-center gap-2 font-normal"
                                            >
                                                <Checkbox
                                                    checked={checked}
                                                    disabled={
                                                        !checked &&
                                                        data.featured.group_ids
                                                            .length >= 3
                                                    }
                                                    onCheckedChange={(on) =>
                                                        set('featured', {
                                                            group_ids: on
                                                                ? [
                                                                      ...data
                                                                          .featured
                                                                          .group_ids,
                                                                      group.id,
                                                                  ]
                                                                : data.featured.group_ids.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          group.id,
                                                                  ),
                                                        })
                                                    }
                                                />
                                                {group.name}
                                            </Label>
                                        );
                                    })}
                                    <p className="text-sm text-muted-foreground">
                                        None ticked shows the first group in the
                                        catalog.
                                    </p>
                                    {error('featured.group_ids') && (
                                        <p className="text-sm text-danger-text">
                                            {error('featured.group_ids')}
                                        </p>
                                    )}
                                </fieldset>
                                <Field
                                    htmlFor="cycle"
                                    label="Billing cycle shown first"
                                    hint={
                                        ['biennially', 'triennially'].includes(
                                            data.featured.cycle,
                                        )
                                            ? 'Longer than 12 months: visitors see a lower monthly price but pay for the whole term up front.'
                                            : 'Visitors can switch. Plans without this cycle fall back to annual, then the shortest.'
                                    }
                                >
                                    <Select
                                        value={data.featured.cycle}
                                        onValueChange={(cycle) =>
                                            set('featured', { cycle })
                                        }
                                    >
                                        <SelectTrigger
                                            id="cycle"
                                            className="sm:w-64"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {CYCLES.map((cycle) => (
                                                <SelectItem
                                                    key={cycle}
                                                    value={cycle}
                                                >
                                                    {cycleLabel(cycle)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </Field>
                            </div>
                        )}
                    </SectionCard>

                    <SectionCard
                        title="News and status"
                        description="Latest announcements, popular knowledge-base articles and network status."
                    >
                        <div className="grid gap-5 sm:grid-cols-2">
                            <CountSelect
                                id="announcements"
                                label="Announcements"
                                max={4}
                                value={data.news.announcements}
                                onChange={(announcements) =>
                                    set('news', { announcements })
                                }
                            />
                            <CountSelect
                                id="articles"
                                label="Popular articles"
                                max={6}
                                value={data.news.articles}
                                onChange={(articles) =>
                                    set('news', { articles })
                                }
                            />
                            <SwitchField
                                id="status"
                                className="sm:col-span-2"
                                label="Network status"
                                description="Open and scheduled incidents. Hidden from guests while network status requires login."
                                checked={data.news.status}
                                onCheckedChange={(status) =>
                                    set('news', { status })
                                }
                            />
                        </div>
                    </SectionCard>

                    <SectionCard
                        title="Prices and tax"
                        description={
                            <>
                                How prices read on every public page. Tax rates
                                come from{' '}
                                {options.tax_rules_url ? (
                                    <a
                                        href={options.tax_rules_url}
                                        className="text-primary hover:underline"
                                    >
                                        Billing → Tax rules
                                    </a>
                                ) : (
                                    'Billing tax rules'
                                )}{' '}
                                for the market each currency is priced for (NPR:
                                Nepal, INR: India); other currencies show a
                                neutral note.
                            </>
                        }
                    >
                        <div className="divide-y rounded-lg border">
                            {options.currencies.map((currency) => {
                                const inclusive =
                                    data.prices.tax_inclusive.includes(
                                        currency.code,
                                    );
                                const automatic = inclusive
                                    ? currency.inclusive_note
                                    : currency.exclusive_note;

                                return (
                                    <div
                                        key={currency.code}
                                        className="grid gap-3 p-3 sm:grid-cols-[4rem_minmax(0,1fr)] sm:items-start"
                                    >
                                        <span className="pt-2 font-medium">
                                            {currency.code}
                                        </span>
                                        <div className="grid gap-2">
                                            <Label className="flex items-center gap-2 font-normal">
                                                <Switch
                                                    checked={inclusive}
                                                    disabled={!currency.market}
                                                    onCheckedChange={(on) =>
                                                        set('prices', {
                                                            tax_inclusive: on
                                                                ? [
                                                                      ...data
                                                                          .prices
                                                                          .tax_inclusive,
                                                                      currency.code,
                                                                  ]
                                                                : data.prices.tax_inclusive.filter(
                                                                      (code) =>
                                                                          code !==
                                                                          currency.code,
                                                                  ),
                                                        })
                                                    }
                                                />
                                                Show prices with tax included
                                            </Label>
                                            {!currency.market && (
                                                <p className="text-xs text-muted-foreground">
                                                    {currency.code} is sold in
                                                    several markets, so prices
                                                    show without tax and the
                                                    note stays neutral.
                                                </p>
                                            )}
                                            <Input
                                                aria-label={`Tax note for ${currency.code}`}
                                                maxLength={80}
                                                value={
                                                    data.prices.notes[
                                                        currency.code
                                                    ] ?? ''
                                                }
                                                placeholder={
                                                    automatic ??
                                                    'No tax applies; leave empty to show no note'
                                                }
                                                onChange={(event) =>
                                                    set('prices', {
                                                        notes: {
                                                            ...data.prices
                                                                .notes,
                                                            [currency.code]:
                                                                event.target
                                                                    .value,
                                                        },
                                                    })
                                                }
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Leave empty to use “
                                                {automatic ?? 'no note'}”. Shown
                                                under every price group and in
                                                the footer.
                                            </p>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </SectionCard>

                    <SectionCard
                        title="Header and footer"
                        description={
                            <>
                                Shared by every public page. Company name,
                                address and tax numbers come from{' '}
                                {options.company_url ? (
                                    <a
                                        href={options.company_url}
                                        className="text-primary hover:underline"
                                    >
                                        Settings → Company
                                    </a>
                                ) : (
                                    'Settings → Company'
                                )}{' '}
                                and the invoice seller details.
                            </>
                        }
                    >
                        <div className="grid gap-5">
                            <Field
                                htmlFor="products"
                                label="Products in the header"
                            >
                                <Select
                                    value={data.header.products}
                                    onValueChange={(products) =>
                                        set('header', {
                                            products:
                                                products as Content['header']['products'],
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id="products"
                                        className="sm:w-96"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="auto">
                                            Automatic: a link per group up to 3
                                            groups, then one menu
                                        </SelectItem>
                                        <SelectItem value="menu">
                                            Always one menu
                                        </SelectItem>
                                        <SelectItem value="links">
                                            A link for each group (one menu
                                            above 3 groups)
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Counted
                                id="menu_label"
                                label="Menu name"
                                max={24}
                                value={data.header.menu_label}
                                error={error('header.menu_label')}
                                hint="Used when products are in one menu."
                                onChange={(menu_label) =>
                                    set('header', { menu_label })
                                }
                            />
                            {(
                                [
                                    'terms_url',
                                    'privacy_url',
                                    'refund_url',
                                ] as const
                            ).map((key) => (
                                <Field
                                    key={key}
                                    htmlFor={key}
                                    optional
                                    label={
                                        {
                                            terms_url: 'Terms of service link',
                                            privacy_url: 'Privacy policy link',
                                            refund_url: 'Refund policy link',
                                        }[key]
                                    }
                                    error={error(`legal.${key}`)}
                                    hint="An https:// address. Shown in the footer only when set."
                                >
                                    <Input
                                        id={key}
                                        type="url"
                                        inputMode="url"
                                        placeholder="https://"
                                        value={data.legal[key]}
                                        aria-invalid={!!error(`legal.${key}`)}
                                        onChange={(event) =>
                                            set('legal', {
                                                [key]: event.target.value,
                                            })
                                        }
                                    />
                                </Field>
                            ))}
                        </div>
                    </SectionCard>

                    <SectionCard
                        title="Search and sharing"
                        description="How the home page appears in search results and when someone shares the link."
                    >
                        <div className="grid gap-5">
                            <Counted
                                id="seo_title"
                                label="Page title"
                                optional
                                max={60}
                                value={data.seo.title}
                                error={error('seo.title')}
                                placeholder={`${brand.name} | ${data.hero.headline || placeholder_headline}`}
                                hint="Shown in the browser tab and search results."
                                onChange={(title) => set('seo', { title })}
                            />
                            <Counted
                                id="seo_description"
                                label="Description"
                                optional
                                multiline
                                max={160}
                                value={data.seo.description}
                                error={error('seo.description')}
                                hint="Shown under the title in search results. Empty uses the text under the headline."
                                onChange={(description) =>
                                    set('seo', { description })
                                }
                            />
                            <SwitchField
                                id="noindex"
                                label="Hide from search engines"
                                description="For a staging or test site. The home page is also left out of the sitemap."
                                checked={data.seo.noindex}
                                onCheckedChange={(noindex) =>
                                    set('seo', { noindex })
                                }
                            />
                        </div>
                    </SectionCard>

                    <FormActions
                        sticky
                        status={form.isDirty ? 'Unsaved changes' : undefined}
                    >
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={!form.isDirty || form.processing}
                            onClick={() => form.reset()}
                        >
                            Undo changes
                        </Button>
                        <Button
                            type="submit"
                            disabled={!form.isDirty || form.processing}
                        >
                            Save draft
                        </Button>
                    </FormActions>
                </form>
            </Page>
        </>
    );
}

function Counted({
    id,
    label,
    max,
    value,
    onChange,
    error,
    hint,
    optional,
    multiline,
    placeholder,
}: {
    id: string;
    label: string;
    max: number;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    hint?: ReactNode;
    optional?: boolean;
    multiline?: boolean;
    placeholder?: string;
}) {
    const Control = multiline ? Textarea : Input;

    return (
        <Field
            htmlFor={id}
            label={label}
            optional={optional}
            error={error}
            hint={
                <span className="flex justify-between gap-4">
                    <span>{hint}</span>
                    <span className="tabular-nums">
                        {value.length} / {max}
                    </span>
                </span>
            }
        >
            <Control
                id={id}
                value={value}
                maxLength={max}
                placeholder={placeholder}
                aria-invalid={!!error}
                aria-describedby={`${id}-description`}
                onChange={(event: { target: { value: string } }) =>
                    onChange(event.target.value)
                }
            />
        </Field>
    );
}

function CountSelect({
    id,
    label,
    max,
    value,
    onChange,
}: {
    id: string;
    label: string;
    max: number;
    value: number;
    onChange: (value: number) => void;
}) {
    return (
        <Field htmlFor={id} label={label}>
            <Select
                value={String(value)}
                onValueChange={(next) => onChange(Number(next))}
            >
                <SelectTrigger id={id}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {Array.from({ length: max + 1 }, (_, count) => (
                        <SelectItem key={count} value={String(count)}>
                            {count === 0 ? 'Hide' : count}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </Field>
    );
}
