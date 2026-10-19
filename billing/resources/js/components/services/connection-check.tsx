import { usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    CheckCircle2,
    Copy,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { SectionCard } from '@/components/app/section-card';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
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

const fingerprintCommand =
    'openssl x509 -in cert.pem -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64';

/** A value staff compare or paste elsewhere: monospace, never wrapped mid-word, with a copy button. */
export function CopyableCode({
    value,
    label,
}: {
    value: string;
    label: string;
}) {
    const [copied, copy] = useClipboard();

    return (
        <div className="flex items-start gap-2 rounded-md border bg-muted/50 p-2">
            <code className="min-w-0 flex-1 overflow-x-auto font-mono text-xs whitespace-pre">
                {value}
            </code>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-7 shrink-0"
                aria-label={`Copy ${label}`}
                onClick={() => copy(value)}
            >
                {copied === value ? <Check /> : <Copy />}
            </Button>
        </div>
    );
}

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
    const check = flash?.connectionCheck;

    if (!check) {
        return null;
    }

    const fingerprint = check.details.certificate_spki_sha256;
    // Name servers already appear in their own checklist step.
    const facts = [
        { label: 'Version', value: check.details.version },
        { label: 'Access', value: check.details.account_kind },
        {
            label: 'Token expires',
            value: check.details.token_expires_at && (
                <DateTime value={check.details.token_expires_at} />
            ),
        },
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
                        ]}
                    />
                    <div className="flex flex-col gap-1.5">
                        <span className="text-sm font-medium">
                            Public key fingerprint (SPKI SHA-256)
                        </span>
                        <CopyableCode value={fingerprint} label="fingerprint" />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <span className="text-sm text-muted-foreground">
                            Compare it with the fingerprint on the server
                            itself. On the server, run this against its
                            certificate file:
                        </span>
                        <CopyableCode
                            value={fingerprintCommand}
                            label="command"
                        />
                    </div>
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
                                <span className="flex flex-col gap-3">
                                    <span>
                                        Only trust this if it matches the
                                        fingerprint on your server. A wrong key
                                        lets someone intercept this server's
                                        credentials.
                                    </span>
                                    <code className="block overflow-x-auto rounded-md border bg-muted/50 p-2 font-mono text-xs whitespace-pre">
                                        {fingerprint}
                                    </code>
                                    <span>
                                        From then on, calls accept only this key
                                        instead of checking the certificate
                                        authority and hostname, and are refused
                                        if the key changes. To undo it, use
                                        Remove next to the trusted certificate
                                        in the driver settings below. The change
                                        is recorded in the activity log.
                                    </span>
                                </span>
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
