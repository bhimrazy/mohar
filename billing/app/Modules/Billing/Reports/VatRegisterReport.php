<?php

namespace App\Modules\Billing\Reports;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportQuery;
use App\Enums\ColumnType;
use App\Support\Currencies\Decimal;

/**
 * The VAT sales register (vat-register §6.1, §6.2): every tax invoice, credit note and debit note dated in the period,
 * in the column order of §6.2. The `ird` layout writes the sales book (बिक्री खाता, VAT Rule 23(1), Schedule 9) in the
 * Schedule's column order, then our own columns (§7).
 */
class VatRegisterReport extends RegisterReport
{
    /**
     * Schedule 9's 13 columns as of the VAT Rules' 27th amendment, keyed by row key, with its Nepali headings: the 25th
     * amendment (2080) removed the goods and quantity columns, the 26th (2081) wrote "निर्यात" for "निकासी"
     * (docs/research/2026-10-31-nepal-vat-ebilling.md §12; headings as the research note gives them, not yet checked
     * against the IRD PDF). Where the Schedule groups two columns under one heading
     * (taxable sales: value and tax), the group heading leads the column's own.
     */
    public const IRD_COLUMNS = [
        'bs_date' => 'मिति',
        'number' => 'बीजक नम्बर',
        'buyer' => 'खरिदकर्ताको नाम',
        'pan' => 'खरिदकर्ताको स्थायी लेखा नम्बर',
        'total_sales' => 'जम्मा बिक्री/निर्यात मूल्य (रु)',
        'exempt' => 'स्थानीय कर छुटको बिक्री मूल्य (रु)',
        'taxable' => 'करयोग्य बिक्री मूल्य (रु)',
        'vat' => 'करयोग्य बिक्री कर (रु)',
        'export_value' => 'निर्यात गरेको वस्तु वा सेवाको मूल्य (रु)',
        'export_country' => 'निर्यात गरेको देश',
        'export_declaration_number' => 'निर्यात प्रज्ञापनपत्र नम्बर',
        'export_declaration_date' => 'निर्यात प्रज्ञापनपत्र मिति',
        'total' => 'कुल जम्मा',
    ];

    /** Our columns after the Schedule's (§6.2: 2, 3, 5 and 19). */
    private const IRD_EXTRA = ['ad_date', 'type', 'reference', 'reference_bs_date', 'currency', 'original_amount', 'rate'];

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
            new ReportColumn('total', __('Total'), ColumnType::Money),
            new ReportColumn('currency', __('Document currency')),
            new ReportColumn('original_amount', __('Document amount'), ColumnType::Money),
            new ReportColumn('rate', __('Rate'), ColumnType::Decimal),
        ];
    }

    protected function extraLayouts(): array
    {
        $columns = collect($this->columns(new ReportQuery([], null)))->keyBy('key');
        $types = ['total_sales' => ColumnType::Money, 'exempt' => ColumnType::Money, 'taxable' => ColumnType::Money, 'vat' => ColumnType::Money, 'export_value' => ColumnType::Money, 'total' => ColumnType::Money];
        $ird = [];

        foreach (self::IRD_COLUMNS as $key => $heading) {
            $ird[] = new ReportColumn($key, $heading, $types[$key] ?? ColumnType::Text);
        }

        foreach (self::IRD_EXTRA as $key) {
            $ird[] = $columns[$key];
        }

        return ['ird' => ['label' => __('IRD sales book'), 'columns' => $ird]];
    }

    protected function summaryKeys(): array
    {
        return ['bs_date', 'number', 'type', 'buyer', 'pan', 'total_sales', 'exempt', 'taxable', 'vat', 'export_value'];
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
        $sales = $document->totalSales();

        return [
            'bs_date' => $document->bsDate,
            'number' => $document->number,
            'buyer' => $document->buyer,
            'pan' => $document->pan,
            'goods' => $document->goods(),
            // Every line is a service (lines carry no unit yet), so the lines always share one unit (§6.2 col 9).
            'quantity' => $document->quantity(),
            'unit' => $document->lines === [] ? null : self::text('service'),
            'total_sales' => $document->money($sales),
            'exempt' => $document->money($document->exempt),
            'taxable' => $document->money($document->taxable),
            'vat' => $document->money($document->tax),
            'export_value' => $document->money($document->exportValue),
            'export_country' => $document->exportValue !== 0 ? $document->country : null,
            // Services have no export declaration (assumption A8); the IRD layout writes number and date apart.
            'export_declaration' => null,
            'export_declaration_number' => null,
            'export_declaration_date' => null,
            // Sales plus Nepal VAT, as the note register's total; another tax is not in it (#464 Q2).
            'total' => $document->money($sales === null ? null : $sales + (int) $document->tax),
            'ad_date' => $document->issueDate,
            'type' => self::text(RegisterDocument::KINDS[$document->kind]),
            'reference' => $document->refNumber,
            'reference_bs_date' => $document->refBsDate,
            // Columns 19 (§6.2): filled only for a document not in the register currency; the rate to 6 decimals.
            'currency' => $document->foreign() ? $document->currency : null,
            'original_amount' => $document->foreign() ? $document->original : null,
            'rate' => $document->foreign() && $document->rate !== null ? Decimal::round($document->rate, 6) : null,
        ];
    }
}
