import { usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { SectionCard } from '@/components/app/section-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import ServerController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServerController';

export type ConnectionCheckFlash = {
    ok: boolean;
    message: string;
    steps: {
        label: string;
        status: 'pass' | 'warn' | 'fail';
        detail: string | null;
    }[];
    details: Record<string, string>;
    can_trust: boolean;
    checked_at: string;
};

const statusIcon = {
    pass: {
        icon: CheckCircle2,
        className: 'text-success-text',
        label: 'Passed',
    },
    warn: {
        icon: AlertTriangle,
        className: 'text-warning-text',
        label: 'Warning',
    },
    fail: { icon: XCircle, className: 'text-danger-text', label: 'Failed' },
} as const;

/** The checklist "Test connection" flashes, with "Trust this certificate" when the server offered one. */
export function ConnectionCheck({
    serverId,
    serverName,
}: {
    serverId: number;
    serverName: string;
}) {
    const page = usePage();
    const flash = page.flash as { connectionCheck?: ConnectionCheckFlash };
    const errors = page.props.errors as Record<string, string>;
    const check = flash?.connectionCheck;

    if (!check) {
        return null;
    }

    const fingerprint = check.details.certificate_spki_sha256;
    const facts = [
        { label: 'WHM version', value: check.details.version },
        { label: 'Access', value: check.details.account_kind },
        {
            label: 'Token expires',
            value: check.details.token_expires_at && (
                <DateTime value={check.details.token_expires_at} />
            ),
        },
        { label: 'Name servers', value: check.details.nameservers },
    ].filter((item) => item.value);

    return (
        <SectionCard
            title="Connection check"
            description={
                <>
                    {check.ok
                        ? `${check.message} `
                        : 'A step failed; see below. '}
                    Checked <DateTime value={check.checked_at} relative />.
                </>
            }
        >
            <ul
                className="flex flex-col gap-3"
                aria-label="Connection checklist"
            >
                {check.steps.map((step) => {
                    const {
                        icon: Icon,
                        className,
                        label,
                    } = statusIcon[step.status];

                    return (
                        <li key={step.label} className="flex gap-3">
                            <Icon
                                className={cn(
                                    'mt-0.5 size-4 shrink-0',
                                    className,
                                )}
                                aria-label={label}
                            />
                            <span className="flex flex-col">
                                <span className="text-sm font-medium">
                                    {step.label}
                                </span>
                                {step.detail && (
                                    <span className="text-sm text-muted-foreground">
                                        {step.detail}
                                    </span>
                                )}
                            </span>
                        </li>
                    );
                })}
            </ul>

            {facts.length > 0 && (
                <DescriptionList className="mt-6" items={facts} />
            )}

            {fingerprint && (
                <div className="mt-6 flex flex-col gap-4 rounded-lg border p-4">
                    <div className="flex items-center gap-2 text-sm font-medium">
                        <ShieldCheck className="size-4 text-muted-foreground" />
                        Certificate the server presented
                    </div>
                    <DescriptionList
                        items={[
                            {
                                label: 'Subject',
                                value: check.details.certificate_subject,
                            },
                            {
                                label: 'Issuer',
                                value: check.details.certificate_issuer,
                            },
                            {
                                label: 'Expires',
                                value: check.details.certificate_expires_at && (
                                    <DateTime
                                        value={
                                            check.details.certificate_expires_at
                                        }
                                    />
                                ),
                            },
                            {
                                label: 'Public key fingerprint (SPKI SHA-256)',
                                value: (
                                    <code className="font-mono text-xs break-all">
                                        {fingerprint}
                                    </code>
                                ),
                                full: true,
                            },
                        ]}
                    />
                    <p className="text-sm text-muted-foreground">
                        Compare the fingerprint with the server's own before
                        trusting it, for example on the server:{' '}
                        <code className="font-mono text-xs break-all">
                            openssl x509 -in cert.pem -pubkey -noout | openssl
                            pkey -pubin -outform der | openssl dgst -sha256
                            -binary | base64
                        </code>
                    </p>
                    {errors.fingerprint && (
                        <Alert variant="destructive">
                            <AlertDescription>
                                {errors.fingerprint}
                            </AlertDescription>
                        </Alert>
                    )}
                    {check.can_trust ? (
                        <ConfirmDialog
                            trigger={
                                <Button
                                    variant="outline"
                                    className="self-start"
                                >
                                    Trust this certificate
                                </Button>
                            }
                            title={`Trust this certificate for ${serverName}?`}
                            description={
                                <>
                                    Calls to {serverName} will accept only the
                                    key with fingerprint{' '}
                                    <code className="font-mono text-xs break-all">
                                        {fingerprint}
                                    </code>
                                    , instead of checking the certificate
                                    authority and hostname. If the server's key
                                    changes, every call is refused until you
                                    trust the new one. The change is recorded in
                                    the activity log.
                                </>
                            }
                            confirmLabel="Trust certificate"
                            destructive={false}
                            action={ServerController.trustCertificate(serverId)}
                            data={{ fingerprint }}
                        />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            This driver cannot pin certificates; install a
                            certificate the server's hostname is valid for.
                        </p>
                    )}
                </div>
            )}
        </SectionCard>
    );
}
