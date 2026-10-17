import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import ClientContactController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/ClientContactController';
import { Field } from '@/components/app/field';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { CheckboxGroup } from './checkbox-group';
import { EMAIL_TYPE_LABELS } from './types';
import type { ClientContact } from './types';

type Props = {
    clientId: number;
    contact?: ClientContact;
    emailTypes: string[];
    trigger: ReactNode;
};

export function ContactDialog({
    clientId,
    contact,
    emailTypes,
    trigger,
}: Props) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        first_name: contact?.first_name ?? '',
        last_name: contact?.last_name ?? '',
        email: contact?.email ?? '',
        phone: contact?.phone ?? '',
        email_preferences: contact
            ? emailTypes.filter((type) => contact.email_preferences[type])
            : [...emailTypes],
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(
            contact
                ? ClientContactController.update([clientId, contact.id])
                : ClientContactController.store(clientId),
            {
                preserveScroll: true,
                onSuccess: () => {
                    setOpen(false);

                    if (!contact) {
                        form.reset();
                    }
                },
            },
        );
    };

    const fields = [
        ['first_name', 'First name', 'text'],
        ['last_name', 'Last name', 'text'],
        ['email', 'Email', 'email'],
        ['phone', 'Phone', 'tel'],
    ] as const;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {contact ? 'Edit contact' : 'Add contact'}
                        </DialogTitle>
                        <DialogDescription>
                            Contacts receive the email types you choose; they
                            cannot sign in.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {fields.map(([name, label, type]) => (
                            <Field
                                key={name}
                                htmlFor={`contact-${name}`}
                                label={label}
                                error={form.errors[name]}
                                optional={name === 'phone'}
                            >
                                <Input
                                    id={`contact-${name}`}
                                    type={type}
                                    value={form.data[name]}
                                    onChange={(event) =>
                                        form.setData(name, event.target.value)
                                    }
                                    aria-invalid={Boolean(form.errors[name])}
                                />
                            </Field>
                        ))}
                    </div>
                    <div className="grid gap-2">
                        <Label>Email types</Label>
                        <CheckboxGroup
                            idPrefix={`contact-${contact?.id ?? 'new'}-pref`}
                            options={emailTypes.map((type) => ({
                                value: type,
                                label: EMAIL_TYPE_LABELS[type] ?? type,
                            }))}
                            value={form.data.email_preferences}
                            onChange={(value) =>
                                form.setData('email_preferences', value)
                            }
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {contact ? 'Save contact' : 'Add contact'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
