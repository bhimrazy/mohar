import { Form, Link, router } from '@inertiajs/react';
import { Eye, LockKeyhole } from 'lucide-react';
import { useState } from 'react';
import ReviewController from '@/actions/App/Modules/Kyc/Http/Controllers/Admin/ReviewController';
import ClientController from '@/actions/App/Modules/Core/Http/Controllers/Admin/ClientController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { EmptyState } from '@/components/app/empty-state';
import { Field } from '@/components/app/field';
import { Head } from '@/components/app/head';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

type Document = {
    id: number;
    type: string;
    status: 'pending' | 'approved' | 'rejected';
    statusLabel: string;
    reason: string | null;
    uploadedAt: string | null;
    decidedAt: string | null;
    version: string;
    number: string | null;
    images: { side: string; label: string; url: string }[];
};

type Props = {
    client: { id: number; name: string; email: string; phone: string | null };
    status: { value: string; label: string };
    steps: { email: boolean; phone: boolean };
    unlocked: boolean;
    documents: Document[];
};

const TONE = {
    not_started: 'neutral',
    in_progress: 'info',
    pending_review: 'warning',
    rejected: 'danger',
    verified: 'success',
    pending: 'warning',
    approved: 'success',
} as const;

function Decision({ document }: { document: Document }) {
    const [rejecting, setRejecting] = useState(false);

    if (rejecting) {
        return (
            <Form
                {...ReviewController.decide.form(document.id)}
                options={{ preserveScroll: true }}
                className="grid gap-3"
            >
                {({ errors, processing }) => (
                    <>
                        <input type="hidden" name="decision" value="reject" />
                        <input
                            type="hidden"
                            name="version"
                            value={document.version}
                        />
                        <Field
                            htmlFor={`reason-${document.id}`}
                            label="Reason the client will see"
                            hint="Say what to fix, for example: the back is blurred, so the issue date cannot be read."
                            error={errors.reason ?? errors.document}
                        >
                            <Textarea
                                id={`reason-${document.id}`}
                                name="reason"
                                maxLength={500}
                                required
                                aria-invalid={!!errors.reason}
                                aria-describedby={`reason-${document.id}-description`}
                            />
                        </Field>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                Reject document
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setRejecting(false)}
                            >
                                Cancel
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        );
    }

    return (
        <div className="flex flex-wrap gap-2">
            <ConfirmDialog
                trigger={<Button>Approve</Button>}
                title={`Approve the ${document.type}?`}
                description="The client cannot replace an approved document. Check that the number and both sides are readable and match the account holder."
                confirmLabel="Approve document"
                destructive={false}
                action={ReviewController.decide(document.id)}
                method="post"
                data={{ decision: 'approve', version: document.version }}
            />
            <Button variant="outline" onClick={() => setRejecting(true)}>
                Reject with a reason
            </Button>
        </div>
    );
}

export default function KycReview({
    client,
    status,
    steps,
    unlocked,
    documents,
}: Props) {
    const [opening, setOpening] = useState(false);

    return (
        <>
            <Head title={client.name} />
            <Page>
                <PageHeader
                    title={client.name}
                    description="Identity verification"
                    back={{
                        href: ReviewController.index(),
                        label: 'Identity verification',
                    }}
                    actions={
                        <StatusBadge
                            status={status.value}
                            label={status.label}
                            tone={TONE[status.value as keyof typeof TONE]}
                        />
                    }
                />

                <SectionCard title="Client steps">
                    <DescriptionList
                        items={[
                            {
                                label: 'Client',
                                value: (
                                    <Link
                                        href={ClientController.show(client.id)}
                                        className="underline underline-offset-4"
                                    >
                                        {client.email}
                                    </Link>
                                ),
                            },
                            {
                                label: 'Email',
                                value: steps.email
                                    ? 'Confirmed'
                                    : 'Not confirmed',
                            },
                            {
                                label: 'Phone',
                                value: client.phone
                                    ? `${client.phone}${steps.phone ? '' : ' (not a valid number)'}`
                                    : 'Not given',
                            },
                        ]}
                    />
                </SectionCard>

                {!unlocked && documents.length > 0 && (
                    <SectionCard>
                        <div className="flex flex-wrap items-center gap-3">
                            <LockKeyhole
                                className="size-5 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <p className="min-w-0 flex-1 text-sm text-muted-foreground">
                                Document numbers and images are hidden. Opening
                                them asks for your password, is recorded in the
                                activity log, and lasts 15 minutes.
                            </p>
                            <Button
                                disabled={opening}
                                onClick={() =>
                                    router.post(
                                        ReviewController.unlock.url(client.id),
                                        {},
                                        {
                                            preserveScroll: true,
                                            onStart: () => setOpening(true),
                                            onFinish: () => setOpening(false),
                                        },
                                    )
                                }
                            >
                                {opening ? <Spinner /> : <Eye />}
                                Show documents
                            </Button>
                        </div>
                    </SectionCard>
                )}

                {documents.length === 0 ? (
                    <SectionCard>
                        <EmptyState
                            title="No documents"
                            description="This client has not uploaded an identity document."
                        />
                    </SectionCard>
                ) : (
                    documents.map((document) => (
                        <SectionCard
                            key={document.id}
                            title={document.type}
                            description={
                                <>
                                    {document.uploadedAt && (
                                        <>
                                            Uploaded{' '}
                                            <DateTime
                                                value={document.uploadedAt}
                                            />
                                        </>
                                    )}
                                    {document.decidedAt && (
                                        <>
                                            {' '}
                                            · decided{' '}
                                            <DateTime
                                                value={document.decidedAt}
                                            />
                                        </>
                                    )}
                                </>
                            }
                            actions={
                                <StatusBadge
                                    status={document.status}
                                    label={document.statusLabel}
                                    tone={TONE[document.status]}
                                />
                            }
                        >
                            <div className="grid gap-4">
                                {document.number && (
                                    <p className="text-sm">
                                        Number{' '}
                                        <span className="font-mono">
                                            {document.number}
                                        </span>
                                    </p>
                                )}
                                {document.images.length > 0 && (
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        {document.images.map((image) => (
                                            <figure
                                                key={image.side}
                                                className="grid gap-2"
                                            >
                                                <a
                                                    href={image.url}
                                                    target="_blank"
                                                    rel="noreferrer noopener"
                                                >
                                                    <img
                                                        src={image.url}
                                                        alt={`${document.type}, ${image.label.toLowerCase()}`}
                                                        className="max-h-80 w-full rounded-md border bg-muted object-contain"
                                                    />
                                                </a>
                                                <figcaption className="text-sm text-muted-foreground">
                                                    {image.label}
                                                </figcaption>
                                            </figure>
                                        ))}
                                    </div>
                                )}
                                {document.status === 'rejected' &&
                                    document.reason && (
                                        <p className="text-sm text-muted-foreground">
                                            Reason given: {document.reason}
                                        </p>
                                    )}
                                {document.status === 'pending' && (
                                    <Decision document={document} />
                                )}
                            </div>
                        </SectionCard>
                    ))
                )}
            </Page>
        </>
    );
}

KycReview.layout = {
    breadcrumbs: [
        { title: 'Identity verification', href: ReviewController.index() },
    ],
};
