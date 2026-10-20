import { useForm } from '@inertiajs/react';
import { Check, Circle, FileImage, Upload } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import NpRegistrationController from '@/actions/App/Modules/Domains/Http/Controllers/Admin/NpRegistrationController';
import NpDocumentController from '@/actions/App/Modules/Domains/Http/Controllers/Client/NpDocumentController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { Field } from '@/components/app/field';
import { SectionCard } from '@/components/app/section-card';
import { SelectField } from '@/components/app/select-field';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/date';
import { NP_DOCUMENTS, NP_REQUIRED } from '@/types/domains';
import type { NpRequest } from '@/types/domains';

/** A step form value that is text, or '' when it is not. */
const text = (value: unknown): string =>
    typeof value === 'string' ? value : '';

type StepData = Record<string, string | boolean | Record<string, boolean>>;

const STEPS = [
    { stage: 'awaiting_documents', label: 'Documents' },
    { stage: 'documents_review', label: 'Our review' },
    { stage: 'submitted', label: 'Registry review' },
    { stage: 'approved', label: 'Registered' },
] as const;

const STAGE_LABELS: Record<NpRequest['stage'], string> = {
    awaiting_documents: 'Waiting for documents',
    documents_review: 'Documents to review',
    ready_to_submit: 'Ready to submit in the portal',
    submitted: 'Submitted to the registry',
    approved: 'Approved by the registry',
};

const CHECKLIST: Record<string, string> = {
    name_matches: 'The name matches the documents',
    extension_fits: 'The extension fits the applicant (.com.np, .org.np …)',
    readable: 'Every document is readable',
    letter_signed: 'The letter is on letterhead, stamped and signed',
    nameservers_valid: 'Nameservers are hostnames that resolve',
    owner_contact: 'The admin contact is the owner, not us',
};

const stepIndex = (stage: NpRequest['stage']) =>
    stage === 'ready_to_submit'
        ? 1
        : STEPS.findIndex((step) => step.stage === stage);

function Steps({ stage }: { stage: NpRequest['stage'] }) {
    const current = stepIndex(stage);

    return (
        <ol className="grid gap-2 sm:grid-cols-4">
            {STEPS.map((step, index) => (
                <li
                    key={step.stage}
                    className="flex items-center gap-2 text-sm"
                    aria-current={index === current ? 'step' : undefined}
                >
                    {index < current || stage === 'approved' ? (
                        <Check className="size-4 text-primary" />
                    ) : (
                        <Circle
                            className={
                                index === current
                                    ? 'size-4 text-primary'
                                    : 'size-4 text-muted-foreground'
                            }
                        />
                    )}
                    <span
                        className={
                            index === current
                                ? 'font-medium'
                                : 'text-muted-foreground'
                        }
                    >
                        {step.label}
                    </span>
                </li>
            ))}
        </ol>
    );
}

function Reasons({ request }: { request: NpRequest }) {
    if (request.rejected) {
        return (
            <Alert variant="destructive">
                <AlertTitle>The registry declined the request</AlertTitle>
                <AlertDescription>
                    {request.rejection_note}
                    {request.rejection_code &&
                        ` (cause ${request.rejection_code})`}
                </AlertDescription>
            </Alert>
        );
    }

    return request.returned_reasons ? (
        <Alert>
            <AlertTitle>Changes needed</AlertTitle>
            <AlertDescription>{request.returned_reasons}</AlertDescription>
        </Alert>
    ) : null;
}

function Documents({ request }: { request: NpRequest }) {
    if (request.documents.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">No documents yet.</p>
        );
    }

    return (
        <ul className="divide-y rounded-lg border">
            {request.documents.map((document) => (
                <li
                    key={document.id}
                    className="flex flex-wrap items-center gap-x-3 gap-y-1 p-3 text-sm"
                >
                    <FileImage className="size-4 text-muted-foreground" />
                    <span className="min-w-0 flex-1 basis-48">
                        <span className="block font-medium">
                            {NP_DOCUMENTS[document.kind] ?? document.kind}
                        </span>
                        <span className="block truncate text-muted-foreground">
                            {document.name} ·{' '}
                            <DateTime value={document.uploaded_at} />
                        </span>
                    </span>
                    {!document.current && document.delete_after && (
                        <Badge variant="outline">
                            Deleted on {formatDate(document.delete_after)}
                        </Badge>
                    )}
                    {document.url && (
                        <Button asChild variant="outline" size="sm">
                            <a
                                href={document.url}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Open
                            </a>
                        </Button>
                    )}
                </li>
            ))}
        </ul>
    );
}

function StepForm({
    domainId,
    action,
    data,
    label,
    destructive = false,
    disabled = false,
    children,
}: {
    domainId: number;
    action: 'review' | 'submitted' | 'decision' | 'confirm';
    data: StepData;
    label: string;
    destructive?: boolean;
    disabled?: boolean;
    children?: (form: ReturnType<typeof useForm<StepData>>) => ReactNode;
}) {
    const form = useForm<StepData>(data);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(
            NpRegistrationController.step({ domain: domainId, action }),
            {
                preserveScroll: true,
            },
        );
    };

    return (
        <form
            onSubmit={submit}
            className="grid content-start gap-3 rounded-lg border p-4"
        >
            {children?.(form)}
            <div>
                <Button
                    type="submit"
                    variant={destructive ? 'outline' : 'default'}
                    disabled={disabled || form.processing}
                >
                    {form.processing && <Spinner />}
                    {label}
                </Button>
            </div>
        </form>
    );
}

/** Staff side of a .np request: documents, workflow dates and the next registry step. */
export function NpStaffPanel({
    domainId,
    request,
    canReview,
}: {
    domainId: number;
    request: NpRequest;
    canReview: boolean;
}) {
    const organisation = request.applicant_type === 'organisation';
    const checks = Object.keys(CHECKLIST).filter(
        (key) => organisation || key !== 'letter_signed',
    );

    return (
        <SectionCard
            title={
                <span className="inline-flex flex-wrap items-center gap-2">
                    .np registration
                    {request.overdue && (
                        <Badge variant="destructive">Overdue</Badge>
                    )}
                </span>
            }
            description={`${STAGE_LABELS[request.stage]}${request.stage_changed_at ? ` since ${formatDate(request.stage_changed_at)}` : ''}. The registry has no API: record each step you take in its portal here.`}
        >
            <div className="grid gap-4">
                <Steps stage={request.stage} />
                <Reasons request={request} />
                <DescriptionList
                    items={[
                        {
                            label: 'Applicant',
                            value:
                                request.applicant_type === null
                                    ? null
                                    : organisation
                                      ? 'Organisation'
                                      : 'Individual',
                        },
                        {
                            label: 'Submitted in the portal',
                            value: (
                                <DateTime
                                    value={request.submitted_at ?? null}
                                />
                            ),
                        },
                        {
                            label: 'Registry renewal date',
                            value: (
                                <DateTime
                                    value={request.registry_expires_on ?? null}
                                />
                            ),
                        },
                        {
                            label: 'Next status check',
                            value: (
                                <DateTime
                                    value={request.review_due_on ?? null}
                                />
                            ),
                        },
                        {
                            label: 'Hostmaster email',
                            value: request.hostmaster_email,
                        },
                    ]}
                />
                <Documents request={request} />
                {canReview && request.stage === 'documents_review' && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <StepForm
                            domainId={domainId}
                            action="review"
                            label="Approve documents"
                            data={{
                                approved: true,
                                checklist: {} as Record<string, boolean>,
                            }}
                        >
                            {(form) => {
                                const checklist = form.data.checklist as Record<
                                    string,
                                    boolean
                                >;
                                const errors = form.errors as Record<
                                    string,
                                    string | undefined
                                >;

                                return (
                                    <fieldset className="grid gap-2">
                                        <legend className="mb-1 text-sm font-medium">
                                            Before submitting, confirm
                                        </legend>
                                        {checks.map((key) => (
                                            <label
                                                key={key}
                                                className="flex items-start gap-2 text-sm"
                                            >
                                                <Checkbox
                                                    checked={
                                                        checklist[key] === true
                                                    }
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        form.setData(
                                                            'checklist',
                                                            {
                                                                ...checklist,
                                                                [key]:
                                                                    checked ===
                                                                    true,
                                                            },
                                                        )
                                                    }
                                                />
                                                {CHECKLIST[key]}
                                            </label>
                                        ))}
                                        {Object.entries(errors).some(([key]) =>
                                            key.startsWith('checklist'),
                                        ) && (
                                            <p className="text-sm text-destructive">
                                                Confirm every check before
                                                approving the documents.
                                            </p>
                                        )}
                                    </fieldset>
                                );
                            }}
                        </StepForm>
                        <StepForm
                            domainId={domainId}
                            action="review"
                            label="Return to the client"
                            destructive
                            data={{ approved: false, reasons: '' }}
                        >
                            {(form) => (
                                <Field
                                    htmlFor="np-reasons"
                                    label="What the client must change"
                                    error={form.errors.reasons}
                                >
                                    <Textarea
                                        id="np-reasons"
                                        rows={4}
                                        value={text(form.data.reasons)}
                                        onChange={(event) =>
                                            form.setData(
                                                'reasons',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={!!form.errors.reasons}
                                        aria-describedby="np-reasons-description"
                                    />
                                </Field>
                            )}
                        </StepForm>
                    </div>
                )}
                {canReview && request.stage === 'ready_to_submit' && (
                    <StepForm
                        domainId={domainId}
                        action="submitted"
                        label="Record the submission"
                        data={{
                            submitted_on: new Date().toISOString().slice(0, 10),
                        }}
                    >
                        {(form) => (
                            <Field
                                htmlFor="np-submitted-on"
                                label="Submitted in the registry portal on"
                                hint="Submit the request at register.com.np under the client's own account and email, then record it here."
                                error={form.errors.submitted_on}
                            >
                                <Input
                                    id="np-submitted-on"
                                    type="date"
                                    value={text(form.data.submitted_on)}
                                    onChange={(event) =>
                                        form.setData(
                                            'submitted_on',
                                            event.target.value,
                                        )
                                    }
                                    aria-describedby="np-submitted-on-description"
                                />
                            </Field>
                        )}
                    </StepForm>
                )}
                {canReview && request.stage === 'submitted' && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <StepForm
                            domainId={domainId}
                            action="decision"
                            label="Record approval"
                            data={{ approved: true, registry_expires_on: '' }}
                        >
                            {(form) => (
                                <Field
                                    htmlFor="np-registry-expiry"
                                    label="Registry renewal date"
                                    optional
                                    hint="If the portal shows one. The domain becomes active."
                                    error={form.errors.registry_expires_on}
                                >
                                    <Input
                                        id="np-registry-expiry"
                                        type="date"
                                        value={text(
                                            form.data.registry_expires_on,
                                        )}
                                        onChange={(event) =>
                                            form.setData(
                                                'registry_expires_on',
                                                event.target.value,
                                            )
                                        }
                                        aria-describedby="np-registry-expiry-description"
                                    />
                                </Field>
                            )}
                        </StepForm>
                        <StepForm
                            domainId={domainId}
                            action="decision"
                            label="Record rejection"
                            destructive
                            data={{
                                approved: false,
                                rejection_code: '',
                                note: '',
                            }}
                        >
                            {(form) => (
                                <>
                                    <Field
                                        htmlFor="np-cause"
                                        label="Rejection cause"
                                        optional
                                        error={form.errors.rejection_code}
                                    >
                                        <SelectField
                                            id="np-cause"
                                            value={text(
                                                form.data.rejection_code,
                                            )}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'rejection_code',
                                                    value,
                                                )
                                            }
                                            placeholder="Cause number from the registry"
                                            options={Array.from(
                                                { length: 14 },
                                                (_, index) => ({
                                                    value: String(index + 1),
                                                    label: `Cause ${index + 1}`,
                                                }),
                                            )}
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="np-note"
                                        label="Reason shown to the client"
                                        hint="The registry keeps rejected requests for 3–4 business days: resubmit within 2."
                                        error={form.errors.note}
                                    >
                                        <Textarea
                                            id="np-note"
                                            rows={3}
                                            value={text(form.data.note)}
                                            onChange={(event) =>
                                                form.setData(
                                                    'note',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={!!form.errors.note}
                                            aria-describedby="np-note-description"
                                        />
                                    </Field>
                                </>
                            )}
                        </StepForm>
                    </div>
                )}
                {canReview && request.review_pending && (
                    <ConfirmDialog
                        trigger={
                            <Button
                                variant="outline"
                                className="justify-self-start"
                            >
                                Confirm registry status
                            </Button>
                        }
                        title="Confirm the registry status?"
                        description="Confirm that the domain is active in the registry portal and any reverification is answered. This clears the review flag."
                        confirmLabel="Confirm status"
                        destructive={false}
                        action={NpRegistrationController.step({
                            domain: domainId,
                            action: 'confirm',
                        })}
                    />
                )}
            </div>
        </SectionCard>
    );
}

function UploadForm({
    domainId,
    request,
}: {
    domainId: number;
    request: NpRequest;
}) {
    const form = useForm<{
        applicant_type: string;
        kind: string;
        file: File | null;
    }>({
        applicant_type: request.applicant_type ?? 'individual',
        kind: '',
        file: null,
    });
    const kinds =
        NP_REQUIRED[
            form.data.applicant_type as 'individual' | 'organisation'
        ] ?? [];
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(NpDocumentController.store(domainId), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => form.reset('file', 'kind'),
        });
    };

    return (
        <form
            onSubmit={submit}
            className="grid items-start gap-4 rounded-lg border p-4 sm:grid-cols-2"
        >
            <Field
                htmlFor="np-applicant"
                label="Registering as"
                error={form.errors.applicant_type}
            >
                <SelectField
                    id="np-applicant"
                    value={form.data.applicant_type}
                    onValueChange={(value) =>
                        form.setData({
                            ...form.data,
                            applicant_type: value,
                            kind: '',
                        })
                    }
                    options={[
                        { value: 'individual', label: 'An individual' },
                        {
                            value: 'organisation',
                            label: 'A company or organisation',
                        },
                    ]}
                />
            </Field>
            <Field htmlFor="np-kind" label="Document" error={form.errors.kind}>
                <SelectField
                    id="np-kind"
                    value={form.data.kind}
                    onValueChange={(value) => form.setData('kind', value)}
                    placeholder="Choose…"
                    options={kinds.map((kind) => ({
                        value: kind,
                        label: NP_DOCUMENTS[kind],
                    }))}
                />
            </Field>
            <Field
                htmlFor="np-file"
                label="File"
                hint="JPG, PNG or GIF, up to 800 KB, as the registry requires."
                error={form.errors.file}
                className="sm:col-span-2"
            >
                <Input
                    id="np-file"
                    type="file"
                    accept=".jpg,.jpeg,.png,.gif"
                    className="h-auto py-1.5"
                    onChange={(event) =>
                        form.setData('file', event.target.files?.[0] ?? null)
                    }
                    aria-invalid={!!form.errors.file}
                    aria-describedby="np-file-description"
                />
            </Field>
            <div>
                <Button
                    type="submit"
                    disabled={
                        form.processing || !form.data.kind || !form.data.file
                    }
                >
                    {form.processing ? <Spinner /> : <Upload />}
                    Upload document
                </Button>
            </div>
        </form>
    );
}

const CLIENT_COPY: Record<NpRequest['stage'], string> = {
    awaiting_documents:
        'The .np registry registers a name only for its owner. Upload proof that the name is yours, then send the documents to us.',
    documents_review:
        'We are checking your documents, usually within one business day.',
    ready_to_submit:
        'Your documents are approved. We are submitting the request to the registry.',
    submitted:
        'Your request is with the registry. Approval usually takes 3 to 4 business days.',
    approved: 'The registry approved your domain.',
};

/** Client side of a .np request: where it stands, what is missing, uploads and the request letter. */
export function NpClientPanel({
    domainId,
    domainName,
    nameservers,
    request,
}: {
    domainId: number;
    domainName: string;
    nameservers: string[];
    request: NpRequest;
}) {
    const collecting = request.stage === 'awaiting_documents';
    const required = NP_REQUIRED[request.applicant_type ?? 'individual'];

    return (
        <SectionCard
            title=".np registration"
            description={CLIENT_COPY[request.stage]}
        >
            <div className="grid gap-4">
                <Steps stage={request.stage} />
                <Reasons request={request} />
                {collecting && (
                    <>
                        <ul className="grid gap-1 text-sm">
                            {required.map((kind) => (
                                <li
                                    key={kind}
                                    className="flex items-center gap-2"
                                >
                                    {request.missing.includes(kind) ? (
                                        <Circle className="size-4 text-muted-foreground" />
                                    ) : (
                                        <Check className="size-4 text-primary" />
                                    )}
                                    {NP_DOCUMENTS[kind]}
                                </li>
                            ))}
                        </ul>
                        <UploadForm domainId={domainId} request={request} />
                    </>
                )}
                <Documents request={request} />
                {collecting && request.applicant_type === 'organisation' && (
                    <div className="grid gap-2">
                        <p className="text-sm font-medium">Request letter</p>
                        <p className="text-sm text-muted-foreground">
                            Print this on your letterhead, sign and stamp it,
                            then upload a photo or scan.
                        </p>
                        <pre className="rounded-lg border bg-muted p-3 text-sm whitespace-pre-wrap">
                            {`To the .np domain registry\n\nWe request the registration of ${domainName} for our organisation.\nPurpose: the website and email of our organisation.\nNameservers: ${nameservers.join(', ')}\n\nName, position, signature and stamp`}
                        </pre>
                    </div>
                )}
                {request.handling_fee_note && (
                    <p className="text-sm text-muted-foreground">
                        {request.handling_fee_note}
                    </p>
                )}
                {collecting && (
                    <div className="flex flex-wrap items-center gap-3">
                        <ConfirmDialog
                            trigger={
                                <Button disabled={request.missing.length > 0}>
                                    Send documents for review
                                </Button>
                            }
                            title="Send your documents for review?"
                            description="We check them and submit your request to the registry. You can't change them while we review."
                            confirmLabel="Send documents"
                            destructive={false}
                            action={NpDocumentController.submit(domainId)}
                        />
                        {request.missing.length > 0 && (
                            <span className="text-sm text-muted-foreground">
                                Upload every document above first.
                            </span>
                        )}
                    </div>
                )}
            </div>
        </SectionCard>
    );
}
