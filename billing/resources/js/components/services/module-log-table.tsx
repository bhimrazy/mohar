import { Link } from '@inertiajs/react';
import { Activity } from 'lucide-react';
import type { ReactNode } from 'react';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { StatusBadge } from '@/components/app/status-badge';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import ServiceController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServiceController';
import type { ModuleCommandRow } from '@/types/services';

const COMMAND_LABELS: Record<string, string> = {
    create: 'Create',
    suspend: 'Suspend',
    unsuspend: 'Unsuspend',
    terminate: 'Terminate',
    change_package: 'Change package',
    change_password: 'Change password',
};

export function commandLabel(command: string): string {
    return COMMAND_LABELS[command] ?? command;
}

function Payload({ entry }: { entry: ModuleCommandRow }) {
    if (!entry.request && !entry.response) {
        return null;
    }

    return (
        <Collapsible>
            <CollapsibleTrigger className="text-xs text-muted-foreground underline-offset-2 hover:underline">
                Request / response
            </CollapsibleTrigger>
            <CollapsibleContent>
                <pre className="mt-2 max-h-64 max-w-xl overflow-auto rounded-md border bg-muted/40 p-2 text-xs">
                    {JSON.stringify(
                        { request: entry.request, response: entry.response },
                        null,
                        2,
                    )}
                </pre>
            </CollapsibleContent>
        </Collapsible>
    );
}

type ModuleLogTableProps = {
    entries: ModuleCommandRow[];
    showService?: boolean;
    footer?: ReactNode;
    emptyDescription?: string;
};

export function ModuleLogTable({
    entries,
    showService = false,
    footer,
    emptyDescription = 'Driver calls for this service appear here.',
}: ModuleLogTableProps) {
    const columns: DataTableColumn<ModuleCommandRow>[] = [
        {
            key: 'command',
            header: 'Action',
            primary: true,
            cell: (entry) => (
                <span className="flex flex-col gap-0.5">
                    <span>
                        {commandLabel(entry.command)}
                        {entry.attempt > 1 && (
                            <span className="ml-1.5 text-xs font-normal text-muted-foreground">
                                attempt {entry.attempt}
                            </span>
                        )}
                    </span>
                    <span className="text-xs font-normal text-muted-foreground">
                        {entry.driver} · {entry.initiator}
                    </span>
                </span>
            ),
        },
        ...(showService
            ? [
                  {
                      key: 'service',
                      header: 'Service',
                      cell: (entry: ModuleCommandRow) =>
                          entry.service_id ? (
                              <Link
                                  href={ServiceController.show(
                                      entry.service_id,
                                  )}
                                  className="hover:underline"
                              >
                                  {entry.service_label ??
                                      `#${entry.service_id}`}
                              </Link>
                          ) : (
                              '—'
                          ),
                  },
              ]
            : []),
        {
            key: 'result',
            header: 'Result',
            cell: (entry) => (
                <div className="space-y-1">
                    <StatusBadge
                        status={entry.success ? 'success' : 'failed'}
                        tone={entry.success ? 'success' : 'danger'}
                        label={entry.success ? 'Succeeded' : 'Failed'}
                    />
                    {entry.error && (
                        <p className="max-w-md text-xs whitespace-normal text-muted-foreground">
                            {entry.error}
                        </p>
                    )}
                    <Payload entry={entry} />
                </div>
            ),
        },
        {
            key: 'duration',
            header: 'Duration',
            align: 'right',
            hideOnMobile: true,
            cell: (entry) => (
                <span className="text-muted-foreground tabular-nums">
                    {entry.duration_ms} ms
                </span>
            ),
        },
        {
            key: 'at',
            header: 'When',
            align: 'right',
            cell: (entry) => <DateTime value={entry.created_at} relative />,
        },
    ];

    return (
        <DataTable
            caption="Module log"
            columns={columns}
            rows={entries}
            getRowKey={(entry) => entry.id}
            empty={
                <EmptyState
                    icon={Activity}
                    title="No module actions yet"
                    description={emptyDescription}
                />
            }
            footer={footer}
        />
    );
}
