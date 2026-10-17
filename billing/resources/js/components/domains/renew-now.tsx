import { useForm } from '@inertiajs/react';
import { RefreshCcw } from 'lucide-react';
import type { FormEvent } from 'react';
import DomainController from '@/actions/App/Modules/Domains/Http/Controllers/Client/DomainController';
import { Field } from '@/components/app/field';
import { SectionCard } from '@/components/app/section-card';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/money';

type RenewNowProps = {
    domainId: number;
    currency: string;
    terms: { years: number; price: number }[];
};

/** Client "Renew now": pick a term at today's price; creates the renewal invoice to pay. */
export function RenewNow({ domainId, currency, terms }: RenewNowProps) {
    const form = useForm({ years: String(terms[0]?.years ?? 1) });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ years: Number(data.years) }));
        form.submit(DomainController.renew(domainId), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} id="renew">
            <SectionCard
                title="Renew now"
                description="Add years to your domain without waiting for the renewal invoice. You'll get an invoice to pay; the domain renews as soon as it's paid."
                footer={
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? <Spinner /> : <RefreshCcw />}
                        Renew now
                    </Button>
                }
            >
                <Field
                    htmlFor="renew-years"
                    label="Renewal term"
                    error={form.errors.years}
                >
                    <Select
                        value={form.data.years}
                        onValueChange={(value) => form.setData('years', value)}
                    >
                        <SelectTrigger
                            id="renew-years"
                            className="w-full"
                            aria-describedby="renew-years-description"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {terms.map((term) => (
                                <SelectItem
                                    key={term.years}
                                    value={String(term.years)}
                                >
                                    {term.years} year
                                    {term.years === 1 ? '' : 's'} ·{' '}
                                    {formatMoney(term.price, currency)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
            </SectionCard>
        </form>
    );
}
