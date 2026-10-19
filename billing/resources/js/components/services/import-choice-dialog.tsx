import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ServerImportController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServerImportController';
import { Field } from '@/components/app/field';
import { SelectField } from '@/components/app/select-field';
import { ClientPicker } from '@/components/billing/client-picker';
import type { ClientOption } from '@/components/billing/types';
import { FormDialog } from '@/components/services/form-dialog';
import { Input } from '@/components/ui/input';
import type { SelectOption } from '@/types/app';

/** What an account would be imported as (AccountImportPlan), or the choices already made for it. */
export type ImportChoice = {
    client: { id: number; name: string; currency?: string } | null;
    product: { id: number; name: string } | null;
    cycle: string | null;
    next_due_date: string | null;
};

type Props = {
    serverId: number;
    /** Accounts the choice applies to. */
    usernames: string[];
    /** Null: the dialog is closed. */
    initial: ImportChoice | null;
    onClose: () => void;
    productOptions: { id: number; name: string }[];
    cycles: SelectOption[];
    /** Bulk mode: only the cycle and next due date. */
    datesOnly?: boolean;
};

/** Choose how accounts are imported: client, product, cycle and next due date. Saved on the import, not applied. */
export function ImportChoiceDialog({
    serverId,
    usernames,
    initial,
    onClose,
    productOptions,
    cycles,
    datesOnly = false,
}: Props) {
    const [client, setClient] = useState<ClientOption | null>(null);
    const [productId, setProductId] = useState('');
    const [cycle, setCycle] = useState('');
    const [due, setDue] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        setClient(
            initial?.client
                ? {
                      id: initial.client.id,
                      display_name: initial.client.name,
                      currency: initial.client.currency,
                  }
                : null,
        );
        setProductId(initial?.product ? String(initial.product.id) : '');
        setCycle(initial?.cycle ?? '');
        setDue(initial?.next_due_date ?? '');
        setErrors({});
    }, [initial]);

    const submit = () =>
        router.put(
            ServerImportController.select(serverId).url,
            {
                usernames,
                include: true,
                ...(!datesOnly && client ? { client_id: client.id } : {}),
                ...(!datesOnly && productId
                    ? { product_id: Number(productId) }
                    : {}),
                ...(cycle ? { cycle } : {}),
                ...(due ? { next_due_date: due } : {}),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (next) => setErrors(next),
                onSuccess: onClose,
            },
        );

    const title =
        usernames.length === 1
            ? `Import ${usernames[0]}`
            : `Set the cycle and due date for ${usernames.length} accounts`;

    return (
        <FormDialog
            open={initial !== null}
            onOpenChange={(open) => !open && onClose()}
            title={title}
            description="Saved and ticked for the import. Nothing is created until you review and confirm it."
            submitLabel="Save choice"
            processing={processing}
            onSubmit={submit}
        >
            {!datesOnly && (
                <>
                    <Field
                        htmlFor="import-client"
                        label="Client"
                        error={errors.client_id}
                    >
                        <ClientPicker
                            id="import-client"
                            value={client}
                            onChange={setClient}
                            invalid={!!errors.client_id}
                            searchUrl={(q) =>
                                ServerImportController.clients.url(serverId, {
                                    query: { q },
                                })
                            }
                        />
                    </Field>
                    <Field
                        htmlFor="import-product"
                        label="Product"
                        error={errors.product_id}
                    >
                        <SelectField
                            id="import-product"
                            value={productId}
                            onValueChange={setProductId}
                            placeholder="Choose a product"
                            invalid={!!errors.product_id}
                            options={productOptions.map((product) => ({
                                value: String(product.id),
                                label: product.name,
                            }))}
                        />
                    </Field>
                </>
            )}
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    htmlFor="import-cycle"
                    label="Billing cycle"
                    error={errors.cycle}
                >
                    <SelectField
                        id="import-cycle"
                        value={cycle}
                        onValueChange={setCycle}
                        placeholder="Choose a cycle"
                        invalid={!!errors.cycle}
                        options={cycles}
                    />
                </Field>
                <Field
                    htmlFor="import-due"
                    label="Next due date"
                    error={errors.next_due_date ?? errors.usernames}
                >
                    <Input
                        id="import-due"
                        type="date"
                        value={due}
                        onChange={(event) => setDue(event.target.value)}
                        aria-invalid={!!errors.next_due_date}
                    />
                </Field>
            </div>
        </FormDialog>
    );
}
