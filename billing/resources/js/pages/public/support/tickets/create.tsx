import { Head } from '@inertiajs/react';
import GuestTicketController from '@/actions/App/Modules/Support/Http/Controllers/Public/GuestTicketController';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { OpenTicketForm } from '@/components/support/open-ticket-form';
import { PublicSupportNav } from '@/components/support/public-nav';
import TextLink from '@/components/text-link';
import { login } from '@/routes';
import type { SelectOption } from '@/types/app';
import type { DepartmentOption, UploadLimits } from '@/types/support';

type Props = {
    departments: DepartmentOption[];
    selected: string | null;
    priorities: SelectOption[];
    limits: UploadLimits;
};

export default function GuestOpenTicket({
    departments,
    selected,
    priorities,
    limits,
}: Props) {
    return (
        <>
            <Head title="Contact support" />
            <Page size="narrow">
                <PublicSupportNav />
                <PageHeader
                    title="Contact support"
                    description={
                        <>
                            Already a client?{' '}
                            <TextLink href={login()}>Sign in</TextLink> to see
                            your services and all departments.
                        </>
                    }
                />
                <OpenTicketForm
                    form={GuestTicketController.store.form()}
                    departments={departments}
                    selected={selected}
                    priorities={priorities}
                    limits={limits}
                    guest
                />
            </Page>
        </>
    );
}
