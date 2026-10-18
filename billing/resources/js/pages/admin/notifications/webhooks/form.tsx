import { Head, useForm } from '@inertiajs/react';
import WebhookEndpointController from '@/actions/App/Modules/Notifications/Http/Controllers/Admin/WebhookEndpointController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { SwitchField } from '@/components/app/switch-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { WebhookEndpointSummary } from '@/types/notifications';

export default function WebhookForm({
    endpoint,
    eventTypes,
}: {
    endpoint: WebhookEndpointSummary | null;
    eventTypes: string[];
}) {
    const form = useForm({
        url: endpoint?.url ?? '',
        description: endpoint?.description ?? '',
        events: endpoint?.events ?? ['*'],
        is_active: endpoint?.is_active ?? true,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const allEvents = form.data.events.includes('*');

    const toggleEvent = (type: string, checked: boolean) =>
        form.setData(
            'events',
            checked
                ? [...form.data.events.filter((event) => event !== '*'), type]
                : form.data.events.filter((event) => event !== type),
        );

    return (
        <>
            <Head
                title={
                    endpoint ? 'Edit webhook endpoint' : 'Add webhook endpoint'
                }
            />
            <Page size="narrow">
                <PageHeader
                    back={
                        endpoint
                            ? {
                                  href: WebhookEndpointController.show(
                                      endpoint.id,
                                  ),
                                  label: 'Endpoint',
                              }
                            : {
                                  href: WebhookEndpointController.index(),
                                  label: 'Webhooks',
                              }
                    }
                    title={
                        endpoint
                            ? 'Edit webhook endpoint'
                            : 'Add webhook endpoint'
                    }
                    description="Requests are signed with a secret shown once after saving. Private and internal addresses are refused."
                />
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.submit(
                            endpoint
                                ? WebhookEndpointController.update(endpoint.id)
                                : WebhookEndpointController.store(),
                        );
                    }}
                    className="flex flex-col gap-6"
                >
                    <SectionCard>
                        <div className="grid gap-6">
                            <Field htmlFor="url" label="URL" error={errors.url}>
                                <Input
                                    id="url"
                                    type="url"
                                    placeholder="https://hooks.example.com/billing"
                                    value={form.data.url}
                                    onChange={(event) =>
                                        form.setData('url', event.target.value)
                                    }
                                    className="font-mono"
                                    required
                                    aria-invalid={!!errors.url}
                                />
                            </Field>
                            <Field
                                htmlFor="description"
                                label="Description"
                                optional
                                error={errors.description}
                            >
                                <Input
                                    id="description"
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <SwitchField
                                id="is_active"
                                label="Active"
                                description="Paused endpoints receive nothing. Re-activating resets the failure counter."
                                checked={form.data.is_active}
                                onCheckedChange={(checked) =>
                                    form.setData('is_active', checked)
                                }
                            />
                        </div>
                    </SectionCard>
                    <SectionCard title="Events" description={errors.events}>
                        <div className="grid gap-3">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="event-all"
                                    checked={allEvents}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'events',
                                            checked === true ? ['*'] : [],
                                        )
                                    }
                                />
                                <Label htmlFor="event-all">
                                    All events, including ones added later
                                </Label>
                            </div>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {eventTypes
                                    .filter((type) => type !== 'webhook.ping')
                                    .map((type) => (
                                        <div
                                            key={type}
                                            className="flex items-center gap-2"
                                        >
                                            <Checkbox
                                                id={`event-${type}`}
                                                checked={
                                                    allEvents ||
                                                    form.data.events.includes(
                                                        type,
                                                    )
                                                }
                                                disabled={allEvents}
                                                onCheckedChange={(checked) =>
                                                    toggleEvent(
                                                        type,
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            <Label
                                                htmlFor={`event-${type}`}
                                                className="font-mono text-xs"
                                            >
                                                {type}
                                            </Label>
                                        </div>
                                    ))}
                            </div>
                        </div>
                    </SectionCard>
                    <FormActions
                        status={
                            endpoint && (
                                <ConfirmDialog
                                    trigger={
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="text-destructive"
                                        >
                                            Delete endpoint
                                        </Button>
                                    }
                                    title="Delete this endpoint?"
                                    description="Its delivery log is deleted too. The receiver stops getting events immediately."
                                    confirmLabel="Delete endpoint"
                                    destructive
                                    action={WebhookEndpointController.destroy(
                                        endpoint.id,
                                    )}
                                />
                            )
                        }
                    >
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {endpoint ? 'Save endpoint' : 'Add endpoint'}
                        </Button>
                    </FormActions>
                </form>
            </Page>
        </>
    );
}
