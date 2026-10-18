import { Head, useForm } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import EmailPreferenceController from '@/actions/App/Modules/Notifications/Http/Controllers/Client/EmailPreferenceController';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';

type Row = {
    scope: string;
    kind: 'owner' | 'user' | 'contact';
    name: string;
    email: string;
    categories: Record<string, boolean>;
    editable: boolean;
};

type Category = { value: string; label: string; required: boolean };

const KIND_LABEL: Record<Row['kind'], string> = {
    owner: 'Account email',
    user: 'Login',
    contact: 'Contact',
};

export default function EmailPreferences({
    rows,
    categories,
    canManage,
}: {
    rows: Row[];
    categories: Category[];
    canManage: boolean;
}) {
    const form = useForm({
        preferences: rows
            .filter((row) => row.editable)
            .map((row) => ({ scope: row.scope, categories: row.categories })),
    });
    const errors = form.errors as Record<string, string | undefined>;

    const value = (row: Row, category: string) =>
        form.data.preferences.find((item) => item.scope === row.scope)
            ?.categories[category] ?? row.categories[category];

    const set = (scope: string, category: string, checked: boolean) =>
        form.setData(
            'preferences',
            form.data.preferences.map((item) =>
                item.scope === scope
                    ? {
                          ...item,
                          categories: {
                              ...item.categories,
                              [category]: checked,
                          },
                      }
                    : item,
            ),
        );

    return (
        <>
            <Head title="Email preferences" />
            <Page>
                <PageHeader
                    title="Email preferences"
                    description="Choose who on this account receives which emails. Sign-in and security emails are always sent."
                />
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.submit(EmailPreferenceController.update(), {
                            preserveScroll: true,
                        });
                    }}
                    className="flex flex-col gap-6"
                >
                    {!canManage && (
                        <Alert>
                            <Lock />
                            <AlertDescription>
                                Email preferences are managed by our team.
                                Contact support to change them.
                            </AlertDescription>
                        </Alert>
                    )}
                    {errors.preferences && (
                        <Alert variant="destructive">
                            <AlertDescription>
                                {errors.preferences}
                            </AlertDescription>
                        </Alert>
                    )}
                    <div className="grid gap-4">
                        {rows.map((row) => (
                            <SectionCard
                                key={row.scope}
                                title={
                                    <span className="flex flex-wrap items-center gap-2">
                                        {row.name || row.email}
                                        <Badge variant="outline">
                                            {KIND_LABEL[row.kind]}
                                        </Badge>
                                    </span>
                                }
                                description={row.email}
                            >
                                <div className="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {categories.map((category) => (
                                        <label
                                            key={category.value}
                                            htmlFor={`${row.scope}-${category.value}`}
                                            className="flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm"
                                        >
                                            <span>
                                                {category.label}
                                                {category.required && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        Someone must receive
                                                        these
                                                    </span>
                                                )}
                                            </span>
                                            <Switch
                                                id={`${row.scope}-${category.value}`}
                                                checked={value(
                                                    row,
                                                    category.value,
                                                )}
                                                disabled={
                                                    !row.editable || !canManage
                                                }
                                                onCheckedChange={(checked) =>
                                                    set(
                                                        row.scope,
                                                        category.value,
                                                        checked,
                                                    )
                                                }
                                            />
                                        </label>
                                    ))}
                                </div>
                            </SectionCard>
                        ))}
                    </div>
                    {canManage && (
                        <FormActions>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing && <Spinner />}
                                Save preferences
                            </Button>
                        </FormActions>
                    )}
                </form>
            </Page>
        </>
    );
}
