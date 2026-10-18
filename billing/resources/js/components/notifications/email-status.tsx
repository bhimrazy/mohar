import { StatusBadge } from '@/components/app/status-badge';
import type { StatusTone } from '@/components/app/status-badge';

const TONES: Record<string, StatusTone> = {
    queued: 'info',
    sent: 'success',
    failed: 'danger',
    suppressed: 'neutral',
    pending: 'info',
    succeeded: 'success',
    abandoned: 'danger',
};

export function DeliveryStatus({ status }: { status: string }) {
    return <StatusBadge status={status} tone={TONES[status] ?? 'neutral'} />;
}
