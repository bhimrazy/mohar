import { useForm } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { DateTime } from '@/components/app/date';
import { Money } from '@/components/app/money';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type { RouteAction } from '@/types/app';
import { cycleLabel } from '@/types/services';
import type { ChangeOption, ChangeOptions } from '@/types/services';

type ChangeOptionsPickerProps = {
    options: ChangeOptions;
    action: RouteAction;
    currentProduct: string | null;
    /** Copy for the submit button. */
    submitLabel?: string;
};

const keyOf = (option: ChangeOption) => `${option.product_id}:${option.cycle}`;

/**
 * Upgrade/downgrade targets with the server-computed prorated price. The UI never
 * recalculates amounts; it only shows what the backend quoted.
 */
export function ChangeOptionsPicker({
    options,
    action,
    currentProduct,
    submitLabel = 'Change plan',
}: ChangeOptionsPickerProps) {
    const [selected, setSelected] = useState<string | null>(null);
    const form = useForm({ product_id: 0, cycle: '' });

    if (!options.available) {
        return (
            <p className="text-sm text-muted-foreground">{options.reason}</p>
        );
    }

    const choice = options.options.find((option) => keyOf(option) === selected);

    const submit = () => {
        if (!choice) {
            return;
        }

        form.submit(action, { preserveScroll: true });
    };

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
                                        <>
                                            <Money
                                                amount={-option.amount_due}
                                                currency={option.currency}
                                            />{' '}
                                            difference
                                        </>
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
                <Button onClick={submit} disabled={!choice || form.processing}>
                    {form.processing && <Spinner />}
                    {submitLabel}
                </Button>
            </div>
        </div>
    );
}
