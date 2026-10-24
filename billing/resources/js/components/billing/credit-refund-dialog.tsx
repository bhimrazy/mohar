import { Field } from '@/components/app/field';
import { FormDialog } from '@/components/billing/form-dialog';
import { SelectField } from '@/components/billing/select-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { refund } from '@/actions/App/Modules/Billing/Http/Controllers/Admin/CreditController';
import { formatMoney } from '@/lib/money';

export type RefundablePayment = {
    id: number;
    label: string;
    gateway: string;
    refundable: number;
    currency: string;
};

type Props = {
    client: { id: number; currency: string; balance: number };
    methods: { value: string; label: string }[];
    payments: RefundablePayment[];
    canGateway: boolean;
};

/** Pay account credit out: recorded as made outside, or through the gateway of an earlier payment. */
export function CreditRefundDialog({
    client,
    methods,
    payments,
    canGateway,
}: Props) {
    return (
        <FormDialog
            trigger={<Button variant="outline">Refund credit</Button>}
            title="Refund account credit"
            description={`Pays the credit back to the client. It is not a sale: no credit note is issued and no invoice changes.${canGateway && payments.length === 0 ? ' No payment of this client can be refunded through its gateway.' : ''}`}
            submitLabel="Refund credit"
            action={refund()}
            moneyFields={['amount']}
            initial={{
                client_id: client.id,
                mode: 'recorded',
                amount: '',
                method: methods[0]?.value ?? '',
                reference: '',
                payment_id: '',
                description: '',
            }}
            transform={(data) => ({
                ...data,
                payment_id:
                    data.mode === 'gateway' ? Number(data.payment_id) : '',
            })}
        >
            {(form) => (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        className="content-start"
                        htmlFor="credit-refund-mode"
                        label="How"
                        error={form.errors.mode}
                    >
                        <SelectField
                            id="credit-refund-mode"
                            value={form.data.mode}
                            onChange={(value) => form.setData('mode', value)}
                            options={[
                                {
                                    value: 'recorded',
                                    label: 'Paid outside, record it',
                                },
                                ...(canGateway && payments.length > 0
                                    ? [
                                          {
                                              value: 'gateway',
                                              label: 'Through the payment gateway',
                                          },
                                      ]
                                    : []),
                            ]}
                        />
                    </Field>
                    <Field
                        className="content-start"
                        htmlFor="credit-refund-amount"
                        label={`Amount (balance ${formatMoney(client.balance, client.currency)})`}
                        error={form.errors.amount}
                    >
                        <Input
                            id="credit-refund-amount"
                            inputMode="decimal"
                            value={form.data.amount}
                            onChange={(event) =>
                                form.setData('amount', event.target.value)
                            }
                        />
                    </Field>
                    {form.data.mode === 'recorded' ? (
                        <>
                            <Field
                                className="content-start"
                                htmlFor="credit-refund-method"
                                label="Method"
                                error={form.errors.method}
                            >
                                <SelectField
                                    id="credit-refund-method"
                                    value={form.data.method}
                                    onChange={(value) =>
                                        form.setData('method', value)
                                    }
                                    options={methods}
                                />
                            </Field>
                            <Field
                                className="content-start"
                                htmlFor="credit-refund-reference"
                                label="Reference"
                                error={form.errors.reference}
                            >
                                <Input
                                    id="credit-refund-reference"
                                    value={form.data.reference}
                                    onChange={(event) =>
                                        form.setData(
                                            'reference',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </>
                    ) : (
                        <Field
                            className="content-start sm:col-span-2"
                            htmlFor="credit-refund-payment"
                            label="Refund against the payment"
                            error={form.errors.payment_id}
                        >
                            <SelectField
                                id="credit-refund-payment"
                                value={String(form.data.payment_id)}
                                onChange={(value) =>
                                    form.setData('payment_id', value)
                                }
                                placeholder="Choose a payment"
                                options={payments.map((payment) => ({
                                    value: String(payment.id),
                                    label: `${payment.label} (up to ${formatMoney(payment.refundable, payment.currency)})`,
                                }))}
                            />
                        </Field>
                    )}
                    <Field
                        className="content-start sm:col-span-2"
                        htmlFor="credit-refund-note"
                        label="Note (optional)"
                        error={form.errors.description}
                    >
                        <Input
                            id="credit-refund-note"
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </Field>
                </div>
            )}
        </FormDialog>
    );
}
