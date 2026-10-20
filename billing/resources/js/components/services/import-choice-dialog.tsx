import { router } from '@inertiajs/react';
import { useState } from 'react';
import ServerImportController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServerImportController';
import { DateTime } from '@/components/app/date';
import { Field } from '@/components/app/field';
import { SelectField } from '@/components/app/select-field';
import { ClientPicker } from '@/components/billing/client-picker';
import type { ClientOption } from '@/components/billing/types';
import { FormDialog } from '@/components/services/form-dialog';
import { ImportClientFlag } from '@/components/services/import-client-flag';
import type { ImportClientFlagData } from '@/components/services/import-client-flag';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { SelectOption } from '@/types/app';

/** What an account would be imported as (its match, or the choices already made for it). */
export type ImportChoice = {
    client: { id: number; name: string; currency?: string } | null;
    product: { id: number; name: string } | null;
    cycle: string | null;
    next_due_date: string | null;
    /** Whether staff chose the client and product; otherwise they are the match, never sent unless changed. */
    chosen?: { client: boolean; product: boolean };
    /** Why the matched client is not proposed (it is imported only when staff choose it). */
    flag?: ImportClientFlagData | null;
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

/** Product option that drops a chosen product, back to the account's match. */
const MATCHED_PRODUCT = 'match';

/**
 * Choose how accounts are imported: client, product, cycle and next due date. Saved on the import, not applied. Only
 * the fields staff change are sent; a cleared client or product returns the account to its match. The state is set
 * up once per opening, so render it with a key per account (a failed save keeps what was typed).
 */
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
    const [client, setClient] = useState<ClientOption | null>(() =>
        initial?.client
            ? {
                  id: initial.client.id,
                  display_name: initial.client.name,
                  currency: initial.client.currency,
              }
            : null,
    );
    const [productId, setProductId] = useState(() =>
        initial?.product ? String(initial.product.id) : '',
    );
    const [clientChanged, setClientChanged] = useState(false);
    const [productChanged, setProductChanged] = useState(false);
    const [cycle, setCycle] = useState(initial?.cycle ?? '');
    const [due, setDue] = useState(initial?.next_due_date ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    // A flagged match stays unconfirmed until staff use it (or pick a client) themselves.
    const unconfirmed =
        !!initial?.flag &&
        !initial.chosen?.client &&
        !clientChanged &&
        client?.id === initial.client?.id;

    const submit = () =>
        router.put(
            ServerImportController.select(serverId).url,
            {
                ...(ticked ? { ticked: true } : { usernames, include: true }),
                ...(!datesOnly && clientChanged
                    ? { client_id: client?.id ?? null }
                    : {}),
                ...(!datesOnly && productChanged
                    ? {
                          product_id:
                              productId && productId !== MATCHED_PRODUCT
                                  ? Number(productId)
                                  : null,
                      }
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
                        hint="Search by name, company or email. Empty: the account's match."
                        error={errors.client_id}
                    >
                        <ClientPicker
                            placeholder="Search clients…"
                            id="import-client"
                            value={client}
                            onChange={(next) => {
                                setClient(next);
                                setClientChanged(true);
                            }}
                            invalid={!!errors.client_id}
                            searchUrl={(q) =>
                                ServerImportController.clients.url(serverId, {
                                    query: { q },
                                })
                            }
                        />
                        {unconfirmed && initial?.flag && (
                            <span className="flex flex-wrap items-center gap-2">
                                <ImportClientFlag flag={initial.flag}>
                                    : not imported unless you use this client.
                                </ImportClientFlag>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setClientChanged(true)}
                                >
                                    Use this client
                                </Button>
                            </span>
                        )}
                    </Field>
                    <Field
                        htmlFor="import-product"
                        label="Product"
                        error={errors.product_id}
                    >
                        <SelectField
                            id="import-product"
                            value={productId}
                            onValueChange={(next) => {
                                setProductId(next);
                                setProductChanged(true);
                            }}
                            placeholder="Choose a product"
                            invalid={!!errors.product_id}
                            options={[
                                ...(initial?.chosen?.product
                                    ? [
                                          {
                                              value: MATCHED_PRODUCT,
                                              label: 'As matched',
                                          },
                                      ]
                                    : []),
                                ...productOptions.map((product) => ({
                                    value: String(product.id),
                                    label: product.name,
                                })),
                            ]}
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
