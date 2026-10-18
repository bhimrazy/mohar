import { Link } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import type { ReactNode } from 'react';
import { DateTime } from '@/components/app/date';
import { Money } from '@/components/app/money';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { GatewayLogo } from './gateway-logo';
import type { PaymentInProgressData } from './types';

/**
 * A hosted payment that was started and isn't confirmed yet. It stays on the invoice until it settles, so nobody
 * pays twice by accident. Clients get "Check status" (one quick lookup); staff pass their own actions.
 */
export function PaymentInProgressCard({
    attempt,
    staff = false,
    actions,
}: {
    attempt: PaymentInProgressData;
    staff?: boolean;
    actions?: ReactNode;
}) {
    const name = attempt.gateway_name;
    const review = attempt.status === 'ambiguous';

    return (
        <SectionCard
            title="Payment in progress"
            description={
                review
                    ? `${name} hasn't confirmed this payment yet.`
                    : `Waiting for ${name} to confirm this payment.`
            }
        >
            <div className="grid gap-4">
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                    <GatewayLogo
                        name={name}
                        logo={attempt.logo}
                        className="font-medium"
                    />
                    <Money
                        amount={attempt.amount}
                        currency={attempt.currency}
                        className="font-medium"
                    />
                    {attempt.started_at && (
                        <span className="flex items-center gap-1.5 text-muted-foreground">
                            <Clock className="size-4" aria-hidden="true" />
                            Started{' '}
                            <DateTime
                                value={attempt.started_at}
                                format="datetime"
                            />
                        </span>
                    )}
                    {review && (
                        <StatusBadge
                            status="ambiguous"
                            tone="warning"
                            label="Being checked"
                        />
                    )}
                </div>
                <p className="text-sm text-muted-foreground">
                    {staff
                        ? review
                            ? `Needs review: ${attempt.review_reason ?? 'the provider could not confirm the payment yet'}. It is still checked every few minutes.`
                            : `The client started this payment. It is checked with ${name} every few minutes until it settles.`
                        : review
                          ? `Our team is checking it with ${name}. If you were charged, you don't need to pay again; contact us if this takes long.`
                          : attempt.can_pay_another_way
                            ? `The ${name} session has ended. If you didn't finish paying there, you can pay another way.`
                            : `If you completed the payment at ${name}, you don't need to do anything: this invoice updates once ${name} confirms it.`}
                </p>
                <div className="flex flex-wrap gap-2">
                    {actions ?? (
                        <Button asChild variant="outline">
                            <Link href={attempt.check_url} preserveScroll>
                                Check status
                            </Link>
                        </Button>
                    )}
                </div>
                {staff && (
                    <p className="text-xs text-muted-foreground">
                        Reference {attempt.reference}
                    </p>
                )}
            </div>
        </SectionCard>
    );
}
