import { Form, Link, router } from '@inertiajs/react';
import { Eye, EyeOff, LockKeyhole } from 'lucide-react';
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
import { KYC_TONE } from '@/components/kyc/kyc-tone';
import { useUnlockWindow } from '@/components/kyc/unlock-window';

type Document = {
    id: number;
    type: string;
    status: 'pending' | 'approved' | 'declined';
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
    status: { value: string; label: string; verifiedAt: string | null };
    steps: { email: boolean; phone: boolean };
    unlockedUntil: string | null;
    unlockedFor: number | null;
    unlockGeneration: number;
    canDecide: boolean;
    documents: Document[];
};

function Decision({
    document,
    unlocked,
    canDecide,
}: {
    document: Document;
    unlocked: boolean;
    canDecide: boolean;
}) {
    const [declining, setDeclining] = useState(false);
    const ready = unlocked && canDecide;
    const blocked = !canDecide
        ? 'Only administrators can approve or decline identity documents.'
        : !unlocked
          ? 'Show the documents first to decide.'
          : null;

    if (declining && ready) {
        return (
            <Form
                {...ReviewController.decide.form(document.id)}
                options={{ preserveScroll: true }}
                noValidate
                className="grid gap-3"
            >
                {({ errors, processing }) => (
                    <>
                        <input type="hidden" name="decision" value="decline" />
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
                                Decline document
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setDeclining(false)}
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
        <div className="flex flex-wrap items-center gap-2">
            {ready ? (
                <ConfirmDialog
                    trigger={<Button>Approve</Button>}
                    title={`Approve the ${document.type}?`}
                    description="The client cannot replace an approved document, and we email them the decision. Check that the number and both sides are readable and match the account holder."
                    confirmLabel="Approve document"
                    destructive={false}
                    action={ReviewController.decide(document.id)}
                    method="post"
                    data={{ decision: 'approve', version: document.version }}
                />
            ) : (
                <Button disabled>Approve</Button>
            )}
            <Button
                variant="outline"
                disabled={!ready}
                onClick={() => setDeclining(true)}
            >
                Decline with a reason
            </Button>
            {blocked && (
                <p className="text-sm text-muted-foreground">{blocked}</p>
            )}
        </div>
    );
}

export default function KycReview({
    client,
    status,
    steps,
    unlockedUntil,
    unlockedFor,
    unlockGeneration,
    canDecide,
    documents,
}: Props) {
    const [opening, setOpening] = useState(false);
    const [hiding, setHiding] = useState(false);
    // False from the moment the window ends, also on a page restored from history; the page then reloads.
    const unlocked = useUnlockWindow(unlockedUntil, unlockedFor);

    return (
        <>
            <Head title={client.name} />
            <Page>
                <PageHeader
                    title={client.name}
                    description={
                        status.verifiedAt ? (
                            <>
                                Verified on{' '}
                                <DateTime value={status.verifiedAt} />
                            </>
                        ) : undefined
                    }
                    back={{
                        href: ReviewController.index(),
                        label: 'Identity verification',
                    }}
                    actions={
                        <StatusBadge
                            status={status.value}
                            label={status.label}
                            tone={
                                KYC_TONE[status.value as keyof typeof KYC_TONE]
                            }
                        />
                    }
                />

                <SectionCard title="Client steps">
                    <DescriptionList
                        items={[
                            {
                                label: 'Account',
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

                {documents.length > 0 && (
                    <SectionCard>
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                            <div className="flex min-w-0 flex-1 items-start gap-3">
                                {unlocked ? (
                                    <Eye
                                        className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                ) : (
                                    <LockKeyhole
                                        className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                )}
                                <p className="text-sm text-muted-foreground">
                                    {unlocked && unlockedUntil ? (
                                        <>
                                            Documents are visible until{' '}
                                            <DateTime
                                                value={unlockedUntil}
                                                format="datetime"
                                            />{' '}
                                            (
                                            <DateTime
                                                value={unlockedUntil}
                                                relative
                                            />
                                            ). Each image you open is recorded
                                            in the activity log.
                                        </>
                                    ) : (
                                        'Document numbers and images are hidden. Showing them asks for your password, is recorded in the activity log, and lasts up to 15 minutes.'
                                    )}
                                </p>
                            </div>
                            {unlocked && (
                                <Button
                                    variant="outline"
                                    className="w-full sm:w-auto"
                                    disabled={hiding}
                                    onClick={() =>
                                        router.post(
                                            ReviewController.lock.url(
                                                client.id,
                                            ),
                                            {},
                                            {
                                                preserveScroll: true,
                                                onStart: () => setHiding(true),
                                                onFinish: () =>
                                                    setHiding(false),
                                            },
                                        )
                                    }
                                >
                                    {hiding ? <Spinner /> : <EyeOff />}
                                    Hide documents
                                </Button>
                            )}
                            {!unlocked && (
                                <Button
                                    className="w-full sm:w-auto"
                                    disabled={opening}
                                    onClick={() =>
                                        router.post(
                                            ReviewController.unlock.url(
                                                client.id,
                                            ),
                                            { generation: unlockGeneration },
                                            {
                                                preserveScroll: true,
                                                onStart: () => setOpening(true),
                                                onFinish: () =>
                                                    setOpening(false),
                                            },
                                        )
                                    }
                                >
                                    {opening ? <Spinner /> : <Eye />}
                                    Show documents
                                </Button>
                            )}
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
                                    tone={KYC_TONE[document.status]}
                                />
                            }
                        >
                            <div className="grid gap-4">
                                {unlocked && document.number && (
                                    <p className="text-sm">
                                        Number{' '}
                                        <span className="font-mono">
                                            {document.number}
                                        </span>
                                    </p>
                                )}
                                {unlocked && document.images.length > 0 && (
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
                                {document.status === 'declined' &&
                                    document.reason && (
                                        <p className="text-sm text-muted-foreground">
                                            Reason given: {document.reason}
                                        </p>
                                    )}
                                {document.status === 'pending' && (
                                    <Decision
                                        document={document}
                                        unlocked={unlocked}
                                        canDecide={canDecide}
                                    />
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
