import { StatusBadge } from '@/components/app/status-badge';
import type { StatusTone } from '@/components/app/status-badge';

const PRIORITY_TONES: Record<string, StatusTone> = {
    low: 'neutral',
    medium: 'info',
    high: 'warning',
    urgent: 'danger',
    critical: 'danger',
};

/** Ticket/incident priority, using the shared status palette. */
export function PriorityBadge({ priority }: { priority: string }) {
    return (
        <StatusBadge
            status={priority}
            tone={PRIORITY_TONES[priority] ?? 'neutral'}
        />
    );
}
