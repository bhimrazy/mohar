import { Head, useForm } from '@inertiajs/react';
import StaffSubscriptionController from '@/actions/App/Modules/Notifications/Http/Controllers/Admin/StaffSubscriptionController';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type Role = {
    id: number;
    name: string;
    users_count: number;
    is_administrator: boolean;
    customised: boolean;
    categories: string[];
};

type Category = { value: string; label: string; description: string };

export default function StaffSubscriptions({
    roles,
    categories,
}: {
    roles: Role[];
    categories: Category[];
}) {
    const form = useForm({
        roles: roles.map((role) => ({
            id: role.id,
            categories: role.categories,
        })),
    });

    const toggle = (roleId: number, category: string, checked: boolean) =>
        form.setData(
            'roles',
            form.data.roles.map((role) =>
                role.id !== roleId
                    ? role
                    : {
                          ...role,
                          categories: checked
                              ? [...new Set([...role.categories, category])]
                              : role.categories.filter(
                                    (value) => value !== category,
                                ),
                      },
            ),
        );

    return (
        <>
            <Head title="Staff email alerts" />
            <Page>
                <PageHeader
                    title="Staff email alerts"
                    description="Choose which roles receive each kind of staff email. Support alerts only go to staff who can view tickets; assignments and mentions always reach the person concerned."
                />
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.submit(StaffSubscriptionController.update(), {
                            preserveScroll: true,
                        });
                    }}
                    className="flex flex-col gap-6"
                >
                    <SectionCard flush>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Role</TableHead>
                                    {categories.map((category) => (
                                        <TableHead
                                            key={category.value}
                                            className="text-center"
                                        >
                                            <div>{category.label}</div>
                                            <div className="hidden max-w-56 text-xs font-normal whitespace-normal text-muted-foreground lg:block">
                                                {category.description}
                                            </div>
                                        </TableHead>
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {roles.map((role) => {
                                    const selected =
                                        form.data.roles.find(
                                            (row) => row.id === role.id,
                                        )?.categories ?? [];

                                    return (
                                        <TableRow key={role.id}>
                                            <TableCell>
                                                <div className="flex items-center gap-2 font-medium">
                                                    {role.name}
                                                    {role.is_administrator && (
                                                        <Badge variant="outline">
                                                            Full access
                                                        </Badge>
                                                    )}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {role.users_count} staff
                                                </div>
                                            </TableCell>
                                            {categories.map((category) => (
                                                <TableCell
                                                    key={category.value}
                                                    className="text-center"
                                                >
                                                    <Checkbox
                                                        aria-label={`${role.name}: ${category.label}`}
                                                        checked={selected.includes(
                                                            category.value,
                                                        )}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            toggle(
                                                                role.id,
                                                                category.value,
                                                                checked ===
                                                                    true,
                                                            )
                                                        }
                                                    />
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </SectionCard>
                    <FormActions>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Save alerts
                        </Button>
                    </FormActions>
                </form>
            </Page>
        </>
    );
}
