import type { ReactNode } from 'react';
import { InvoiceDate } from './invoice-date';
import type { Invoice, InvoiceParties, TaxId } from './types';

export const DOCUMENT_LABELS: Record<InvoiceParties['document'], string> = {
    draft: 'Draft invoice',
    tax_invoice: 'Tax invoice',
    proforma: 'Proforma invoice',
    invoice: 'Invoice',
};

function Label({ children }: { children: ReactNode }) {
    return (
        <div className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
            {children}
        </div>
    );
}

function TaxIdLine({ taxId }: { taxId: TaxId }) {
    return (
        <div>
            <span className="text-muted-foreground">{taxId.label}</span>{' '}
            <span className="font-medium tabular-nums">{taxId.value}</span>
        </div>
    );
}

type InvoicePartiesHeaderProps = {
    invoice: Invoice;
    parties: InvoiceParties;
    /** Extra facts beside "Bill to", e.g. the payment method on the admin page. */
    aside?: { label: string; value: ReactNode }[];
};

/** The page title: "Tax invoice INV-1243"; a draft is just "Draft invoice". */
export function documentTitle(invoice: Invoice, parties: InvoiceParties) {
    return parties.document === 'draft'
        ? DOCUMENT_LABELS.draft
        : `${DOCUMENT_LABELS[parties.document]} ${invoice.number}`;
}

/**
 * The top of the invoice document: seller (From) with its tax registrations, the document facts, and the buyer
 * (Bill to) with their tax number, as printed on the PDF.
 */
export function InvoicePartiesHeader({
    invoice,
    parties,
    aside = [],
}: InvoicePartiesHeaderProps) {
    const { seller, buyer } = parties;
    const facts = [
        ...(parties.place_of_supply
            ? [{ label: 'Place of supply', value: parties.place_of_supply }]
            : []),
        ...aside,
    ];

    return (
        <div className="text-sm">
            <div className="grid gap-4 p-4 sm:grid-cols-[1fr_auto] sm:gap-6 sm:px-(--card-p) sm:py-5">
                <div className="min-w-0 space-y-1">
                    <Label>From</Label>
                    {seller ? (
                        <>
                            <div className="font-display text-base">
                                {seller.name}
                            </div>
                            {seller.address && (
                                <div className="whitespace-pre-line text-muted-foreground">
                                    {seller.address}
                                </div>
                            )}
                            {(seller.email || seller.phone) && (
                                <div className="break-words text-muted-foreground">
                                    {[seller.email, seller.phone]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </div>
                            )}
                            {seller.tax_ids.map((taxId) => (
                                <TaxIdLine key={taxId.label} taxId={taxId} />
                            ))}
                        </>
                    ) : (
                        <p className="text-muted-foreground">
                            Seller details were not recorded on this invoice.
                        </p>
                    )}
                    {parties.seller_from_settings && (
                        <p className="pt-1 text-xs text-muted-foreground">
                            Today&apos;s company details. They are saved on the
                            invoice when it is published.
                        </p>
                    )}
                </div>
                <dl className="grid h-fit grid-cols-[auto_1fr] gap-x-4 gap-y-1 sm:text-right">
                    <dt className="text-muted-foreground">
                        {DOCUMENT_LABELS[parties.document]}
                    </dt>
                    <dd className="font-medium tabular-nums">
                        {invoice.number}
                    </dd>
                    {invoice.legal_number && invoice.proforma_number && (
                        <>
                            <dt className="text-muted-foreground">Proforma</dt>
                            <dd className="tabular-nums">
                                {invoice.proforma_number}
                            </dd>
                        </>
                    )}
                    {invoice.issue_date && (
                        <>
                            <dt className="text-muted-foreground">Issued</dt>
                            <dd>
                                <InvoiceDate
                                    value={invoice.issue_date}
                                    bs={invoice.issue_date_bs}
                                />
                            </dd>
                        </>
                    )}
                    {invoice.due_date && (
                        <>
                            <dt className="text-muted-foreground">Due</dt>
                            <dd>
                                <InvoiceDate
                                    value={invoice.due_date}
                                    bs={invoice.due_date_bs}
                                />
                            </dd>
                        </>
                    )}
                </dl>
            </div>
            <div className="grid gap-4 border-t bg-muted/40 p-4 sm:grid-cols-2 sm:gap-6 sm:px-(--card-p)">
                <div className="min-w-0 space-y-1">
                    <Label>Bill to</Label>
                    {buyer ? (
                        <>
                            <div className="font-medium">{buyer.name}</div>
                            {buyer.contact && <div>{buyer.contact}</div>}
                            {buyer.address.map((line) => (
                                <div
                                    key={line}
                                    className="text-muted-foreground"
                                >
                                    {line}
                                </div>
                            ))}
                            {buyer.email && (
                                <div className="break-words text-muted-foreground">
                                    {buyer.email}
                                </div>
                            )}
                            {buyer.tax_id && <TaxIdLine taxId={buyer.tax_id} />}
                        </>
                    ) : (
                        <p className="text-muted-foreground">
                            No billing details were recorded on this invoice.
                        </p>
                    )}
                </div>
                {facts.length > 0 && (
                    <div className="space-y-3">
                        {facts.map((fact) => (
                            <div key={fact.label}>
                                <Label>{fact.label}</Label>
                                <div>{fact.value}</div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
