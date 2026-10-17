import { usePage } from '@inertiajs/react';
import { Check, Copy, KeyRound } from 'lucide-react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import type { RouteAction } from '@/types/app';
import type { EppFlash } from '@/types/domains';

type EppCodeProps = {
    action: RouteAction;
    domainName: string;
    disabled?: boolean;
    /** Wording for the client area vs. staff. */
    audience?: 'client' | 'staff';
};

/** "Get EPP code" with confirmation; shows the flashed code (or "emailed"). */
export function EppCode({
    action,
    domainName,
    disabled = false,
    audience = 'client',
}: EppCodeProps) {
    const flash = usePage().flash as { epp?: EppFlash };
    const [copied, copy] = useClipboard();
    const epp = flash.epp;

    return (
        <div className="space-y-3">
            <ConfirmDialog
                trigger={
                    <Button variant="outline" disabled={disabled}>
                        <KeyRound />
                        Get EPP code
                    </Button>
                }
                title={`Get the EPP code for ${domainName}?`}
                description={
                    audience === 'client'
                        ? 'The EPP (transfer) code lets another registrar take over this domain. Keep it private and unlock the domain before starting a transfer. This request is logged.'
                        : 'The retrieval is logged with your name. Share the code only with the verified registrant.'
                }
                confirmLabel="Get EPP code"
                destructive={false}
                action={action}
            />
            {epp?.emailed && (
                <p className="text-sm text-muted-foreground" role="status">
                    The registrar has emailed the code to the registrant
                    contact.
                </p>
            )}
            {epp?.code && (
                <div
                    className="flex items-center justify-between gap-3 rounded-lg border bg-muted/40 px-3 py-2"
                    role="status"
                >
                    <code className="font-mono text-sm break-all">
                        {epp.code}
                    </code>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Copy EPP code"
                        onClick={() => void copy(epp.code ?? '')}
                    >
                        {copied === epp.code ? <Check /> : <Copy />}
                    </Button>
                </div>
            )}
        </div>
    );
}
