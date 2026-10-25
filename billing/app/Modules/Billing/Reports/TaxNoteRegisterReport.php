<?php

namespace App\Modules\Billing\Reports;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportQuery;
use App\Enums\ColumnType;

/**
 * The credit and debit note register, the monthly account of notes (vat-register §6.4): notes only, in their own
 * period, signed like the VAT register (a credit note is negative), with the reason, how the note was settled and
 * who issued it.
 */
class TaxNoteRegisterReport extends RegisterReport
{
    private const SETTLEMENT = [
        'credit_note' => 'Balance reduced',
        'debit_note' => 'Balance increased',
        'credit_note_to_credit' => 'Account credit',
    ];

    public function key(): string
    {
        return 'tax_note_register';
    }

    public function label(): string
    {
        return __('Credit and debit note register');
    }

    public function columns(ReportQuery $query): array
    {
        return [
            new ReportColumn('number', __('Number')),
            new ReportColumn('bs_date', __('Date (BS)')),
            new ReportColumn('ad_date', __('Date (AD)'), ColumnType::Date),
            new ReportColumn('type', __('Type')),
            new ReportColumn('invoice', __('Invoice')),
            new ReportColumn('invoice_bs_date', __('Invoice date (BS)')),
            new ReportColumn('buyer', __('Buyer')),
            new ReportColumn('pan', __('Buyer PAN')),
            new ReportColumn('reason_code', __('Reason code')),
            new ReportColumn('reason', __('Reason')),
            new ReportColumn('taxable', __('Taxable'), ColumnType::Money),
            new ReportColumn('exempt', __('Exempt'), ColumnType::Money),
            new ReportColumn('vat', __('VAT'), ColumnType::Money),
            new ReportColumn('total', __('Total'), ColumnType::Money),
            new ReportColumn('settlement', __('Settlement')),
            new ReportColumn('created_by', __('Issued by')),
        ];
    }

    protected function summaryKeys(): array
    {
        return ['number', 'bs_date', 'type', 'invoice', 'buyer', 'pan', 'taxable', 'exempt', 'vat', 'total'];
    }

    protected function notesOnly(): bool
    {
        return true;
    }

    protected function figures(array $figures): array
    {
        return ['taxable' => $figures['taxable'] + $figures['export_value']] + $figures;
    }

    protected function labelColumn(): string
    {
        return 'number';
    }

    protected function row(RegisterDocument $document): array
    {
        $sales = $document->totalSales();

        return [
            'number' => $document->number,
            'bs_date' => $document->bsDate,
            'ad_date' => $document->issueDate,
            'type' => self::text(RegisterDocument::KINDS[$document->kind]),
            'invoice' => $document->refNumber,
            'invoice_bs_date' => $document->refBsDate,
            'buyer' => $document->buyer,
            'pan' => $document->pan,
            'reason_code' => $document->reasonCode,
            'reason' => $document->reasonText,
            // Export value is shown as taxable here: the note register has no export column.
            'taxable' => $document->money($document->converted() ? (int) $document->taxable + (int) $document->exportValue : null),
            'exempt' => $document->money($document->exempt),
            'vat' => $document->money($document->tax),
            'total' => $document->money($sales === null ? null : $sales + (int) $document->tax),
            'settlement' => implode(', ', array_unique(array_map(fn (string $reason): string => self::text(self::SETTLEMENT[$reason] ?? ucfirst(str_replace('_', ' ', $reason))), $document->settlement))),
            'created_by' => $document->automatic ? self::text('Automatic') : $document->creator,
        ];
    }
}
