import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { Money } from '@/components/app/money';
import { cn } from '@/lib/utils';
import type { InvoiceItem } from './types';

type LineItemsProps = {
    items: InvoiceItem[];
    currency: string;
    /** Shown in a leading column, e.g. a checkbox for "cancel selected lines". */
    select?: (item: InvoiceItem) => React.ReactNode;
};

export function LineItems({ items, currency, select }: LineItemsProps) {
    const columns: DataTableColumn<InvoiceItem>[] = [
        ...(select
            ? [
                  {
                      key: 'select',
                      header: <span className="sr-only">Select</span>,
                      className: 'w-8',
                      hideOnMobile: true,
                      cell: select,
                  } satisfies DataTableColumn<InvoiceItem>,
              ]
            : []),
        {
            key: 'description',
            header: 'Description',
            primary: true,
            cell: (item) => (
                <div className={cn(item.cancelled && 'text-muted-foreground line-through')}>
                    <div className="whitespace-normal">{item.description}</div>
                    {item.period_start && item.period_end && (
                        <div className="text-xs text-muted-foreground">
                            <DateTime value={item.period_start} /> –{' '}
                            <DateTime value={item.period_end} />
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'quantity',
            header: 'Qty',
            align: 'right',
            className: 'w-16',
            cell: (item) => <span className="tabular-nums">{item.quantity}</span>,
        },
        {
            key: 'unit',
            header: 'Unit price',
            align: 'right',
            hideOnMobile: true,
            cell: (item) => (
                <Money amount={item.unit_amount} currency={currency} />
            ),
        },
        {
            key: 'tax',
            header: 'Tax',
            align: 'right',
            hideOnMobile: true,
            cell: (item) =>
                item.taxable ? (
                    <Money amount={item.tax_amount} currency={currency} />
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            cell: (item) => <Money amount={item.amount} currency={currency} />,
        },
    ];

    return (
        <DataTable
            caption="Line items"
            columns={columns}
            rows={items}
            getRowKey={(item) => item.id}
            className="rounded-none border-0 shadow-none"
        />
    );
}
