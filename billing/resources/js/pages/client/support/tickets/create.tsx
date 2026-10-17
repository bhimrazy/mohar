import { Head } from '@inertiajs/react';
import TicketController from '@/actions/App/Modules/Support/Http/Controllers/Client/TicketController';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { OpenTicketForm } from '@/components/support/open-ticket-form';
import type { SelectOption } from '@/types/app';
import type { DepartmentOption, UploadLimits } from '@/types/support';

type Props = {
    departments: DepartmentOption[];
    selected: string | null;
    priorities: SelectOption[];
    limits: UploadLimits;
};

export default function ClientOpenTicket({
    departments,
    selected,
    priorities,
    limits,
}: Props) {
    return (
        <>
            <Head title="Open a ticket" />
            <Page size="narrow">
                <PageHeader
                    back={{
                        href: TicketController.index(),
                        label: 'Support tickets',
                    }}
                    title="Open a ticket"
                    description="Tell us what you need and we'll get back to you by email."
                />
                <OpenTicketForm
                    form={TicketController.store.form()}
                    departments={departments}
                    selected={selected}
                    priorities={priorities}
                    limits={limits}
                    showService
                />
            </Page>
        </>
    );
}
