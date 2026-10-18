import { Link, router, usePage } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { CheckCircle2 } from 'lucide-react';
import { DataTable } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { useQueryParams } from '@/components/app/use-query-params';
import {
    commandLabel,
    ModuleLogTable,
} from '@/components/services/module-log-table';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import ModuleLogController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ModuleLogController';
import ServiceController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServiceController';
import type {
    LaravelPaginated,
    ResourcePaginated,
    SelectOption,
} from '@/types/app';
import type { ModuleCommandRow } from '@/types/services';

type QueueRow = {
    id: number;
    service_id: number;
    service_label: string | null;
    command: string;
    last_error: string | null;
    attempts: number;
    status: string;
    updated_at: string | null;
    can_check: boolean;
    lookup_status: string | null;
    lookup_message: string | null;
    looked_up_at: string | null;
    retry_blocked: string | null;
};

const lookupLabels: Record<string, string> = {
    executed: 'Ran on the server',
    not_executed: 'Not on the server',
    in_progress: 'Still in progress on the server',
    conflict: 'Conflict on the server',
    unknown: 'Server check failed',
};

type Props = {
    tab: 'log' | 'queue';
    commands: SelectOption[];
    openCount: number;
    log: ResourcePaginated<ModuleCommandRow> | null;
    queue: LaravelPaginated<QueueRow> | null;
    canManage: boolean;
};

export default function ModuleLogIndex({
    tab,
    commands,
    openCount,
    log,
    queue,
    canManage,
}: Props) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const { apply } = useQueryParams();
    const post = (url: string) =>
        router.post(url, {}, { preserveScroll: true });

    return (
        <>
            <Head title="Module log" />
            <Page>
                <PageHeader
                    title="Module log"
                    description="Every provisioning call with its redacted request and response, and the actions that failed after all retries."
                />

                <Tabs
                    value={tab}
                    onValueChange={(value) =>
                        apply({
                            tab: value === 'queue' ? 'queue' : null,
                            result: null,
                            command: null,
                            search: null,
                        })
                    }
                >
                    <TabsList>
                        <TabsTrigger value="log">All actions</TabsTrigger>
                        <TabsTrigger value="queue">
                            Failed actions
                            {openCount > 0 && (
                                <span className="ml-1.5 rounded-full bg-destructive/10 px-1.5 text-xs text-destructive tabular-nums">
                                    {openCount}
                                </span>
                            )}
                        </TabsTrigger>
                    </TabsList>
                </Tabs>

                {errors.action && (
                    <Alert variant="destructive">
                        <AlertDescription>{errors.action}</AlertDescription>
                    </Alert>
                )}

                {log && (
                    <>
                        <FilterBar
                            searchPlaceholder="Search domain or username…"
                            filterKeys={['result', 'command']}
                            filters={
                                <>
                                    <FilterSelect
                                        name="result"
                                        label="Results"
                                        options={[
                                            {
                                                value: 'success',
                                                label: 'Succeeded',
                                            },
                                            {
                                                value: 'failed',
                                                label: 'Failed',
                                            },
                                        ]}
                                    />
                                    <FilterSelect
                                        name="command"
                                        label="Actions"
                                        options={commands}
                                    />
                                </>
                            }
                        />
                        <ModuleLogTable
                            entries={log.data}
                            showService
                            emptyDescription="Driver calls appear here as services are created, suspended and changed."
                            footer={
                                <Pagination
                                    paginator={log}
                                    itemLabel="entries"
                                />
                            }
                        />
                    </>
                )}

                {queue && (
                    <DataTable
                        caption="Failed actions"
                        columns={[
                            {
                                key: 'command',
                                header: 'Action',
                                primary: true,
                                cell: (row) => (
                                    <span className="flex flex-col">
                                        <span>{commandLabel(row.command)}</span>
                                        <span className="text-xs font-normal text-muted-foreground">
                                            {row.attempts} attempt
                                            {row.attempts === 1 ? '' : 's'}
                                        </span>
                                    </span>
                                ),
                            },
                            {
                                key: 'service',
                                header: 'Service',
                                cell: (row) => (
                                    <Link
                                        href={ServiceController.show(
                                            row.service_id,
                                        )}
                                        className="hover:underline"
                                    >
                                        {row.service_label ??
                                            `#${row.service_id}`}
                                    </Link>
                                ),
                            },
                            {
                                key: 'error',
                                header: 'Last error',
                                cell: (row) => (
                                    <span className="block max-w-md text-sm whitespace-normal text-muted-foreground">
                                        {row.last_error}
                                        {row.lookup_status && (
                                            <span className="mt-1 block text-foreground">
                                                {lookupLabels[
                                                    row.lookup_status
                                                ] ?? row.lookup_status}
                                                {row.lookup_message &&
                                                    `: ${row.lookup_message}`}{' '}
                                                <span className="text-muted-foreground">
                                                    (checked{' '}
                                                    <DateTime
                                                        value={row.looked_up_at}
                                                        relative
                                                    />
                                                    )
                                                </span>
                                            </span>
                                        )}
                                        {row.retry_blocked && (
                                            <span className="mt-1 block">
                                                {row.retry_blocked}
                                            </span>
                                        )}
                                    </span>
                                ),
                            },
                            {
                                key: 'at',
                                header: 'Last attempt',
                                cell: (row) => (
                                    <DateTime value={row.updated_at} relative />
                                ),
                            },
                            ...(canManage
                                ? [
                                      {
                                          key: 'actions',
                                          header: (
                                              <span className="sr-only">
                                                  Actions
                                              </span>
                                          ),
                                          align: 'right' as const,
                                          cell: (row: QueueRow) => (
                                              <span className="ml-auto flex max-w-64 flex-wrap justify-end gap-2">
                                                  <Button
                                                      size="sm"
                                                      variant="ghost"
                                                      onClick={() =>
                                                          post(
                                                              ModuleLogController.dismiss(
                                                                  row.id,
                                                              ).url,
                                                          )
                                                      }
                                                  >
                                                      Dismiss
                                                  </Button>
                                                  <Button
                                                      size="sm"
                                                      variant="outline"
                                                      onClick={() =>
                                                          post(
                                                              ModuleLogController.resolve(
                                                                  row.id,
                                                              ).url,
                                                          )
                                                      }
                                                  >
                                                      Mark resolved
                                                  </Button>
                                                  {row.can_check && (
                                                      <Button
                                                          size="sm"
                                                          variant="outline"
                                                          onClick={() =>
                                                              post(
                                                                  ModuleLogController.check(
                                                                      row.id,
                                                                  ).url,
                                                              )
                                                          }
                                                      >
                                                          Check now
                                                      </Button>
                                                  )}
                                                  <Button
                                                      size="sm"
                                                      disabled={
                                                          row.retry_blocked !==
                                                          null
                                                      }
                                                      title={
                                                          row.retry_blocked ??
                                                          undefined
                                                      }
                                                      onClick={() =>
                                                          post(
                                                              ModuleLogController.retry(
                                                                  row.id,
                                                              ).url,
                                                          )
                                                      }
                                                  >
                                                      {row.can_check &&
                                                      row.command === 'create'
                                                          ? 'Create again'
                                                          : 'Retry'}
                                                  </Button>
                                              </span>
                                          ),
                                      },
                                  ]
                                : []),
                        ]}
                        rows={queue.data}
                        getRowKey={(row) => row.id}
                        empty={
                            <EmptyState
                                icon={CheckCircle2}
                                title="No failed actions"
                                description="Actions that still fail after all retries wait here for staff."
                            />
                        }
                        footer={
                            <Pagination
                                paginator={queue}
                                itemLabel="failed actions"
                            />
                        }
                    />
                )}
            </Page>
        </>
    );
}
