import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ServerImportController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServerImportController';
import { DateTime } from '@/components/app/date';
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
    /** Apply to every ticked account instead of $usernames. */
    ticked?: boolean;
    /** How many accounts a bulk choice applies to. */
    count?: number;
};

/** Cycle option that returns each account to its default (monthly, or the product's first cycle). */
const DEFAULT_CYCLE = 'default';

/** Choose how accounts are imported: client, product, cycle and next due date. Saved on the import, not applied. */
export function ImportChoiceDialog({
    serverId,
    usernames,
    initial,
    onClose,
    productOptions,
    cycles,
    datesOnly = false,
    ticked = false,
    count = 0,
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
                ...(ticked ? { ticked: true } : { usernames, include: true }),
                ...(!datesOnly && client ? { client_id: client.id } : {}),
                ...(!datesOnly && productId
                    ? { product_id: Number(productId) }
                    : {}),
                // One account: an empty field returns it to the default. Ticked accounts: an empty field keeps theirs.
                ...(cycle || !ticked
                    ? { cycle: cycle && cycle !== DEFAULT_CYCLE ? cycle : null }
                    : {}),
                ...(due || !ticked ? { next_due_date: due || null } : {}),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (next) => setErrors(next),
                onSuccess: onClose,
            },
        );

    const title = ticked
        ? `Set the cycle and due date for ${count} ticked account${count === 1 ? '' : 's'}`
        : `Import ${usernames[0] ?? ''}`;

    return (
        <FormDialog
            open={initial !== null}
            onOpenChange={(open) => !open && onClose()}
            title={title}
            description={
                ticked
                    ? 'Applies to every ticked account. An account whose product has no price for the chosen cycle is left out until you change it; you are told how many.'
                    : 'Saved and ticked for the import. Nothing is created until you review and confirm it.'
            }
            submitLabel={ticked ? 'Apply to ticked accounts' : 'Save choice'}
            processing={processing}
            disabled={ticked && cycle === '' && due === ''}
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
                    hint="Default: monthly, or the product's first cycle."
                    error={errors.cycle}
                >
                    <SelectField
                        id="import-cycle"
                        value={cycle}
                        onValueChange={setCycle}
                        placeholder={
                            ticked
                                ? "Keep each account's cycle"
                                : 'Default for the account'
                        }
                        invalid={!!errors.cycle}
                        options={[
                            {
                                value: DEFAULT_CYCLE,
                                label: ticked
                                    ? 'Default for each account'
                                    : 'Default for the account',
                            },
                            ...cycles,
                        ]}
                    />
                </Field>
                <Field
                    htmlFor="import-due"
                    label="Next due date"
                    hint={
                        due ? (
                            <>
                                Renews on <DateTime value={due} />
                            </>
                        ) : ticked ? (
                            'Empty: each account keeps its date.'
                        ) : (
                            "Empty: the next anniversary of the account's start date on the server."
                        )
                    }
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
