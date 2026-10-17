import { useForm } from '@inertiajs/react';
import { FormInput, MoreHorizontal, Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ProductCustomFieldController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/ProductCustomFieldController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { EmptyState } from '@/components/app/empty-state';
import { Field } from '@/components/app/field';
import { SectionCard } from '@/components/app/section-card';
import { SelectInput } from '@/components/catalog/select-input';
import { SwitchRow } from '@/components/catalog/switch-row';
import type { EnumOption } from '@/components/catalog/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

export type CustomField = {
    id: number;
    name: string;
    module_key: string | null;
    type: string;
    options: string[];
    description: string | null;
    validation_regex: string | null;
    is_required: boolean;
    is_admin_only: boolean;
    show_on_order: boolean;
    show_on_invoice: boolean;
    sort_order: number;
};

type CustomFieldsTabProps = {
    productId: number;
    fields: CustomField[];
    types: EnumOption[];
    canManage: boolean;
};

export function CustomFieldsTab({
    productId,
    fields,
    types,
    canManage,
}: CustomFieldsTabProps) {
    const [editing, setEditing] = useState<CustomField | 'new' | null>(null);
    const [deleting, setDeleting] = useState<CustomField | null>(null);
    const typeLabel = (type: string) =>
        types.find((option) => option.value === type)?.label ?? type;

    const columns: DataTableColumn<CustomField>[] = [
        {
            key: 'name',
            header: 'Field',
            primary: true,
            cell: (field) => (
                <span className="flex flex-col">
                    <span>{field.name}</span>
                    {field.module_key && (
                        <span className="font-mono text-xs font-normal text-muted-foreground">
                            {field.module_key}
                        </span>
                    )}
                </span>
            ),
        },
        { key: 'type', header: 'Type', cell: (field) => typeLabel(field.type) },
        {
            key: 'flags',
            header: 'Shown',
            cell: (field) => (
                <span className="flex flex-wrap gap-1">
                    {field.is_required && (
                        <Badge variant="outline">Required</Badge>
                    )}
                    {field.is_admin_only ? (
                        <Badge variant="secondary">Staff only</Badge>
                    ) : (
                        field.show_on_order && (
                            <Badge variant="outline">Order form</Badge>
                        )
                    )}
                    {field.show_on_invoice && (
                        <Badge variant="outline">Invoice</Badge>
                    )}
                    {field.validation_regex && (
                        <Badge variant="outline">Validated</Badge>
                    )}
                </span>
            ),
        },
    ];

    if (canManage) {
        columns.push({
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            align: 'right',
            hideOnMobile: true,
            className: 'w-12',
            cell: (field) => (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Actions for ${field.name}`}
                        >
                            <MoreHorizontal />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onSelect={() => setEditing(field)}>
                            Edit field
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => setDeleting(field)}
                        >
                            Delete field
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            ),
        });
    }

    return (
        <>
            <SectionCard
                title="Custom fields"
                description="Extra information collected per service. Clients fill them in when ordering; afterwards only staff can change them. Password values are stored encrypted."
                flush
                actions={
                    canManage && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setEditing('new')}
                        >
                            <Plus />
                            Add field
                        </Button>
                    )
                }
            >
                <DataTable
                    className="rounded-none border-0 shadow-none"
                    caption="Custom fields"
                    columns={columns}
                    rows={fields}
                    getRowKey={(field) => field.id}
                    empty={
                        <EmptyState
                            icon={FormInput}
                            title="No custom fields"
                            description="Add fields such as “Preferred username” or “Site type” to collect them on the order form."
                        />
                    }
                />
            </SectionCard>

            {editing && (
                <CustomFieldDialog
                    productId={productId}
                    field={editing === 'new' ? null : editing}
                    types={types}
                    onClose={() => setEditing(null)}
                />
            )}

            {deleting && (
                <ConfirmDialog
                    open
                    onOpenChange={(open) => !open && setDeleting(null)}
                    title={`Delete ${deleting.name}?`}
                    description="Values already entered for existing services are no longer shown."
                    confirmLabel="Delete field"
                    action={ProductCustomFieldController.destroy({
                        product: productId,
                        field: deleting.id,
                    })}
                    onSuccess={() => setDeleting(null)}
                />
            )}
        </>
    );
}

function CustomFieldDialog({
    productId,
    field,
    types,
    onClose,
}: {
    productId: number;
    field: CustomField | null;
    types: EnumOption[];
    onClose: () => void;
}) {
    const form = useForm({
        name: field?.name ?? '',
        module_key: field?.module_key ?? '',
        type: field?.type ?? 'text',
        options: (field?.options ?? []).join(', '),
        description: field?.description ?? '',
        validation_regex: field?.validation_regex ?? '',
        is_required: field?.is_required ?? false,
        is_admin_only: field?.is_admin_only ?? false,
        show_on_order: field?.show_on_order ?? true,
        show_on_invoice: field?.show_on_invoice ?? false,
        sort_order: String(field?.sort_order ?? 0),
    });
    const { data, setData, errors } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(
            field
                ? ProductCustomFieldController.update({
                      product: productId,
                      field: field.id,
                  })
                : ProductCustomFieldController.store(productId),
            { preserveScroll: true, onSuccess: onClose },
        );
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <form onSubmit={submit} className="grid gap-6">
                    <DialogHeader>
                        <DialogTitle>
                            {field ? `Edit ${field.name}` : 'Add custom field'}
                        </DialogTitle>
                        <DialogDescription>
                            Shown on the order form unless it is staff only.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            htmlFor="field-name"
                            label="Name"
                            error={errors.name}
                        >
                            <Input
                                id="field-name"
                                value={data.name}
                                onChange={(event) =>
                                    setData('name', event.target.value)
                                }
                                aria-invalid={!!errors.name}
                                aria-describedby="field-name-description"
                            />
                        </Field>
                        <Field
                            htmlFor="field-type"
                            label="Type"
                            error={errors.type}
                        >
                            <SelectInput
                                id="field-type"
                                value={data.type}
                                onChange={(value) => setData('type', value)}
                                options={types}
                            />
                        </Field>
                        {data.type === 'dropdown' && (
                            <Field
                                htmlFor="field-options"
                                label="Choices"
                                hint="Comma-separated"
                                error={errors.options}
                                className="sm:col-span-2"
                            >
                                <Input
                                    id="field-options"
                                    value={data.options}
                                    onChange={(event) =>
                                        setData('options', event.target.value)
                                    }
                                    aria-invalid={!!errors.options}
                                    aria-describedby="field-options-description"
                                />
                            </Field>
                        )}
                        <Field
                            htmlFor="field-module-key"
                            label="Driver key"
                            optional
                            hint="Passed to the provisioning driver."
                            error={errors.module_key}
                        >
                            <Input
                                id="field-module-key"
                                value={data.module_key}
                                onChange={(event) =>
                                    setData('module_key', event.target.value)
                                }
                                className="font-mono"
                                aria-describedby="field-module-key-description"
                            />
                        </Field>
                        <Field
                            htmlFor="field-regex"
                            label="Validation pattern"
                            optional
                            hint="Regular expression, e.g. /^[a-z0-9]+$/i"
                            error={errors.validation_regex}
                        >
                            <Input
                                id="field-regex"
                                value={data.validation_regex}
                                onChange={(event) =>
                                    setData(
                                        'validation_regex',
                                        event.target.value,
                                    )
                                }
                                className="font-mono"
                                aria-invalid={!!errors.validation_regex}
                                aria-describedby="field-regex-description"
                            />
                        </Field>
                        <Field
                            htmlFor="field-description"
                            label="Help text"
                            optional
                            error={errors.description}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="field-description"
                                value={data.description}
                                onChange={(event) =>
                                    setData('description', event.target.value)
                                }
                                aria-describedby="field-description-description"
                            />
                        </Field>
                        <div className="grid gap-2 sm:col-span-2">
                            <SwitchRow
                                id="field-required"
                                title="Required"
                                description="Clients must fill it in to order."
                                checked={data.is_required}
                                onCheckedChange={(checked) =>
                                    setData('is_required', checked)
                                }
                            />
                            {errors.is_required && (
                                <p className="text-sm text-red-600 dark:text-red-400">
                                    {errors.is_required}
                                </p>
                            )}
                            <SwitchRow
                                id="field-admin-only"
                                title="Staff only"
                                description="Hidden from clients."
                                checked={data.is_admin_only}
                                onCheckedChange={(checked) =>
                                    setData('is_admin_only', checked)
                                }
                            />
                            <SwitchRow
                                id="field-show-order"
                                title="Show on order form"
                                checked={data.show_on_order}
                                onCheckedChange={(checked) =>
                                    setData('show_on_order', checked)
                                }
                            />
                            <SwitchRow
                                id="field-show-invoice"
                                title="Show on invoice"
                                description="Appends “Name: value” to the service line."
                                checked={data.show_on_invoice}
                                onCheckedChange={(checked) =>
                                    setData('show_on_invoice', checked)
                                }
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {field ? 'Save field' : 'Add field'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
