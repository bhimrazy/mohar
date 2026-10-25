import { FileText } from 'lucide-react';
import { Head } from '@/components/app/head';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import {
    ClientTaxNotesTable,
    type ClientTaxNote,
} from '@/components/billing/client-tax-notes';
import { index as invoicesIndex } from '@/actions/App/Modules/Billing/Http/Controllers/Client/InvoiceController';
import type { Paginated } from '@/types/app';

export default function ClientTaxNotes({
    notes,
}: {
    notes: Paginated<ClientTaxNote>;
}) {
    return (
        <>
            <Head title="Credit notes" />
            <Page>
                <PageHeader
                    back={{ href: invoicesIndex(), label: 'Invoices' }}
                    title="Credit notes"
                    description="Credit and debit notes correct an invoice after it was issued. Download each one for your records."
                />
                <ClientTaxNotesTable
                    notes={notes.data}
                    empty={
                        <EmptyState
                            icon={FileText}
                            title="You don’t have any credit or debit notes."
                        />
                    }
                    footer={<Pagination paginator={notes} itemLabel="notes" />}
                />
            </Page>
        </>
    );
}
