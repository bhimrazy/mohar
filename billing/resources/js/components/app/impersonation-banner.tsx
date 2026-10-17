import { router, usePage } from '@inertiajs/react';
import { LogOut, UserRoundCheck } from 'lucide-react';
import ImpersonationController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/ImpersonationController';
import { DateTime } from '@/components/app/date';
import { toneSurface } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * Persistent banner while a staff member is signed in as a client.
 */
export function ImpersonationBanner() {
    const { impersonation } = usePage().props;

    if (!impersonation) {
        return null;
    }

    return (
        <div
            role="status"
            className={cn(
                'flex flex-wrap items-center gap-x-3 gap-y-2 rounded-none border-x-0 border-t-0 px-4 py-2 text-sm md:px-6',
                toneSurface('warning'),
            )}
            data-test="impersonation-banner"
        >
            <UserRoundCheck className="size-4 shrink-0" aria-hidden="true" />
            <span className="min-w-0 flex-1">
                Signed in as <strong>{impersonation.user}</strong> ·{' '}
                {impersonation.client} — by {impersonation.staff}, ends{' '}
                <DateTime value={impersonation.expires_at} relative />
            </span>
            <Button
                size="sm"
                variant="outline"
                onClick={() =>
                    router.visit(ImpersonationController.leave.url(), {
                        method: 'post',
                    })
                }
            >
                <LogOut />
                Return to admin
            </Button>
        </div>
    );
}
