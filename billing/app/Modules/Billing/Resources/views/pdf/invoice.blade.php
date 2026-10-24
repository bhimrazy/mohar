@extends('billing::pdf.layout')

@section('title', $title.' '.$invoice->displayNumber())

@section('content')
<table class="header">
    <tr>
        <td>
            <h1>{{ $title }}</h1>
            <div style="font-size: 13px; font-weight: bold">{{ $invoice->displayNumber() }}</div>
            @if ($bsDates)
                <div class="muted">{{ __('Issued') }} {{ $invoiceDate($invoice->issue_date) }}</div>
                <div class="muted">{{ __('Due') }} {{ $invoiceDate($invoice->due_date) }}</div>
            @else
                <div class="muted">{{ __('Issued') }} {{ $date($invoice->issue_date) }} · {{ __('Due') }} {{ $date($invoice->due_date) }}</div>
            @endif
            @if ($invoice->status->value === 'paid')
                <div style="margin-top: 8px"><span class="stamp">{{ __('Paid') }}</span></div>
            @elseif (in_array($invoice->status->value, ['cancelled', 'refunded', 'collections'], true))
                <div style="margin-top: 8px"><span class="stamp info">{{ $invoice->status->label() }}</span></div>
            @endif
        </td>
        <td class="right">
            <strong>{{ $seller['legal_name'] ?? '' }}</strong><br>
            @if (! empty($seller['address']))
                {!! nl2br(e($seller['address'])) !!}<br>
            @endif
            @if (! empty($seller['email']))
                <span class="muted">{{ $seller['email'] }}</span><br>
            @endif
            @foreach ($sellerTaxIds as $taxId)
                {{ $taxId['label'] }}: {{ $taxId['value'] }}@if (! $loop->last)<br>@endif
            @endforeach
        </td>
    </tr>
</table>

<table class="header">
    <tr>
        <td class="party">
            <div class="label">{{ __('Bill to') }}</div>
            <strong>{{ ($buyer['company'] ?? null) ?: ($buyer['name'] ?? '') }}</strong><br>
            @if (! empty($buyer['company']))
                {{ $buyer['name'] ?? '' }}<br>
            @endif
            @foreach (['address_1', 'address_2'] as $line)
                @if (! empty($buyer[$line]))
                    {{ $buyer[$line] }}<br>
                @endif
            @endforeach
            {{ trim(($buyer['city'] ?? '').' '.($buyer['state'] ?? '').' '.($buyer['postcode'] ?? '')) }}<br>
            {{ $countryName($buyer['country'] ?? null) }}
            @if (! empty($buyer['tax_id']))
                <br>{{ $buyerTaxLabel }}: {{ $buyer['tax_id'] }}
            @endif
        </td>
        <td class="party right">
            @if ($placeOfSupply)
                <div class="label">{{ __('Place of supply') }}</div>
                {{ $placeOfSupply }}<br>
            @endif
            @if ($invoice->payment_gateway)
                <div class="label" style="margin-top: 6px">{{ __('Payment method') }}</div>
                {{ $gatewayName($invoice->payment_gateway) }}
            @endif
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th>{{ __('Description') }}</th>
            @if ($showSac)
                <th>{{ __('SAC') }}</th>
            @endif
            <th class="right">{{ __('Qty') }}</th>
            <th class="right">{{ __('Unit price') }}</th>
            @if ($invoice->tax1_amount + $invoice->tax2_amount > 0)
                <th class="right">{{ __('Tax') }}</th>
            @endif
            <th class="right">{{ __('Amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($invoice->items as $item)
            <tr @if ($item->cancelled_at) style="color: #9ca3af; text-decoration: line-through" @endif>
                <td>
                    {{ $item->description }}
                    @if ($item->period_start && $item->period_end)
                        <br><span class="muted">{{ $date($item->period_start) }} – {{ $date($item->period_end) }}</span>
                    @endif
                </td>
                @if ($showSac)
                    <td>{{ $item->sac_code ?? '—' }}</td>
                @endif
                <td class="right">{{ $item->quantity }}</td>
                <td class="right">{{ $money($item->unit_amount) }}</td>
                @if ($invoice->tax1_amount + $invoice->tax2_amount > 0)
                    <td class="right">{{ $money($item->tax_amount) }}</td>
                @endif
                <td class="right">{{ $money($item->amount) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>{{ $invoice->tax_inclusive ? __('Taxable value') : __('Subtotal') }}</td><td class="right">{{ $money($invoice->subtotal) }}</td></tr>
    @if ($invoice->tax1_name)
        <tr><td>{{ $invoice->tax1_name }} @ {{ rtrim(rtrim($invoice->tax1_rate, '0'), '.') }}%</td><td class="right">{{ $money($invoice->tax1_amount) }}</td></tr>
    @endif
    @if ($invoice->tax2_name)
        <tr><td>{{ $invoice->tax2_name }} @ {{ rtrim(rtrim($invoice->tax2_rate, '0'), '.') }}%</td><td class="right">{{ $money($invoice->tax2_amount) }}</td></tr>
    @endif
    <tr class="grand"><td>{{ __('Total') }}</td><td class="right">{{ $money($invoice->total) }}</td></tr>
    @foreach ($invoice->adjustments as $adjustment)
        <tr><td class="muted">{{ $adjustment->description ?? str_replace('_', ' ', $adjustment->reason->value) }}</td><td class="right">{{ $adjustment->kind->value === 'credit' ? $money(-$adjustment->amount) : '+'.$money($adjustment->amount) }}</td></tr>
    @endforeach
    @if ($invoice->amount_paid !== 0)
        <tr><td>{{ __('Paid') }}</td><td class="right">{{ $money(-$invoice->amount_paid) }}</td></tr>
    @endif
    <tr class="grand"><td>{{ __('Balance due') }}</td><td class="right">{{ $money($invoice->balance) }}</td></tr>
    <tr><td colspan="2" class="right muted">{{ __('All amounts in :currency', ['currency' => $invoice->currency]) }}</td></tr>
</table>

@if ($invoice->tax_exempt_reason?->value === 'reverse_charge')
    <div class="section"><span class="stamp info">{{ __('Reverse charge') }}</span>
        <p class="muted">{{ __('VAT reverse charge: the recipient is liable to account for VAT.') }}</p></div>
@elseif ($invoice->tax_exempt_reason?->value === 'exempt')
    <div class="section muted">{{ __('Tax exempt.') }}</div>
@endif

@php $payments = $invoice->transactions->filter(fn ($transaction) => $transaction->amount_in > 0 || $transaction->amount_out > 0); @endphp
@if ($payments->isNotEmpty())
    <div class="section">
        <h2>{{ __('Transactions') }}</h2>
        <table class="lines">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Method') }}</th><th>{{ __('Reference') }}</th><th class="right">{{ __('Amount') }}</th></tr></thead>
            <tbody>
                @foreach ($payments as $transaction)
                    <tr>
                        <td>{{ $date($transaction->occurred_at) }}</td>
                        <td>{{ $gatewayName($transaction->gateway) }}</td>
                        <td>{{ $transaction->gateway_txn_id ?? '—' }}</td>
                        <td class="right">{{ $transaction->amount_out > 0 ? $money(-$transaction->amount_out) : $money($transaction->amount_in) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if ($invoice->notes)
    <div class="section"><h2>{{ __('Notes') }}</h2>{!! nl2br(e($invoice->notes)) !!}</div>
@endif
@endsection
