import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import type { FormEvent } from 'react';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import ServerController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServerController';
import ServerGroupController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServerGroupController';
import type { SelectOption } from '@/types/app';

type Props = {
    group?: {
        id: number;
        name: string;
        fill_type: string;
        server_ids: number[];
    };
    fillTypes: SelectOption[];
    servers: SelectOption[];
};

export default function ServerGroupForm({ group, fillTypes, servers }: Props) {
    const form = useForm({
        name: group?.name ?? '',
        fill_type: group?.fill_type ?? 'least_full',
        server_ids: (group?.server_ids ?? []).map(String),
    });
    const { data, setData, errors, processing } = form;
    const label = (id: string) =>
        servers.find((server) => server.value === id)?.label ?? id;

    const toggle = (id: string, checked: boolean) =>
        setData(
            'server_ids',
            checked
                ? [...data.server_ids, id]
                : data.server_ids.filter((value) => value !== id),
        );

    const move = (index: number, offset: number) => {
        const next = [...data.server_ids];
        const [item] = next.splice(index, 1);
        next.splice(index + offset, 0, item);
        setData('server_ids', next);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(
            group
                ? ServerGroupController.update(group.id)
                : ServerGroupController.store(),
        );
    };

    return (
        <>
            <Head title={group ? `Edit ${group.name}` : 'New server group'} />
            <Page size="narrow">
                <PageHeader
                    back={{ href: ServerController.index(), label: 'Servers' }}
                    title={group ? `Edit ${group.name}` : 'New server group'}
                />
                <form onSubmit={submit} className="flex flex-col gap-6">
                    <SectionCard title="Group">
                        <div className="grid gap-6 sm:grid-cols-2">
                            <Field
                                htmlFor="name"
                                label="Name"
                                error={errors.name}
                            >
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(event) =>
                                        setData('name', event.target.value)
                                    }
                                    aria-invalid={!!errors.name}
                                />
                            </Field>
                            <Field
                                htmlFor="fill_type"
                                label="Fill type"
                                hint="Least full balances load; fill until full uses servers in order."
                                error={errors.fill_type}
                            >
                                <Select
                                    value={data.fill_type}
                                    onValueChange={(value) =>
                                        setData('fill_type', value)
                                    }
                                >
                                    <SelectTrigger
                                        id="fill_type"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {fillTypes.map((type) => (
                                            <SelectItem
                                                key={type.value}
                                                value={type.value}
                                            >
                                                {type.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        </div>
                    </SectionCard>

                    <SectionCard
                        title="Servers"
                        description="Tick the servers in this group; the order decides ties and the fill-until-full sequence."
                    >
                        <div className="grid gap-4">
                            <div className="grid gap-2 sm:grid-cols-2">
                                {servers.map((server) => (
                                    <label
                                        key={server.value}
                                        className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm"
                                    >
                                        <Checkbox
                                            checked={data.server_ids.includes(
                                                server.value,
                                            )}
                                            onCheckedChange={(checked) =>
                                                toggle(
                                                    server.value,
                                                    checked === true,
                                                )
                                            }
                                        />
                                        {server.label}
                                    </label>
                                ))}
                            </div>
                            {data.server_ids.length > 0 && (
                                <ol className="divide-y rounded-lg border text-sm">
                                    {data.server_ids.map((id, index) => (
                                        <li
                                            key={id}
                                            className="flex items-center justify-between gap-2 px-3 py-1.5"
                                        >
                                            <span>
                                                <span className="mr-2 text-muted-foreground tabular-nums">
                                                    {index + 1}.
                                                </span>
                                                {label(id)}
                                            </span>
                                            <span className="flex gap-1">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Move ${label(id)} up`}
                                                    disabled={index === 0}
                                                    onClick={() =>
                                                        move(index, -1)
                                                    }
                                                >
                                                    <ArrowUp />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Move ${label(id)} down`}
                                                    disabled={
                                                        index ===
                                                        data.server_ids.length -
                                                            1
                                                    }
                                                    onClick={() =>
                                                        move(index, 1)
                                                    }
                                                >
                                                    <ArrowDown />
                                                </Button>
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                            <InputError message={errors.server_ids} />
                        </div>
                    </SectionCard>

                    <FormActions>
                        <Button variant="outline" asChild>
                            <Link href={ServerController.index()}>Cancel</Link>
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            {group ? 'Save changes' : 'Create group'}
                        </Button>
                    </FormActions>
                </form>
            </Page>
        </>
    );
}
