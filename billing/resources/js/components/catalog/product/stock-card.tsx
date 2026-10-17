import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import ProductSettingsController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/ProductSettingsController';
import { Field } from '@/components/app/field';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

/**
 * Relative stock adjustment. Never sets an absolute number, so units reserved by
 * checkouts while the page was open are not overwritten.
 */
export function StockCard({
    productId,
    quantity,
    canManage,
}: {
    productId: number;
    quantity: number;
    canManage: boolean;
}) {
    const form = useForm({ adjustment: '', reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(ProductSettingsController.stock(productId), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <SectionCard
            title="Stock"
            description="Checkouts reserve units as they happen. Add units you received or remove units you wrote off."
            actions={
                quantity <= 0 ? (
                    <StatusBadge
                        status="out_of_stock"
                        tone="danger"
                        label="Out of stock"
                    />
                ) : (
                    <span className="text-sm text-muted-foreground">
                        <span className="text-lg font-semibold text-foreground tabular-nums">
                            {quantity}
                        </span>{' '}
                        in stock
                    </span>
                )
            }
        >
            {canManage && (
                <form
                    onSubmit={submit}
                    className="grid items-start gap-4 sm:grid-cols-[10rem_1fr_auto]"
                >
                    <Field
                        htmlFor="stock-adjustment"
                        label="Add or remove"
                        hint="e.g. 10 or -2"
                        error={form.errors.adjustment}
                    >
                        <Input
                            id="stock-adjustment"
                            inputMode="numeric"
                            value={form.data.adjustment}
                            onChange={(event) =>
                                form.setData('adjustment', event.target.value)
                            }
                            aria-invalid={!!form.errors.adjustment}
                            aria-describedby="stock-adjustment-description"
                        />
                    </Field>
                    <Field
                        htmlFor="stock-reason"
                        label="Reason"
                        optional
                        error={form.errors.reason}
                    >
                        <Input
                            id="stock-reason"
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            aria-describedby="stock-reason-description"
                        />
                    </Field>
                    <Button
                        type="submit"
                        variant="outline"
                        className="sm:mt-[1.375rem]"
                        disabled={form.processing}
                    >
                        {form.processing && <Spinner />}
                        Adjust stock
                    </Button>
                </form>
            )}
        </SectionCard>
    );
}
