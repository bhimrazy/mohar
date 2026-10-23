<?php

namespace App\Modules\Billing\Reports;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportQuery;
use App\Enums\ColumnType;
use App\Support\Currencies\Decimal;

/**
 * The VAT sales register (vat-register §6.1, §6.2): every tax invoice, credit note and debit note dated in the period,
 * in the column order of §6.2. The `detail` and `ird` layouts (the template's order and headings) and the PDF come
 * with VAT PR 7.
 */
class VatRegisterReport extends RegisterReport
{
    public function key(): string
    {
        return 'vat_register';
    }

    public function label(): string
    {
        return __('VAT sales register');
    }

    public function columns(ReportQuery $query): array
    {
        return [
            new ReportColumn('bs_date', __('Date (BS)')),
            new ReportColumn('ad_date', __('Date (AD)'), ColumnType::Date),
            new ReportColumn('type', __('Document')),
            new ReportColumn('number', __('Number')),
            new ReportColumn('reference', __('Reference invoice')),
            new ReportColumn('reference_bs_date', __('Reference date (BS)')),
            new ReportColumn('buyer', __('Buyer')),
            new ReportColumn('pan', __('Buyer PAN')),
            new ReportColumn('goods', __('Goods or service')),
            new ReportColumn('quantity', __('Quantity'), ColumnType::Integer),
            new ReportColumn('unit', __('Unit')),
            new ReportColumn('total_sales', __('Total sales'), ColumnType::Money),
            new ReportColumn('exempt', __('Exempt sales'), ColumnType::Money),
            new ReportColumn('taxable', __('Taxable sales'), ColumnType::Money),
            new ReportColumn('vat', __('VAT'), ColumnType::Money),
            new ReportColumn('export_value', __('Export value'), ColumnType::Money),
            new ReportColumn('export_country', __('Export country')),
            new ReportColumn('export_declaration', __('Export declaration')),
            new ReportColumn('currency', __('Document currency')),
            new ReportColumn('original_amount', __('Document amount'), ColumnType::Money),
            new ReportColumn('rate', __('Rate'), ColumnType::Decimal),
        ];
    }

    protected function notesOnly(): bool
    {
        return false;
    }

    protected function labelColumn(): string
    {
        return 'bs_date';
    }

    protected function row(RegisterDocument $document): array
    {
        return [
            'bs_date' => $document->bsDate,
            'number' => $document->number,
            'buyer' => $document->buyer,
            'pan' => $document->pan,
            'goods' => $document->goods(),
            // Every line is a service (lines carry no unit yet), so the lines always share one unit (§6.2 col 9).
            'quantity' => $document->quantity(),
            'unit' => $document->lines === [] ? null : self::text('service'),
            'total_sales' => $document->money($document->totalSales()),
            'exempt' => $document->money($document->exempt),
            'taxable' => $document->money($document->taxable),
            'vat' => $document->money($document->tax),
            'export_value' => $document->money($document->exportValue),
            'export_country' => $document->exportValue !== 0 ? $document->country : null,
            'export_declaration' => null,
            'ad_date' => $document->issueDate,
            'type' => self::text(RegisterDocument::KINDS[$document->kind]),
            'reference' => $document->refNumber,
            'reference_bs_date' => $document->refBsDate,
            // Columns 18 (§6.2): filled only for a document not in the register currency; the rate to 6 decimals.
            'currency' => $document->foreign() ? $document->currency : null,
            'original_amount' => $document->foreign() ? $document->original : null,
            'rate' => $document->foreign() && $document->rate !== null ? Decimal::round($document->rate, 6) : null,
        ];
    }
}
