import { Form, Link } from '@inertiajs/react';
import { CheckCircle2, Circle } from 'lucide-react';
import { useState } from 'react';
import VerificationController from '@/actions/App/Modules/Kyc/Http/Controllers/Client/VerificationController';
import AccountController from '@/actions/App/Modules/Core/Http/Controllers/Client/AccountController';
import { DateTime } from '@/components/app/date';
import { Field } from '@/components/app/field';
import { Head } from '@/components/app/head';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

type DocumentRow = {
    type: string;
    label: string;
    hasBack: boolean;
    current: null | {
        number: string;
        status: 'pending' | 'approved' | 'declined';
        statusLabel: string;
        reason: string | null;
        uploadedAt: string | null;
    };
};

type Props = {
    status: { value: string; label: string; verifiedAt: string | null };
    email: { address: string; done: boolean };
    phone: { value: string | null; done: boolean; countryCode: string };
    documents: DocumentRow[];
    requiredTypes: number;
    maxUploadMb: number;
};

const STATUS_TONE: Record<
    string,
    'success' | 'warning' | 'danger' | 'info' | 'neutral'
> = {
    not_started: 'neutral',
    in_progress: 'info',
    pending_review: 'warning',
    declined: 'danger',
    verified: 'success',
    pending: 'warning',
    approved: 'success',
};

function StepTitle({
    number,
    title,
    done,
}: {
    number: number;
    title: string;
    done: boolean;
}) {
    const Icon = done ? CheckCircle2 : Circle;

    return (
        <span className="flex items-center gap-2">
            <Icon
                className={
                    done
                        ? 'size-5 text-success-text'
                        : 'size-5 text-muted-foreground'
                }
                aria-hidden="true"
            />
            <span>
                {number}. {title}
            </span>
            <StatusBadge
                status={done ? 'done' : 'to-do'}
                label={done ? 'Done' : 'To do'}
                tone={done ? 'success' : 'neutral'}
            />
        </span>
    );
}

function DocumentUpload({
    row,
    maxUploadMb,
    onDone,
}: {
    row: DocumentRow;
    maxUploadMb: number;
    onDone?: () => void;
}) {
    const id = (field: string) => `${row.type}-${field}`;
    const accept = 'image/jpeg,image/png,image/webp';

    return (
        <Form
            {...VerificationController.document.form()}
            options={{ preserveScroll: true }}
            resetOnSuccess
            onSuccess={onDone}
            className="grid gap-4 sm:grid-cols-2"
        >
            {({ errors, processing }) => (
                <>
                    <input type="hidden" name="type" value={row.type} />
                    <Field
                        htmlFor={id('number')}
                        label="Document number"
                        error={errors.number}
                        className="sm:col-span-2"
                    >
                        <Input
                            id={id('number')}
                            name="number"
                            autoComplete="off"
                            maxLength={40}
                            aria-invalid={!!errors.number}
                            aria-describedby={`${id('number')}-description`}
                        />
                    </Field>
                    <Field
                        htmlFor={id('front')}
                        label="Front"
                        hint={`JPEG, PNG or WebP, up to ${maxUploadMb} MB.`}
                        error={errors.front}
                    >
                        <Input
                            id={id('front')}
                            name="front"
                            type="file"
                            accept={accept}
                            aria-invalid={!!errors.front}
                            aria-describedby={`${id('front')}-description`}
                        />
                    </Field>
                    {row.hasBack && (
                        <Field
                            htmlFor={id('back')}
                            label="Back"
                            error={errors.back}
                        >
                            <Input
                                id={id('back')}
                                name="back"
                                type="file"
                                accept={accept}
                                aria-invalid={!!errors.back}
                                aria-describedby={`${id('back')}-description`}
                            />
                        </Field>
                    )}
                    {errors.type && (
                        <p className="text-sm text-danger-text sm:col-span-2">
                            {errors.type}
                        </p>
                    )}
                    <div className="sm:col-span-2">
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Upload {row.label}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function DocumentItem({
    row,
    maxUploadMb,
    verified,
}: {
    row: DocumentRow;
    maxUploadMb: number;
    verified: boolean;
}) {
    const current = row.current;
    const [replacing, setReplacing] = useState(false);
    const canReplace =
        current !== null && current.status !== 'approved' && !verified;

    return (
        <li className="grid gap-3 py-4 first:pt-0 last:pb-0">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0">
                    <p className="font-medium">{row.label}</p>
                    {current && (
                        <p className="text-sm text-muted-foreground">
                            Number {current.number}
                            {current.uploadedAt && (
                                <>
                                    {' '}
                                    · uploaded{' '}
                                    <DateTime value={current.uploadedAt} />
                                </>
                            )}
                        </p>
                    )}
                </div>
                <div className="flex items-center gap-2">
                    {current ? (
                        <StatusBadge
                            status={current.status}
                            label={current.statusLabel}
                            tone={STATUS_TONE[current.status]}
                        />
                    ) : (
                        <StatusBadge
                            status="none"
                            label="Not uploaded"
                            tone="neutral"
                        />
                    )}
                    {canReplace && !replacing && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setReplacing(true)}
                        >
                            Upload again
                        </Button>
                    )}
                </div>
            </div>
            {current?.status === 'declined' && current.reason && (
                <p className="rounded-md border border-danger/25 bg-danger-subtle px-3 py-2 text-sm text-danger-text">
                    Declined: {current.reason} Upload a new one when you can.
                </p>
            )}
            {current?.status === 'approved' && (
                <p className="text-sm text-muted-foreground">
                    Approved documents cannot be changed. Contact support if a
                    detail is wrong.
                </p>
            )}
            {current === null && verified && (
                <p className="text-sm text-muted-foreground">Not needed.</p>
            )}
            {((current === null && !verified) || replacing) && (
                <DocumentUpload
                    row={row}
                    maxUploadMb={maxUploadMb}
                    onDone={() => setReplacing(false)}
                />
            )}
        </li>
    );
}

export default function KycShow({
    status,
    email,
    phone,
    documents,
    requiredTypes,
    maxUploadMb,
}: Props) {
    const uploaded = documents.filter(
        (row) => row.current && row.current.status !== 'declined',
    ).length;
    const verified = status.value === 'verified';

    return (
        <>
            <Head title="Identity verification" />
            <Page size="narrow">
                <PageHeader
                    title="Identity verification"
                    description={
                        verified && status.verifiedAt ? (
                            <>
                                Verified on{' '}
                                <DateTime value={status.verifiedAt} />. Thank
                                you, there is nothing more to do.
                            </>
                        ) : (
                            'Confirm your email and phone, then upload two different identity documents for our team to check. Your account works as usual while you do this.'
                        )
                    }
                    actions={
                        <StatusBadge
                            status={status.value}
                            label={status.label}
                            tone={STATUS_TONE[status.value]}
                        />
                    }
                />

                <div className="flex flex-col gap-6">
                    <SectionCard
                        title={
                            <StepTitle
                                number={1}
                                title="Email"
                                done={email.done}
                            />
                        }
                    >
                        {email.done ? (
                            <p className="text-sm">
                                <strong>{email.address}</strong> is confirmed.
                            </p>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Your account email{' '}
                                <strong className="text-foreground">
                                    {email.address}
                                </strong>{' '}
                                is not confirmed yet. It counts once the account
                                owner signs in with this address and confirms
                                it. You can also change it to the owner&apos;s
                                sign-in email in{' '}
                                <Link
                                    href={AccountController.edit()}
                                    className="underline underline-offset-4"
                                >
                                    Account details
                                </Link>
                                .
                            </p>
                        )}
                    </SectionCard>

                    <SectionCard
                        title={
                            <StepTitle
                                number={2}
                                title="Phone number"
                                done={phone.done}
                            />
                        }
                    >
                        <Form
                            {...VerificationController.phone.form()}
                            options={{ preserveScroll: true }}
                            className="flex flex-col gap-4 sm:flex-row sm:items-end"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        htmlFor="phone"
                                        label="Mobile or landline"
                                        hint={`Numbers without a country code get ${phone.countryCode}.`}
                                        error={errors.phone}
                                        className="flex-1"
                                    >
                                        <Input
                                            id="phone"
                                            name="phone"
                                            type="tel"
                                            autoComplete="tel"
                                            key={phone.value ?? ''}
                                            defaultValue={phone.value ?? ''}
                                            placeholder={`${phone.countryCode} 98XXXXXXXX`}
                                            aria-invalid={!!errors.phone}
                                            aria-describedby="phone-description"
                                        />
                                    </Field>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="sm:mb-7"
                                    >
                                        {processing && <Spinner />}
                                        Save phone
                                    </Button>
                                </>
                            )}
                        </Form>
                    </SectionCard>

                    <SectionCard
                        title={
                            <StepTitle
                                number={3}
                                title="Identity documents"
                                done={uploaded >= requiredTypes}
                            />
                        }
                        description={
                            verified
                                ? undefined
                                : `Upload at least ${requiredTypes} different documents, with a clear photo of the front and back. ${uploaded} of ${requiredTypes} uploaded.`
                        }
                    >
                        <ul className="divide-y">
                            {documents.map((row) => (
                                <DocumentItem
                                    key={row.type}
                                    row={row}
                                    maxUploadMb={maxUploadMb}
                                    verified={verified}
                                />
                            ))}
                        </ul>
                    </SectionCard>
                </div>
            </Page>
        </>
    );
}

KycShow.layout = {
    breadcrumbs: [
        { title: 'Identity verification', href: VerificationController.show() },
    ],
};
