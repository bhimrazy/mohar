import { useForm } from '@inertiajs/react';
import { Minus, Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import { Field } from '@/components/app/field';
import { SectionCard } from '@/components/app/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import type { RouteAction } from '@/types/app';

const MIN = 2;
const MAX = 5;

type NameserversFormProps = {
    action: RouteAction;
    nameservers: string[];
    disabled?: boolean;
    description?: string;
};

export function NameserversForm({
    action,
    nameservers,
    disabled = false,
    description = 'Nameservers tell the internet where your website and email are hosted.',
}: NameserversFormProps) {
    const initial = [...nameservers];

    while (initial.length < MIN) {
        initial.push('');
    }

    const form = useForm({ nameservers: initial });
    const errors = form.errors as Record<string, string | undefined>;

    const update = (index: number, value: string) =>
        form.setData(
            'nameservers',
            form.data.nameservers.map((current, i) =>
                i === index ? value : current,
            ),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(action, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit}>
            <SectionCard
                title="Nameservers"
                description={description}
                footer={
                    <Button
                        type="submit"
                        disabled={disabled || form.processing || !form.isDirty}
                    >
                        {form.processing && <Spinner />}
                        Save nameservers
                    </Button>
                }
            >
                <div className="grid items-start gap-4 sm:grid-cols-2">
                    {form.data.nameservers.map((value, index) => {
                        const id = `nameserver-${index}`;
                        const error = errors[`nameservers.${index}`];

                        return (
                            <Field
                                key={id}
                                htmlFor={id}
                                label={`Nameserver ${index + 1}`}
                                error={error}
                                optional={index >= MIN}
                            >
                                <div className="flex gap-2">
                                    <Input
                                        id={id}
                                        value={value}
                                        disabled={disabled}
                                        placeholder={`ns${index + 1}.example.com`}
                                        onChange={(event) =>
                                            update(index, event.target.value)
                                        }
                                        aria-invalid={!!error}
                                        aria-describedby={`${id}-description`}
                                        autoComplete="off"
                                        spellCheck={false}
                                    />
                                    {index >= MIN && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            aria-label={`Remove nameserver ${index + 1}`}
                                            disabled={disabled}
                                            onClick={() =>
                                                form.setData(
                                                    'nameservers',
                                                    form.data.nameservers.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <Minus />
                                        </Button>
                                    )}
                                </div>
                            </Field>
                        );
                    })}
                </div>
                {errors.nameservers && (
                    <p className="mt-3 text-sm text-destructive">
                        {errors.nameservers}
                    </p>
                )}
                {form.data.nameservers.length < MAX && !disabled && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="mt-3"
                        onClick={() =>
                            form.setData('nameservers', [
                                ...form.data.nameservers,
                                '',
                            ])
                        }
                    >
                        <Plus />
                        Add nameserver
                    </Button>
                )}
            </SectionCard>
        </form>
    );
}
