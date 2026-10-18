import { Head, router } from '@inertiajs/react';
import { CreditCard, Plus } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { StatusBadge } from '@/components/app/status-badge';
import {
    CardFields,
    tokenizeCard,
    useCardForm,
} from '@/components/billing/card-form';
import type { PayMethod } from '@/components/billing/types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import {
    destroy,
    makeDefault,
    store,
} from '@/actions/App/Modules/Billing/Http/Controllers/Client/PayMethodController';

type Props = {
    pay_methods: PayMethod[];
    gateways: { key: string; name: string; vault_url: string }[];
    can_remove: boolean;
};

function AddCardDialog({
    gateway,
}: {
    gateway: { key: string; name: string; vault_url: string };
}) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [serverError, setServerError] = useState<string | undefined>();
    const card = useCardForm();

    const submit = async (event: React.FormEvent) => {
        event.preventDefault();
        setProcessing(true);
        const result = await tokenizeCard(gateway.vault_url, card.card);
        card.setErrors(result.errors ?? {});

        if (!result.token) {
            setProcessing(false);

            return;
        }

        router.post(
            store.url(),
            {
                gateway: gateway.key,
                token: result.token.token,
                make_default: false,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    card.reset();
                    setOpen(false);
                },
                onError: (errors) => setServerError(Object.values(errors)[0]),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !processing && setOpen(next)}
        >
            <DialogTrigger asChild>
                <Button>
                    <Plus />
                    Add card
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Add a card</DialogTitle>
                        <DialogDescription>
                            Card details go straight to {gateway.name}; we only
                            keep a secure token.
                        </DialogDescription>
                    </DialogHeader>
                    <CardFields
                        value={card.card}
                        onChange={card.setCard}
                        errors={card.errors}
                        idPrefix="new-card"
                    />
                    <InputError message={serverError} />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Save card
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function PayMethods({
    pay_methods,
    gateways,
    can_remove,
}: Props) {
    const columns: DataTableColumn<PayMethod>[] = [
        {
            key: 'card',
            header: 'Card',
            primary: true,
            cell: (method) => (
                <span className="inline-flex items-center gap-2">
                    <CreditCard
                        className="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    {method.label}
                </span>
            ),
        },
        {
            key: 'expiry',
            header: 'Expires',
            cell: (method) => method.expiry ?? '—',
        },
        {
            key: 'status',
            header: 'Status',
            cell: (method) =>
                method.disabled ? (
                    <StatusBadge status="failed" label="Disabled" />
                ) : method.expired ? (
                    <StatusBadge status="expired" />
                ) : method.is_default ? (
                    <StatusBadge status="active" label="Default" />
                ) : (
                    <StatusBadge status="inactive" label="Saved" />
                ),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            align: 'right',
            cell: (method) => (
                <div className="flex justify-end gap-1">
                    {!method.is_default && !method.disabled && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    makeDefault.url(method.id),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Make default
                        </Button>
                    )}
                    {can_remove && (
                        <ConfirmDialog
                            trigger={
                                <Button variant="ghost" size="sm">
                                    Remove
                                </Button>
                            }
                            title={`Remove ${method.label}?`}
                            description="Automatic payments stop using this card. You can add it again any time."
                            confirmLabel="Remove card"
                            action={destroy(method.id)}
                        />
                    )}
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title="Payment methods" />
            <Page>
                <PageHeader
                    title="Payment methods"
                    description="Your default card is charged automatically shortly before invoices are due."
                    actions={
                        gateways[0] && <AddCardDialog gateway={gateways[0]} />
                    }
                />
                <DataTable
                    caption="Saved payment methods"
                    columns={columns}
                    rows={pay_methods}
                    getRowKey={(method) => method.id}
                    empty={
                        <EmptyState
                            icon={CreditCard}
                            title="You don’t have any saved cards."
                            description="Save a card to pay invoices automatically."
                        />
                    }
                />
            </Page>
        </>
    );
}
