import { useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import ProductSettingsController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/ProductSettingsController';
import { EmptyState } from '@/components/app/empty-state';
import { SectionCard } from '@/components/app/section-card';
import InputError from '@/components/input-error';
import {
    matrixErrors,
    matrixFromRows,
    PricingMatrix,
    rowsFromMatrix,
} from '@/components/catalog/pricing-matrix';
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
    const form = useForm({ prices: rowsFromMatrix(initial) });
    const [submitted, setSubmitted] = useState(rowsFromMatrix(initial));
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
        <form onSubmit={submit}>
            <SectionCard
                title="Pricing"
                description="Turn on each cycle you sell in each currency. A cycle without a price in the client's currency is not offered to them (no automatic conversion). Price changes never affect existing services."
                flush
                footer={
                    canManage && (
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Save prices
                        </Button>
                    )
                }
            >
                <PricingMatrix
                    value={matrix}
                    onChange={setMatrix}
                    currencies={currencies}
                    cycles={cycles}
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
        </form>
    );
}
