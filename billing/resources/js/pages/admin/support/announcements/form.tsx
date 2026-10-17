import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import AnnouncementController from '@/actions/App/Modules/Support/Http/Controllers/Admin/AnnouncementController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';

type Announcement = { id: number; title: string; slug: string; excerpt: string | null; body: string; is_published: boolean; published_at: string | null };

export default function AnnouncementForm({ announcement }: { announcement?: Announcement }) {
    const [published, setPublished] = useState(announcement?.is_published ?? false);
    const action = announcement ? AnnouncementController.update.form(announcement.id) : AnnouncementController.store.form();

    return (
        <>
            <Head title={announcement ? announcement.title : 'New announcement'} />
            <Page size="narrow">
                <PageHeader back={{ href: AnnouncementController.index(), label: 'Announcements' }} title={announcement ? 'Edit announcement' : 'New announcement'} />
                <Form {...action} className="flex flex-col gap-6">
                    {({ errors, processing }) => (
                        <>
                            <input type="hidden" name="is_published" value={published ? '1' : '0'} />
                            <SectionCard title="Content">
                                <div className="grid gap-6">
                                    <Field htmlFor="title" label="Title" error={errors.title}>
                                        <Input id="title" name="title" defaultValue={announcement?.title} required aria-invalid={!!errors.title} />
                                    </Field>
                                    <Field htmlFor="excerpt" label="Summary" optional hint="One or two sentences for lists and the client dashboard." error={errors.excerpt}>
                                        <Textarea id="excerpt" name="excerpt" rows={2} defaultValue={announcement?.excerpt ?? ''} aria-describedby="excerpt-description" />
                                    </Field>
                                    <Field htmlFor="body" label="Body" hint="Markdown." error={errors.body}>
                                        <Textarea id="body" name="body" rows={12} defaultValue={announcement?.body} required aria-invalid={!!errors.body} aria-describedby="body-description" />
                                    </Field>
                                </div>
                            </SectionCard>
                            <SectionCard title="Publishing">
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <div className="flex items-center justify-between gap-4 rounded-lg border p-3 sm:col-span-2">
                                        <div className="space-y-0.5">
                                            <label htmlFor="published" className="text-sm font-medium">Published</label>
                                            <p className="text-sm text-muted-foreground">Visible once the publish date has passed.</p>
                                        </div>
                                        <Switch id="published" checked={published} onCheckedChange={setPublished} />
                                    </div>
                                    <Field htmlFor="published_at" label="Publish date" optional hint="Leave empty to publish now." error={errors.published_at}>
                                        <Input id="published_at" name="published_at" type="datetime-local" defaultValue={announcement?.published_at ?? ''} aria-describedby="published_at-description" />
                                    </Field>
                                    <Field htmlFor="slug" label="Slug" optional error={errors.slug}>
                                        <Input id="slug" name="slug" defaultValue={announcement?.slug} />
                                    </Field>
                                </div>
                            </SectionCard>
                            <FormActions
                                status={announcement && (
                                    <ConfirmDialog
                                        trigger={<Button type="button" variant="ghost" className="text-destructive">Delete</Button>}
                                        title={`Delete "${announcement.title}"?`}
                                        description="The announcement is removed from all lists. This can't be undone."
                                        confirmLabel="Delete announcement"
                                        action={AnnouncementController.destroy(announcement.id)}
                                    />
                                )}
                            >
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {announcement ? 'Save announcement' : 'Create announcement'}
                                </Button>
                            </FormActions>
                        </>
                    )}
                </Form>
            </Page>
        </>
    );
}
