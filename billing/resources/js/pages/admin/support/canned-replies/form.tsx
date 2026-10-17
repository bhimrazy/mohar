import { Form, Head } from '@inertiajs/react';
import CannedReplyController from '@/actions/App/Modules/Support/Http/Controllers/Admin/CannedReplyController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
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
import { Textarea } from '@/components/ui/textarea';
import type { SelectOption } from '@/types/app';

type Reply = { id: number; name: string; category_id: number | null; body: string; sort_order: number };

export default function CannedReplyForm({ reply, categories, tokens }: { reply?: Reply; categories: SelectOption[]; tokens: string[] }) {
    const action = reply ? CannedReplyController.update.form(reply.id) : CannedReplyController.store.form();

    return (
        <>
            <Head title={reply ? `Edit ${reply.name}` : 'New canned reply'} />
            <Page size="narrow">
                <PageHeader back={{ href: CannedReplyController.index(), label: 'Canned replies' }} title={reply ? 'Edit canned reply' : 'New canned reply'} />
                <Form {...action} className="flex flex-col gap-6">
                    {({ errors, processing }) => (
                        <>
                            <SectionCard>
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field htmlFor="name" label="Name" error={errors.name}>
                                        <Input id="name" name="name" defaultValue={reply?.name} required aria-invalid={!!errors.name} />
                                    </Field>
                                    <Field htmlFor="category_id" label="Category" optional error={errors.category_id}>
                                        <Select name="category_id" defaultValue={reply?.category_id ? String(reply.category_id) : undefined}>
                                            <SelectTrigger id="category_id" className="w-full">
                                                <SelectValue placeholder="No category" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {categories.map((category) => (
                                                    <SelectItem key={category.value} value={category.value}>
                                                        {category.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </Field>
                                    <Field
                                        htmlFor="body"
                                        label="Reply text"
                                        hint={<>Markdown. Tokens: {tokens.map((token) => <code key={token} className="mx-0.5 rounded bg-muted px-1 font-mono text-xs">{`{{ ${token} }}`}</code>)}</>}
                                        error={errors.body}
                                        className="sm:col-span-2"
                                    >
                                        <Textarea id="body" name="body" rows={10} defaultValue={reply?.body} required aria-invalid={!!errors.body} aria-describedby="body-description" />
                                    </Field>
                                    <Field htmlFor="sort_order" label="Sort order" optional error={errors.sort_order}>
                                        <Input id="sort_order" name="sort_order" type="number" min={0} defaultValue={reply?.sort_order ?? 0} />
                                    </Field>
                                </div>
                            </SectionCard>
                            <FormActions
                                status={reply && (
                                    <ConfirmDialog
                                        trigger={<Button type="button" variant="ghost" className="text-destructive">Delete</Button>}
                                        title={`Delete "${reply.name}"?`}
                                        description="Staff can no longer insert this reply. Sent replies are not affected."
                                        confirmLabel="Delete canned reply"
                                        action={CannedReplyController.destroy(reply.id)}
                                    />
                                )}
                            >
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {reply ? 'Save canned reply' : 'Create canned reply'}
                                </Button>
                            </FormActions>
                        </>
                    )}
                </Form>
            </Page>
        </>
    );
}
