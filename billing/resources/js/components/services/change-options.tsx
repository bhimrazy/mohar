import { useForm } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';
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
 * recalculates amounts; it only shows what the backend quoted. Changes that apply
 * immediately (no payment) ask for confirmation and say what happens to the difference.
 */
export function ChangeOptionsPicker({
    options,
    action,
    currentProduct,
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

    return (
        <div className="space-y-4">
            <fieldset className="grid gap-2">
                <legend className="sr-only">Choose a new plan</legend>
                {options.options.map((option) => {
                    const key = keyOf(option);
                    const isSelected = key === selected;

                    return (
                        <label
                            key={key}
                            className={cn(
                                'flex cursor-pointer flex-col gap-2 rounded-lg border p-3 text-sm transition-colors hover:bg-muted/40 sm:flex-row sm:items-center sm:justify-between',
                                isSelected &&
                                    'border-foreground/40 bg-muted/40 ring-1 ring-foreground/20',
                            )}
                        >
                            <span className="flex items-start gap-3">
                                <input
                                    type="radio"
                                    name="change-option"
                                    value={key}
                                    checked={isSelected}
                                    onChange={() => {
                                        setSelected(key);
                                        form.setData({
                                            product_id: option.product_id,
                                            cycle: option.cycle,
                                        });
                                    }}
                                    className="mt-1 accent-primary"
                                />
                                <span className="space-y-0.5">
                                    <span className="flex flex-wrap items-center gap-1.5 font-medium">
                                        {currentProduct}
                                        <ArrowRight
                                            className="size-3.5 text-muted-foreground"
                                            aria-label="to"
                                        />
                                        {option.product_name}
                                    </span>
                                    <span className="block text-muted-foreground">
                                        <Money
                                            amount={option.recurring_amount}
                                            currency={option.currency}
                                        />{' '}
                                        {cycleLabel(option.cycle).toLowerCase()}
                                        {option.next_due_date && (
                                            <>
                                                {' '}
                                                · next due{' '}
                                                <DateTime
                                                    value={option.next_due_date}
                                                />
                                            </>
                                        )}
                                    </span>
                                </span>
                            </span>
                            <span className="flex flex-col items-start pl-7 sm:items-end sm:pl-0">
                                <span className="font-medium">
                                    {option.amount_due > 0 ? (
                                        <>
                                            Pay{' '}
                                            <Money
                                                amount={option.amount_due}
                                                currency={option.currency}
                                            />{' '}
                                            now
                                        </>
                                    ) : option.amount_due < 0 ? (
                                        creditOnDowngrade ? (
                                            <>
                                                <Money
                                                    amount={-option.amount_due}
                                                    currency={option.currency}
                                                />{' '}
                                                credit
                                            </>
                                        ) : (
                                            <>
                                                <Money
                                                    amount={-option.amount_due}
                                                    currency={option.currency}
                                                />{' '}
                                                not refunded
                                            </>
                                        )
                                    ) : (
                                        'No charge'
                                    )}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    <Money
                                        amount={option.charge}
                                        currency={option.currency}
                                    />{' '}
                                    charge −{' '}
                                    <Money
                                        amount={option.credit}
                                        currency={option.currency}
                                    />{' '}
                                    credit
                                    {option.cycle_days > 0 &&
                                        ` · ${option.remaining_days} of ${option.cycle_days} days left`}
                                </span>
                            </span>
                        </label>
                    );
                })}
            </fieldset>

            <InputError message={form.errors.product_id ?? form.errors.cycle} />

            <div className="flex justify-end">
                <Button
                    onClick={() =>
                        choice && choice.amount_due <= 0
                            ? setConfirming(true)
                            : submit()
                    }
                    disabled={!choice || form.processing}
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
