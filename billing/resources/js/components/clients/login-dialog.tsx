import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import ClientUserController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/ClientUserController';
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
import { AREA_LABELS } from './types';
import type { ClientLogin } from './types';

type Props = {
    clientId: number;
    areas: string[];
    /** Edit this login's permissions; omit to invite a new login. */
    login?: ClientLogin;
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
};

export function LoginDialog({
    clientId,
    areas,
    login,
    trigger,
    open: controlledOpen,
    onOpenChange,
}: Props) {
    const [internalOpen, setInternalOpen] = useState(false);
    const open = controlledOpen ?? internalOpen;
    const setOpen = (next: boolean) => {
        setInternalOpen(next);
        onOpenChange?.(next);
    };
    const form = useForm({
        email: '',
        name: '',
        permissions: login?.permissions ?? ['billing', 'services', 'support'],
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(
            login
                ? ClientUserController.update([clientId, login.id])
                : ClientUserController.store(clientId),
            {
                preserveScroll: true,
                onSuccess: () => {
                    setOpen(false);

                    if (!login) {
                        form.reset();
                    }
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {trigger && <DialogTrigger asChild>{trigger}</DialogTrigger>}
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {login
                                ? `Permissions for ${login.email}`
                                : 'Add login'}
                        </DialogTitle>
                        <DialogDescription>
                            {login
                                ? 'Choose which parts of the account this login can use.'
                                : 'New email addresses get a link to set their password. Existing customers keep their password.'}
                        </DialogDescription>
                    </DialogHeader>
                    {!login && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                htmlFor="login-name"
                                label="Name"
                                error={form.errors.name}
                            >
                                <Input
                                    id="login-name"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    aria-invalid={Boolean(form.errors.name)}
                                />
                            </Field>
                            <Field
                                htmlFor="login-email"
                                label="Email"
                                error={form.errors.email}
                            >
                                <Input
                                    id="login-email"
                                    type="email"
                                    value={form.data.email}
                                    onChange={(event) =>
                                        form.setData(
                                            'email',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={Boolean(form.errors.email)}
                                />
                            </Field>
                        </div>
                    )}
                    <div className="grid gap-2">
                        <Label>Access</Label>
                        <CheckboxGroup
                            idPrefix={`login-${login?.id ?? 'new'}-area`}
                            options={areas.map((area) => ({
                                value: area,
                                label: AREA_LABELS[area] ?? area,
                            }))}
                            value={form.data.permissions}
                            onChange={(value) =>
                                form.setData('permissions', value)
                            }
                        />
                        {form.errors.permissions && (
                            <p className="text-sm text-destructive">
                                {form.errors.permissions}
                            </p>
                        )}
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
                            {login ? 'Save permissions' : 'Add login'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
