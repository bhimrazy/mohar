import { useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import ProductSettingsController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/ProductSettingsController';
import { EmptyState } from '@/components/app/empty-state';
import { FormActions } from '@/components/app/form-actions';
import { SectionCard } from '@/components/app/section-card';
import InputError from '@/components/input-error';
import {
    hasSetupFees,
    matrixErrors,
    matrixFromRows,
    PricingMatrix,
    rowsFromMatrix,
} from '@/components/catalog/pricing-matrix';
import type { SubmittedPriceRow } from '@/components/catalog/pricing-matrix';
import { SetupFeeToggle } from '@/components/catalog/setup-fee-toggle';
import type {
    CurrencyOption,
    CycleOption,
    PriceRow,
} from '@/components/catalog/types';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type PricingTabProps = {
    productId: number;
    paymentType: string;
    currencies: CurrencyOption[];
    cycles: CycleOption[];
    rows: PriceRow[];
    canManage: boolean;
};

export function PricingTab({
    productId,
    paymentType,
    currencies,
    cycles,
    rows,
    canManage,
}: PricingTabProps) {
    const initial = useMemo(
        () => matrixFromRows(rows, currencies, cycles),
        [rows, currencies, cycles],
    );
    const [matrix, setMatrix] = useState(initial);
    const [showSetup, setShowSetup] = useState(() => hasSetupFees(rows));
    const [submitted, setSubmitted] = useState<SubmittedPriceRow[]>([]);
    const form = useForm<{ prices: SubmittedPriceRow[] }>({ prices: [] });
    const errors = form.errors as Record<string, string | undefined>;

    if (paymentType === 'free') {
        return (
            <SectionCard title="Pricing">
                <EmptyState
                    title="Free products have no prices"
                    description="Change the payment type on the Details tab to sell this product."
                />
            </SectionCard>
        );
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const prices = rowsFromMatrix(matrix);
        setSubmitted(prices);
        form.transform(() => ({ prices }));
        form.submit(ProductSettingsController.pricing(productId), {
            preserveScroll: true,
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <SectionCard
                title="Pricing"
                description={`Turn on each cycle you sell in each currency; clients see only cycles priced in their currency. Prices in other currencies can be converted from the ${currencies.find((currency) => currency.is_default)?.code ?? 'default-currency'} price with each currency's rounding rule (Currencies → Converted prices). Lock a price to keep the amount you set. Price changes never affect existing services.`}
                actions={
                    <SetupFeeToggle
                        id="product-setup-fees"
                        checked={showSetup}
                        onCheckedChange={setShowSetup}
                    />
                }
                flush
            >
                <PricingMatrix
                    value={matrix}
                    onChange={setMatrix}
                    currencies={currencies}
                    cycles={cycles}
                    showSetupFee={showSetup}
                    disabled={!canManage}
                    cellErrors={matrixErrors(errors, submitted)}
                    idPrefix="product-price"
                />
                {errors.prices && (
                    <div className="border-t px-4 py-3 sm:px-6">
                        <InputError message={errors.prices} />
                    </div>
                )}
            </SectionCard>
            {canManage && (
                <FormActions sticky>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Save prices
                    </Button>
                </FormActions>
            )}
        </form>
    );
}
