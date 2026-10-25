import { router } from '@inertiajs/react';
import { Download, FilePlus2, FileMinus2, Undo2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Field } from '@/components/app/field';
import { Money } from '@/components/app/money';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import { SelectField } from '@/components/billing/select-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { normalizeMoney } from '@/components/billing/types';
import { formatMoney, labelWithCurrency } from '@/lib/money';
import {
    pdf as notePdf,
    preview as previewNote,
    store as storeNote,
} from '@/actions/App/Modules/Billing/Http/Controllers/Admin/TaxNoteController';

export type TaxNote = {
    id: number;
    number: string;
    kind: 'credit' | 'debit';
    issue_date: string;
    bs_issue_date: string;
    reason_code: string;
    reason_text: string;
    total: number;
    tax: number;
    to_credit: number;
    automatic: boolean;
    issued_by: string | null;
    reverses: string | null;
    reversed_by: string | null;
};

export type Creditable = {
    total: number;
    lines: { id: number; description: string; left: number }[];
};

type Preview = {
    total: number;
    taxable: number;
    exempt: number;
    tax1: number;
    tax2: number;
    balance_after: number;
    settlement: 'balance' | 'credit';
    to_credit: number;
    /** New due date when the note leaves a paid invoice owing again. */
    due_date: string | null;
    /** The client's account credit after a reversal; may be negative. */
    credit_after: number | null;
    version: number;
    digest: string;
    lines: {
        description: string;
        net: number;
        tax1: number;
        tax2: number;
        gross: number;
    }[];
};

type Mode =
    | { kind: 'credit' }
    | { kind: 'debit' }
    | { kind: 'reversal'; note: TaxNote };

const REASONS: Record<'credit' | 'debit', Record<string, string>> = {
    credit: {
        service_not_delivered: 'Service not delivered',
        price_correction: 'Price correction',
        discount_after_sale: 'Discount after sale',
        duplicate_invoice: 'Duplicate invoice',
        lines_cancelled: 'Lines cancelled',
        invoice_cancelled: 'Invoice cancelled',
        goodwill: 'Goodwill',
        other: 'Other',
    },
    debit: {
        price_correction: 'Price correction',
        late_fee: 'Late payment fee',
        other: 'Other',
    },
};

const ALL_REASONS: Record<string, string> = {
    ...REASONS.credit,
    ...REASONS.debit,
    refunded: 'Refunded',
    refunded_at_provider: 'Refunded at the gateway',
};

type PanelProps = {
    invoiceId: number;
    currency: string;
    /** Null when the staff member may not see notes: the panel is hidden. */
    notes: TaxNote[] | null;
    creditable: Creditable | null;
    enabled: boolean;
    canIssue: boolean;
    /** Why a credit or debit note can't be issued now (server-side), or null when it can. */
    blocked: { credit: string | null; debit: string | null } | null;
};

/**
 * The invoice's credit and debit notes, what can still be credited, and (with billing.tax_notes.issue, in Nepal VAT mode)
 * the issue and reverse actions. A note is previewed by the server and confirmed against that preview.
 */
export function TaxNotesPanel({
    invoiceId,
    currency,
    notes,
    creditable,
    enabled,
    canIssue,
    blocked,
}: PanelProps) {
    const [mode, setMode] = useState<Mode | null>(null);
    const actions = enabled && canIssue;
    const creditBlocked = blocked?.credit ?? null;
    const debitBlocked = blocked?.debit ?? null;

    if (notes === null || (!enabled && notes.length === 0)) {
        return null;
    }

    return (
        <SectionCard
            title="Credit and debit notes"
            description={
                creditable && (
                    <>
                        Still creditable:{' '}
                        <Money amount={creditable.total} currency={currency} />
                    </>
                )
            }
        >
            {actions && (
                <div className="mb-4 flex flex-wrap items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={creditBlocked !== null}
                        onClick={() => setMode({ kind: 'credit' })}
                    >
                        <FileMinus2 />
                        Issue credit note
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={debitBlocked !== null}
                        onClick={() => setMode({ kind: 'debit' })}
                    >
                        <FilePlus2 />
                        Issue debit note
                    </Button>
                    {(creditBlocked || debitBlocked) && (
                        <p className="basis-full text-xs text-muted-foreground">
                            {[creditBlocked, debitBlocked]
                                .filter(Boolean)
                                .join(' ')}
                        </p>
                    )}
                </div>
            )}
            {notes.length === 0 ? (
                <EmptyState
                    title="No notes yet"
                    description="A credit note lowers the value of this invoice, a debit note raises it. Notes are numbered when issued and never edited."
                />
            ) : (
                <ul className="divide-y">
                    {notes.map((note) => (
                        <li
                            key={note.id}
                            className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div className="grid gap-1 text-sm">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {note.number}
                                    </span>
                                    <StatusBadge
                                        status={note.kind}
                                        tone={
                                            note.kind === 'credit'
                                                ? 'info'
                                                : 'neutral'
                                        }
                                        label={
                                            note.kind === 'credit'
                                                ? 'Credit note'
                                                : 'Debit note'
                                        }
                                    />
                                    {note.reversed_by && (
                                        <StatusBadge
                                            status="reversed"
                                            tone="neutral"
                                            label={`Reversed by ${note.reversed_by}`}
                                        />
                                    )}
                                </div>
                                <p className="text-muted-foreground">
                                    <DateTime value={note.issue_date} /> (
                                    {note.bs_issue_date}) ·{' '}
                                    {note.reverses
                                        ? `Reverses ${note.reverses}`
                                        : (ALL_REASONS[note.reason_code] ??
                                          note.reason_code)}{' '}
                                    · {note.issued_by ?? 'Automatic'}
                                </p>
                                <p>{note.reason_text}</p>
                                {note.to_credit > 0 && (
                                    <p className="text-muted-foreground">
                                        <Money
                                            amount={note.to_credit}
                                            currency={currency}
                                        />{' '}
                                        {note.reverses
                                            ? 'taken back from account credit'
                                            : 'to account credit'}
                                    </p>
                                )}
                            </div>
                            <div className="flex items-center gap-3 sm:flex-col sm:items-end">
                                <Money
                                    amount={
                                        note.kind === 'credit'
                                            ? -note.total
                                            : note.total
                                    }
                                    currency={currency}
                                    signed
                                    className="font-medium"
                                />
                                <Button variant="ghost" size="sm" asChild>
                                    <a href={notePdf.url(note.id)}>
                                        <Download />
                                        PDF
                                    </a>
                                </Button>
                                {actions &&
                                    note.kind === 'credit' &&
                                    !note.reversed_by &&
                                    !debitBlocked && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setMode({
                                                    kind: 'reversal',
                                                    note,
                                                })
                                            }
                                        >
                                            <Undo2 />
                                            Reverse
                                        </Button>
                                    )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {mode && (
                <NoteDialog
                    key={mode.kind === 'reversal' ? mode.note.id : mode.kind}
                    invoiceId={invoiceId}
                    currency={currency}
                    mode={mode}
                    creditable={creditable}
                    onClose={() => setMode(null)}
                />
            )}
        </SectionCard>
    );
}

function NoteDialog({
    invoiceId,
    currency,
    mode,
    creditable,
    onClose,
}: {
    invoiceId: number;
    currency: string;
    mode: Mode;
    creditable: Creditable | null;
    onClose: () => void;
}) {
    const reversal = mode.kind === 'reversal';
    const kind = reversal ? 'debit' : mode.kind;
    const [allocation, setAllocation] = useState<'amount' | 'lines'>('amount');
    const [amount, setAmount] = useState('');
    const [lines, setLines] = useState<Record<number, string>>({});
    const [taxable, setTaxable] = useState(true);
    const [reasonCode, setReasonCode] = useState('');
    const [reasonText, setReasonText] = useState('');
    // Not part of the preview: changing it keeps the preview and its digest.
    const [notify, setNotify] = useState(true);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [loading, setLoading] = useState(false);
    const [processing, setProcessing] = useState(false);
    // The password dialog repeats the confirm itself after a step-up; its outcome closes this dialog or shows here.
    const [steppingUp, setSteppingUp] = useState(false);

    useEffect(() => {
        if (!steppingUp) {
            return;
        }

        const offSuccess = router.on('success', () => onClose());
        const offError = router.on('error', (event) => {
            if (!event.detail.errors.password_confirmation) {
                setSteppingUp(false);
                setPreview(null);
                showErrors(event.detail.errors);
            }
        });

        return () => {
            offSuccess();
            offError();
        };
    }, [steppingUp, onClose]);

    const data = (): Record<
        string,
        string | boolean | Record<number, string>
    > =>
        reversal
            ? { reverses: String(mode.note.id), reason_text: reasonText }
            : {
                  kind,
                  ...(kind === 'credit'
                      ? { allocation, settlement: 'credit' }
                      : { taxable }),
                  ...(kind === 'credit' && allocation === 'lines'
                      ? { lines: canonicalLines(lines) }
                      : { amount: normalizeMoney(amount) }),
                  reason_code: reasonCode,
                  reason_text: reasonText,
              };

    // Any change to the form drops the preview, so a confirm always matches what staff last saw.
    const edit =
        <T,>(set: (value: T) => void) =>
        (value: T) => {
            set(value);
            setPreview(null);
            setError(null);
            setFieldErrors({});
        };

    // Field errors show under their field; a rule the note breaks shows above the buttons.
    function showErrors(errors: Record<string, string | string[]>) {
        const first = (value: string | string[]) =>
            Array.isArray(value) ? value[0] : value;
        const fields: Record<string, string> = {};
        let general: string | null = null;

        for (const [key, value] of Object.entries(errors)) {
            const field = key.startsWith('lines.') ? 'lines' : key;

            if (
                ['amount', 'lines', 'reason_code', 'reason_text'].includes(
                    field,
                )
            ) {
                fields[field] ??= first(value);
            } else {
                general ??= first(value);
            }
        }

        setFieldErrors(fields);
        setError(general);
    }

    const loadPreview = async () => {
        setLoading(true);
        setError(null);

        try {
            const response = await fetch(
                previewNote.url(invoiceId, { query: flatten(data()) }),
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                },
            );
            const body = await response.json();

            if (!response.ok) {
                if (response.status === 403) {
                    setError('You are not allowed to issue notes.');
                } else if (body.errors) {
                    showErrors(body.errors);
                } else {
                    setError(body.message ?? 'Could not prepare the note.');
                }

                return;
            }

            setPreview(body as Preview);
        } catch {
            setError('Could not prepare the note. Check your connection.');
        } finally {
            setLoading(false);
        }
    };

    const confirm = () => {
        if (!preview) {
            return;
        }

        router.post(
            storeNote.url(invoiceId),
            {
                ...data(),
                notify,
                version: preview.version,
                digest: preview.digest,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onClose(),
                onError: (errors) => {
                    if (errors.password_confirmation) {
                        setSteppingUp(true);

                        return;
                    }

                    setPreview(null);
                    showErrors(errors);
                },
            },
        );
    };

    const title = reversal
        ? `Reverse credit note ${mode.note.number}`
        : kind === 'credit'
          ? 'Issue credit note'
          : 'Issue debit note';

    return (
        <Dialog open onOpenChange={(open) => !open && !processing && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        {reversal
                            ? 'A debit note repeats every line of the credit note, and any account credit it gave is taken back. Both notes stay on record.'
                            : kind === 'credit'
                              ? 'The credit first lowers what the client still owes; anything above that goes to their account credit.'
                              : 'The debit note adds to what the client owes, with tax at the invoice’s rates.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4">
                    {kind === 'credit' && !reversal && (
                        <div className="grid gap-2">
                            <span
                                id="note-allocation-label"
                                className="text-sm font-medium"
                            >
                                Credit by
                            </span>
                            <ToggleGroup
                                aria-labelledby="note-allocation-label"
                                type="single"
                                variant="outline"
                                value={allocation}
                                onValueChange={(value) =>
                                    value &&
                                    edit(setAllocation)(
                                        value as 'amount' | 'lines',
                                    )
                                }
                            >
                                <ToggleGroupItem value="amount">
                                    Amount, spread over the lines
                                </ToggleGroupItem>
                                <ToggleGroupItem value="lines">
                                    Line
                                </ToggleGroupItem>
                            </ToggleGroup>
                        </div>
                    )}

                    {!reversal &&
                        (kind === 'credit' && allocation === 'lines' ? (
                            <div className="grid gap-2">
                                {(creditable?.lines ?? []).map((line) => (
                                    <div
                                        key={line.id}
                                        className="grid items-center gap-2 sm:grid-cols-[1fr_10rem]"
                                    >
                                        <Label htmlFor={`note-line-${line.id}`}>
                                            {line.description}
                                            <span className="block text-xs font-normal text-muted-foreground">
                                                Up to{' '}
                                                <Money
                                                    amount={line.left}
                                                    currency={currency}
                                                />
                                            </span>
                                        </Label>
                                        <Input
                                            id={`note-line-${line.id}`}
                                            inputMode="decimal"
                                            placeholder="0.00"
                                            disabled={line.left === 0}
                                            value={lines[line.id] ?? ''}
                                            onChange={(event) =>
                                                edit(setLines)({
                                                    ...lines,
                                                    [line.id]:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                ))}
                                <InputError message={fieldErrors.lines} />
                            </div>
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    htmlFor="note-amount"
                                    label={labelWithCurrency(
                                        'Amount including tax',
                                        currency,
                                    )}
                                    hint={
                                        kind === 'credit' && creditable ? (
                                            <>
                                                At most{' '}
                                                <Money
                                                    amount={creditable.total}
                                                    currency={currency}
                                                />
                                                .
                                            </>
                                        ) : (
                                            'Including tax, such as 1,250.00.'
                                        )
                                    }
                                    error={fieldErrors.amount}
                                >
                                    <Input
                                        id="note-amount"
                                        inputMode="decimal"
                                        placeholder="0.00"
                                        aria-invalid={!!fieldErrors.amount}
                                        aria-describedby="note-amount-description"
                                        value={amount}
                                        onChange={(event) =>
                                            edit(setAmount)(event.target.value)
                                        }
                                    />
                                </Field>
                                {kind === 'debit' && (
                                    <div className="flex items-center gap-2 sm:pt-7">
                                        <Checkbox
                                            id="note-taxable"
                                            checked={taxable}
                                            onCheckedChange={(checked) =>
                                                edit(setTaxable)(
                                                    checked === true,
                                                )
                                            }
                                        />
                                        <Label htmlFor="note-taxable">
                                            Taxable at the invoice’s rates
                                        </Label>
                                    </div>
                                )}
                            </div>
                        ))}

                    {!reversal && (
                        <Field
                            htmlFor="note-reason"
                            label="Reason"
                            error={fieldErrors.reason_code}
                        >
                            <SelectField
                                id="note-reason"
                                invalid={!!fieldErrors.reason_code}
                                value={reasonCode}
                                onChange={edit(setReasonCode)}
                                options={Object.entries(REASONS[kind]).map(
                                    ([value, label]) => ({ value, label }),
                                )}
                            />
                        </Field>
                    )}

                    <Field
                        htmlFor="note-reason-text"
                        label="Reason shown to the client"
                        hint="Printed on the note. At most 500 characters."
                        error={fieldErrors.reason_text}
                    >
                        <Textarea
                            id="note-reason-text"
                            aria-invalid={!!fieldErrors.reason_text}
                            aria-describedby="note-reason-text-description"
                            rows={2}
                            maxLength={500}
                            value={reasonText}
                            onChange={(event) =>
                                edit(setReasonText)(event.target.value)
                            }
                        />
                    </Field>

                    {preview && (
                        <NotePreview
                            preview={preview}
                            currency={currency}
                            reversal={reversal}
                        />
                    )}
                    <div className="flex items-start gap-2">
                        <Checkbox
                            id="note-notify"
                            checked={notify}
                            onCheckedChange={(checked) =>
                                setNotify(checked === true)
                            }
                        />
                        <div className="grid gap-0.5">
                            <Label htmlFor="note-notify">Send to client</Label>
                            <p className="text-xs text-muted-foreground">
                                Emails the note to the client’s billing
                                contacts.
                            </p>
                        </div>
                    </div>
                    <InputError message={error ?? undefined} />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    {preview ? (
                        <Button
                            type="button"
                            onClick={confirm}
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            {reversal
                                ? 'Issue reversing debit note'
                                : `Issue ${kind} note`}{' '}
                            · {formatMoney(preview.total, currency)}
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            onClick={loadPreview}
                            disabled={loading}
                        >
                            {loading && <Spinner />}
                            Preview
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function NotePreview({
    preview,
    currency,
    reversal,
}: {
    preview: Preview;
    currency: string;
    reversal: boolean;
}) {
    const tax = preview.tax1 + preview.tax2;

    return (
        <div className="grid gap-3 rounded-lg border bg-muted/40 p-3 text-sm">
            <table className="w-full">
                <thead className="text-left text-xs text-muted-foreground">
                    <tr>
                        <th className="pb-1 font-medium">Line</th>
                        <th className="hidden pb-1 text-right font-medium sm:table-cell">
                            Net
                        </th>
                        <th className="hidden pb-1 text-right font-medium sm:table-cell">
                            Tax
                        </th>
                        <th className="pb-1 text-right font-medium">Total</th>
                    </tr>
                </thead>
                <tbody>
                    {preview.lines.map((line, index) => (
                        <tr key={index}>
                            <td className="py-0.5 pr-2">
                                {line.description}
                                <span className="block text-xs text-muted-foreground sm:hidden">
                                    Net{' '}
                                    <Money
                                        amount={line.net}
                                        currency={currency}
                                    />{' '}
                                    + tax{' '}
                                    <Money
                                        amount={line.tax1 + line.tax2}
                                        currency={currency}
                                    />
                                </span>
                            </td>
                            <td className="hidden text-right sm:table-cell">
                                <Money amount={line.net} currency={currency} />
                            </td>
                            <td className="hidden text-right sm:table-cell">
                                <Money
                                    amount={line.tax1 + line.tax2}
                                    currency={currency}
                                />
                            </td>
                            <td className="text-right">
                                <Money
                                    amount={line.gross}
                                    currency={currency}
                                />
                            </td>
                        </tr>
                    ))}
                </tbody>
                <tfoot className="border-t font-medium">
                    <tr>
                        <td className="pt-1">
                            Note total
                            <span className="block text-xs font-normal text-muted-foreground sm:hidden">
                                Tax <Money amount={tax} currency={currency} />
                            </span>
                        </td>
                        <td className="hidden pt-1 text-right sm:table-cell">
                            <Money
                                amount={preview.total - tax}
                                currency={currency}
                            />
                        </td>
                        <td className="hidden pt-1 text-right sm:table-cell">
                            <Money amount={tax} currency={currency} />
                        </td>
                        <td className="pt-1 text-right">
                            <Money amount={preview.total} currency={currency} />
                        </td>
                    </tr>
                </tfoot>
            </table>
            <dl className="grid grid-cols-[1fr_auto] gap-1">
                <dt className="text-muted-foreground">Balance after</dt>
                <dd className="text-right">
                    <Money amount={preview.balance_after} currency={currency} />
                </dd>
                {preview.to_credit > 0 && (
                    <>
                        <dt className="text-muted-foreground">
                            {reversal
                                ? 'Taken back from account credit'
                                : 'To account credit'}
                        </dt>
                        <dd className="text-right">
                            <Money
                                amount={preview.to_credit}
                                currency={currency}
                            />
                        </dd>
                    </>
                )}
                {preview.credit_after !== null && (
                    <>
                        <dt className="text-muted-foreground">
                            Client’s account credit after
                        </dt>
                        <dd className="text-right">
                            <Money
                                amount={preview.credit_after}
                                currency={currency}
                                tone="ledger"
                            />
                        </dd>
                    </>
                )}
            </dl>
            {preview.credit_after !== null && preview.credit_after < 0 && (
                <p className="text-sm text-danger-text">
                    The client has already used part of this credit, so their
                    account credit goes below zero.
                </p>
            )}
            {preview.due_date && (
                <p className="text-sm">
                    The invoice is paid, so it becomes unpaid again for{' '}
                    <Money amount={preview.balance_after} currency={currency} />
                    , due <DateTime value={preview.due_date} />. No late fee or
                    overdue notice counts from its old due date.
                </p>
            )}
        </div>
    );
}

function canonicalLines(lines: Record<number, string>): Record<number, string> {
    return Object.fromEntries(
        Object.entries(lines)
            .filter(([, value]) => value.trim() !== '')
            .map(([id, value]) => [id, normalizeMoney(value)]),
    );
}

/** Query-string form of the note data: lines as lines[<id>]=<amount>. */
function flatten(
    data: Record<string, string | boolean | Record<number, string>>,
): Record<string, string> {
    const query: Record<string, string> = {};

    for (const [key, value] of Object.entries(data)) {
        if (typeof value === 'object') {
            for (const [id, amount] of Object.entries(value)) {
                query[`${key}[${id}]`] = amount;
            }
        } else {
            query[key] =
                typeof value === 'boolean' ? (value ? '1' : '0') : value;
        }
    }

    return query;
}
