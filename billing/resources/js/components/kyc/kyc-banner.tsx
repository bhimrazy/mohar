import { Link, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { toneSurface } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

const MESSAGES: Record<
    string,
    { text: string; action: string; tone: 'info' | 'warning' | 'danger' }
> = {
    not_started: {
        text: 'Verify your identity to finish setting up your account.',
        action: 'Start verification',
        tone: 'info',
    },
    in_progress: {
        text: 'Finish verifying your identity to complete your account.',
        action: 'Continue verification',
        tone: 'info',
    },
    pending_review: {
        text: 'Your identity documents are pending review. We will update this page once our team has checked them.',
        action: 'View status',
        tone: 'warning',
    },
    rejected: {
        text: 'A document needs attention: our team could not accept it.',
        action: 'See why',
        tone: 'danger',
    },
};

/**
 * Client-area banner while identity verification is not complete. It never blocks anything.
 */
export function KycBanner() {
    const { kyc } = usePage().props;
    const message = kyc ? MESSAGES[kyc.status] : undefined;

    if (!kyc || !message) {
        return null;
    }

    return (
        <div
            role="status"
            className={cn(
                'flex flex-wrap items-center gap-x-3 gap-y-2 rounded-none border-x-0 border-t-0 px-4 py-2 text-sm md:px-6',
                toneSurface(message.tone),
            )}
            data-test="kyc-banner"
        >
            <ShieldCheck className="size-4 shrink-0" aria-hidden="true" />
            <span className="min-w-0 flex-1">{message.text}</span>
            <Button size="sm" variant="outline" asChild>
                <Link href={kyc.href}>{message.action}</Link>
            </Button>
        </div>
    );
}
