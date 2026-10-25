import { useForm } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DateTime } from '@/components/app/date';
import { Money } from '@/components/app/money';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { RouteAction } from '@/types/app';
import { cycleLabel } from '@/types/services';
import type { ChangeOption, ChangeOptions } from '@/types/services';

type ChangeOptionsPickerProps = {
    /** Undefined while the deferred prop loads. */
    options: ChangeOptions | undefined;
    action: RouteAction;
    currentProduct: string | null;
    /** The service's billing cycle now, for the "Current plan" line. */
    currentCycle?: string | null;
    /** Whether a downgrade difference becomes account credit (services.upgrades.credit_on_downgrade). */
    creditOnDowngrade: boolean;
    /** Staff wording ("the client") instead of the client's ("you"). */
    staff?: boolean;
};

const keyOf = (option: ChangeOption) => `${option.product_id}:${option.cycle}`;

function submitLabel(option: ChangeOption | undefined, staff: boolean): string {
    if (!option) {
        return staff ? 'Order change' : 'Change plan';
    }

    if (option.amount_due > 0) {
        const amount = formatMoney(option.amount_due, option.currency);

        return staff
            ? `Invoice ${amount} for upgrade`
            : `Pay ${amount} and upgrade`;
    }

    return option.amount_due < 0 ? 'Downgrade now' : 'Change plan now';
}

/**
 * Upgrade/downgrade targets with the server-computed prorated price. The UI never
 * recalculates amounts; it only shows what the backend quoted. Nothing is chosen up
 * front: the rows show each plan's price and the amount due or credited appears once
 * one is picked. Changes that apply immediately (no payment) ask for confirmation and
 * say what happens to the difference.
 */
export function ChangeOptionsPicker({
    options,
    action,
    currentProduct,
    currentCycle = null,
    creditOnDowngrade,
    staff = false,
}: ChangeOptionsPickerProps) {
    const [selected, setSelected] = useState<string | null>(null);
    const [confirming, setConfirming] = useState(false);
    const form = useForm({ product_id: 0, cycle: '' });

    if (!options) {
        return (
            <div
                className="grid gap-2"
                aria-busy="true"
                aria-label="Loading plans"
            >
                <Skeleton className="h-16 w-full" />
                <Skeleton className="h-16 w-full" />
            </div>
        );
    }

    if (!options.available) {
        return (
            <p className="text-sm text-muted-foreground">{options.reason}</p>
        );
    }

    const choice = options.options.find((option) => keyOf(option) === selected);
    const submit = () =>
        form.submit(action, {
            preserveScroll: true,
            onSuccess: () => setSelected(null),
        });
    const difference = choice
        ? formatMoney(-choice.amount_due, choice.currency)
        : '';
    const who = staff ? 'The client gets' : "You'll get";

    const productChanges = options.options.filter(
        (option) => option.product_name !== currentProduct,
    );
    const cycleChanges = options.options.filter(
        (option) => option.product_name === currentProduct,
    );
    const choose = (option: ChangeOption) => {
        setSelected(keyOf(option));
        form.setData({ product_id: option.product_id, cycle: option.cycle });
    };
    const radio = (option: ChangeOption, title: ReactNode) => {
        const key = keyOf(option);
        const isSelected = key === selected;

        return (
            <label
                key={key}
                className={cn(
                    'flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm transition-colors hover:bg-muted/40 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                    isSelected &&
                        'border-foreground/40 bg-muted/40 ring-1 ring-foreground/20',
                )}
            >
                <input
                    type="radio"
                    name="change-option"
                    value={key}
                    checked={isSelected}
                    onChange={() => choose(option)}
                    className="mt-1 accent-primary"
                />
                <span className="flex min-w-0 flex-1 flex-col gap-0.5 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4">
                    <span className="font-medium">{title}</span>
                    <span className="text-muted-foreground sm:text-right">
                        <Money
                            amount={option.recurring_amount}
                            currency={option.currency}
                        />{' '}
                        {cycleLabel(option.cycle).toLowerCase()}
                    </span>
                </span>
            </label>
        );
    };

    return (
        <div className="space-y-4">
            {currentProduct && (
                <p className="text-sm text-muted-foreground">
                    Current plan:{' '}
                    <span className="font-medium text-foreground">
                        {currentProduct}
                        {currentCycle &&
                            `, ${cycleLabel(currentCycle).toLowerCase()}`}
                    </span>
                </p>
            )}
            {productChanges.length > 0 && (
                <fieldset className="grid gap-2">
                    <legend className="mb-2 text-sm font-medium">
                        {staff
                            ? 'Move to another plan'
                            : 'Switch to another plan'}
                    </legend>
                    {productChanges.map((option) =>
                        radio(
                            option,
                            <span className="inline-flex flex-wrap items-center gap-1.5">
                                {currentProduct}
                                <ArrowRight
                                    className="size-3.5 text-muted-foreground"
                                    aria-label="to"
                                />
                                {option.product_name}
                            </span>,
                        ),
                    )}
                </fieldset>
            )}
            {cycleChanges.length > 0 && (
                <fieldset className="grid gap-2">
                    <legend className="mb-2 text-sm font-medium">
                        Change how often {currentProduct ?? 'this plan'} is
                        billed
                    </legend>
                    {cycleChanges.map((option) =>
                        radio(
                            option,
                            `Billed ${cycleLabel(option.cycle).toLowerCase()}`,
                        ),
                    )}
                </fieldset>
            )}

            {choice ? (
                <div
                    className="grid gap-1 rounded-lg bg-muted/50 p-3 text-sm"
                    aria-live="polite"
                >
                    <p className="font-medium">
                        {choice.amount_due > 0 ? (
                            <>
                                {staff ? 'The client pays' : 'You pay'}{' '}
                                <Money
                                    amount={choice.amount_due}
                                    currency={choice.currency}
                                />{' '}
                                now
                            </>
                        ) : choice.amount_due < 0 ? (
                            creditOnDowngrade ? (
                                <>
                                    {who}{' '}
                                    <Money
                                        amount={-choice.amount_due}
                                        currency={choice.currency}
                                    />{' '}
                                    account credit
                                </>
                            ) : (
                                <>
                                    <Money
                                        amount={-choice.amount_due}
                                        currency={choice.currency}
                                    />{' '}
                                    for the unused days is not refunded
                                </>
                            )
                        ) : (
                            'No charge'
                        )}
                    </p>
                    <p className="text-muted-foreground">
                        <Money
                            amount={choice.charge}
                            currency={choice.currency}
                        />{' '}
                        for the new plan −{' '}
                        <Money
                            amount={choice.credit}
                            currency={choice.currency}
                        />{' '}
                        unused on the current plan
                        {choice.cycle_days > 0 &&
                            ` (${choice.remaining_days} of ${choice.cycle_days} days left)`}
                        {choice.next_due_date && (
                            <>
                                . Next due{' '}
                                <DateTime value={choice.next_due_date} />
                            </>
                        )}
                        .
                    </p>
                </div>
            ) : (
                <p
                    id="change-plan-reason"
                    className="text-sm text-muted-foreground"
                >
                    Choose a plan to see what you would pay.
                </p>
            )}

            <InputError message={form.errors.product_id ?? form.errors.cycle} />

            <div className="flex justify-end">
                <Button
                    onClick={() =>
                        choice && choice.amount_due <= 0
                            ? setConfirming(true)
                            : submit()
                    }
                    disabled={!choice || form.processing}
                    aria-describedby={choice ? undefined : 'change-plan-reason'}
                    variant={
                        choice && choice.amount_due < 0 ? 'outline' : 'default'
                    }
                >
                    {form.processing && <Spinner />}
                    {submitLabel(choice, staff)}
                </Button>
            </div>

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={
                    choice
                        ? `Change to ${choice.product_name} now?`
                        : 'Change plan now?'
                }
                description={
                    choice && choice.amount_due < 0
                        ? creditOnDowngrade
                            ? `The change applies immediately. ${who} ${difference} account credit for the unused days.`
                            : `The change applies immediately. The remaining ${difference} for the unused days is not refunded.`
                        : 'The change applies immediately at no charge.'
                }
                confirmLabel={
                    choice && choice.amount_due < 0
                        ? 'Downgrade now'
                        : 'Change plan now'
                }
                destructive={false}
                onConfirm={submit}
            />
        </div>
    );
}
