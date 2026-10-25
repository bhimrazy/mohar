import { useForm } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { CalendarClock, Info, RefreshCcw, ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';
import DomainController from '@/actions/App/Modules/Domains/Http/Controllers/Client/DomainController';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { Field } from '@/components/app/field';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatCard } from '@/components/app/stat-card';
import { ActionErrors } from '@/components/domains/action-errors';
import { ContactsForm } from '@/components/domains/contacts-form';
import { EppCode } from '@/components/domains/epp-code';
import { NameserversForm } from '@/components/domains/nameservers-form';
import { RenewNow } from '@/components/domains/renew-now';
import { SettingSwitch } from '@/components/domains/setting-switch';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { MANAGED_AT_REGISTRY, domainStatus, expiryHint } from '@/types/domains';
import type { Domain, DomainContacts } from '@/types/domains';

type Props = {
    domain: Domain;
    contacts: DomainContacts;
    capabilities: string[];
    canManage: boolean;
    canResubmitTransfer: boolean;
    renewalTerms: { years: number; price: number }[];
    canRenewNow: boolean;
};

const PENDING_COPY: Partial<Record<Domain['status'], string>> = {
    pending:
        'We are waiting for payment or for our team to process this order.',
    pending_registration:
        'The registry is confirming the registration. This usually takes a few minutes.',
    pending_transfer:
        'Your transfer is in progress. Approve it if your current registrar emails you; it can take up to 7 days.',
    grace: 'This domain has expired but can still be renewed at the normal price for a short time.',
    redemption:
        'This domain has expired. It can still be restored for a fee — renew it now to keep it.',
    expired: 'This domain has expired and can no longer be managed here.',
};

function ResubmitTransfer({ domain }: { domain: Domain }) {
    const form = useForm({ epp_code: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(DomainController.resubmitTransfer(domain.id), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form onSubmit={submit}>
            <SectionCard
                title="Retry the transfer"
                description={
                    domain.last_error ??
                    'The transfer did not complete. Get a new EPP code from your current registrar and try again; you will not be charged again.'
                }
                footer={
                    <Button
                        type="submit"
                        disabled={form.processing || !form.data.epp_code}
                    >
                        {form.processing && <Spinner />}
                        Retry transfer
                    </Button>
                }
            >
                <Field
                    htmlFor="epp_code"
                    label="EPP code"
                    error={form.errors.epp_code}
                >
                    <Input
                        id="epp_code"
                        autoComplete="off"
                        value={form.data.epp_code}
                        onChange={(event) =>
                            form.setData('epp_code', event.target.value)
                        }
                        aria-invalid={!!form.errors.epp_code}
                        aria-describedby="epp_code-description"
                    />
                </Field>
            </SectionCard>
        </form>
    );
}

export default function MyDomain({
    domain,
    contacts,
    capabilities,
    canManage,
    canResubmitTransfer,
    renewalTerms,
    canRenewNow,
}: Props) {
    const supports = (capability: string) => capabilities.includes(capability);
    const notice = PENDING_COPY[domain.status];

    return (
        <>
            <Head title={domain.name_unicode} />
            <Page>
                <PageHeader
                    back={{
                        href: DomainController.index(),
                        label: 'My domains',
                    }}
                    title={domain.name_unicode}
                    status={domainStatus(domain)}
                    meta={
                        domain.expiry_date ? (
                            <span>
                                Expires <DateTime value={domain.expiry_date} />{' '}
                                · {expiryHint(domain.days_until_expiry)}
                            </span>
                        ) : undefined
                    }
                />

                <ActionErrors />

                {notice && (
                    <Alert
                        variant={
                            domain.status === 'redemption' ||
                            domain.status === 'expired'
                                ? 'destructive'
                                : 'default'
                        }
                    >
                        <Info />
                        <AlertTitle>
                            {domain.status === 'grace' ||
                            domain.status === 'redemption' ||
                            domain.status === 'expired'
                                ? 'Renewal needed'
                                : 'In progress'}
                        </AlertTitle>
                        <AlertDescription>
                            {notice}
                            {canRenewNow &&
                                renewalTerms.length > 0 &&
                                (domain.status === 'grace' ||
                                    domain.status === 'redemption') && (
                                    <a
                                        href="#renew"
                                        className="mt-1 block font-medium underline"
                                    >
                                        Renew now
                                    </a>
                                )}
                        </AlertDescription>
                    </Alert>
                )}

                {domain.contact_change_pending && (
                    <Alert>
                        <ShieldCheck />
                        <AlertTitle>
                            Contact change awaiting verification
                        </AlertTitle>
                        <AlertDescription>
                            Check the registrant's inbox to confirm the change.
                            {domain.transfer_lock_until && (
                                <>
                                    {' '}
                                    Transfers are locked until{' '}
                                    <DateTime
                                        value={domain.transfer_lock_until}
                                    />
                                    .
                                </>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                <div
                    className={
                        domain.registered_by_client
                            ? 'grid grid-cols-1 gap-4 sm:grid-cols-2'
                            : 'grid grid-cols-1 gap-4 sm:grid-cols-3'
                    }
                >
                    <StatCard
                        label="Expires"
                        icon={CalendarClock}
                        value={
                            <DateTime
                                value={domain.expiry_date}
                                fallback={
                                    domain.registered_by_client
                                        ? MANAGED_AT_REGISTRY
                                        : 'Not yet'
                                }
                            />
                        }
                        hint={expiryHint(domain.days_until_expiry) ?? undefined}
                    />
                    {domain.registered_by_client ? (
                        <StatCard
                            label="Renewal"
                            icon={RefreshCcw}
                            value="At the registry"
                            hint={
                                domain.how_to_url ? (
                                    <>
                                        You renew it with the registry.{' '}
                                        <a
                                            href={domain.how_to_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="underline"
                                        >
                                            Registry guide
                                        </a>
                                    </>
                                ) : (
                                    'You renew it with the registry.'
                                )
                            }
                        />
                    ) : (
                        <>
                            <StatCard
                                label="Renewal"
                                icon={RefreshCcw}
                                value={
                                    <Money
                                        amount={domain.recurring_amount}
                                        currency={domain.currency}
                                    />
                                }
                                hint={`every ${domain.period_years === 1 ? 'year' : `${domain.period_years} years`}`}
                            />
                            <StatCard
                                label="Auto-renew"
                                icon={ShieldCheck}
                                value={domain.auto_renew ? 'On' : 'Off'}
                                hint={
                                    domain.auto_renew
                                        ? 'Renewal invoice before expiry'
                                        : 'Renew manually before expiry'
                                }
                            />
                        </>
                    )}
                </div>

                {canResubmitTransfer && <ResubmitTransfer domain={domain} />}

                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
                        <NameserversForm
                            action={DomainController.nameservers(domain.id)}
                            nameservers={domain.nameservers}
                            disabled={!canManage || !supports('nameservers')}
                        />
                        {supports('contacts') && (
                            <ContactsForm
                                action={DomainController.contacts(domain.id)}
                                contacts={contacts}
                                disabled={!canManage}
                            />
                        )}
                    </div>

                    <div className="flex min-w-0 flex-col gap-4">
                        {(!domain.registered_by_client || supports('lock')) && (
                            <SectionCard title="Settings">
                                <div className="grid gap-3">
                                    {!domain.registered_by_client && (
                                        <SettingSwitch
                                            id="auto-renew"
                                            title="Auto-renew"
                                            description="We send a renewal invoice before the domain expires and renew it once paid."
                                            checked={domain.auto_renew}
                                            action={DomainController.autoRenew(
                                                domain.id,
                                            )}
                                            disabled={!canManage}
                                        />
                                    )}
                                    {supports('lock') && (
                                        <SettingSwitch
                                            id="registrar-lock"
                                            title="Transfer lock"
                                            description="Keep this on unless you are moving the domain to another provider."
                                            checked={
                                                domain.registrar_lock === true
                                            }
                                            action={DomainController.lock(
                                                domain.id,
                                            )}
                                            disabled={!canManage}
                                        />
                                    )}
                                </div>
                            </SectionCard>
                        )}

                        {supports('epp') && (
                            <SectionCard
                                title="Transfer away"
                                description="Moving to another provider? You need the EPP (authorisation) code."
                            >
                                <EppCode
                                    action={DomainController.epp(domain.id)}
                                    domainName={domain.name_unicode}
                                    disabled={!canManage}
                                />
                            </SectionCard>
                        )}

                        {canRenewNow && renewalTerms.length > 0 && (
                            <RenewNow
                                domainId={domain.id}
                                currency={domain.currency}
                                terms={renewalTerms}
                            />
                        )}

                        <SectionCard title="Details">
                            <DescriptionList
                                columns={1}
                                items={[
                                    {
                                        label: 'Registered',
                                        value: (
                                            <DateTime
                                                value={domain.registration_date}
                                            />
                                        ),
                                    },
                                    {
                                        label: 'Next renewal due',
                                        value: (
                                            <DateTime
                                                value={domain.next_due_date}
                                            />
                                        ),
                                    },
                                    {
                                        label: 'ID protection',
                                        value: domain.id_protection
                                            ? 'Included'
                                            : 'Not included',
                                    },
                                ]}
                            />
                        </SectionCard>
                    </div>
                </div>
            </Page>
        </>
    );
}
