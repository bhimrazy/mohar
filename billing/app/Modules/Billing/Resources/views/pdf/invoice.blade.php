@extends('billing::pdf.layout')

@php
    $money = fn (int $amount) => \App\Support\Money::of($amount, $invoice->currency)->format();
    $client = $invoice->client_snapshot ?? [];
@endphp

@section('title', __('Invoice :number', ['number' => $invoice->displayNumber()]))

@section('content')
<table class="header">
    <tr>
        <td>
            <h1>{{ $invoice->number === null && $invoice->proforma_number !== null ? __('Proforma invoice') : __('Invoice') }} {{ $invoice->displayNumber() }}</h1>
            <div class="muted">{{ __('Issued') }}: {{ $invoice->issue_date?->toDateString() ?? '—' }} · {{ __('Due') }}: {{ $invoice->due_date?->toDateString() ?? '—' }}</div>
            @if ($invoice->status->value === 'paid')
                <div style="margin-top: 8px"><span class="stamp">{{ __('Paid') }}</span></div>
            @elseif (in_array($invoice->status->value, ['cancelled', 'refunded', 'collections'], true))
                <div style="margin-top: 8px"><span class="stamp info">{{ $invoice->status->label() }}</span></div>
            @endif
        </td>
        <td class="right">
            <strong>{{ $company['name'] }}</strong><br>
            <span class="muted">{{ $company['email'] }}</span>
        </td>
    </tr>
</table>

<table class="header">
    <tr>
        <td>
            <div class="muted">{{ __('Bill to') }}</div>
            <strong>{{ $client['company'] ?? null ?: ($client['name'] ?? '') }}</strong><br>
            @if (! empty($client['company']))
                {{ $client['name'] ?? '' }}<br>
            @endif
            @foreach (['address_1', 'address_2'] as $line)
                @if (! empty($client[$line]))
                    {{ $client[$line] }}<br>
                @endif
            @endforeach
            {{ trim(($client['city'] ?? '').' '.($client['state'] ?? '').' '.($client['postcode'] ?? '')) }}<br>
            {{ $client['country'] ?? '' }}
            @if (! empty($client['tax_id']))
                <br>{{ __('Tax ID') }}: {{ $client['tax_id'] }}
            @endif
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr><th>{{ __('Description') }}</th><th class="right">{{ __('Qty') }}</th><th class="right">{{ __('Unit') }}</th><th class="right">{{ __('Amount') }}</th></tr>
    </thead>
    <tbody>
        @foreach ($invoice->items as $item)
            <tr @if ($item->cancelled_at) style="color: #9ca3af; text-decoration: line-through" @endif>
                <td>
                    {{ $item->description }}
                    @if ($item->period_start && $item->period_end)
                        <br><span class="muted">{{ $item->period_start->toDateString() }} – {{ $item->period_end->toDateString() }}</span>
                    @endif
                </td>
                <td class="right">{{ $item->quantity }}</td>
                <td class="right">{{ $money($item->unit_amount) }}</td>
                <td class="right">{{ $money($item->amount) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>{{ __('Subtotal') }}@if ($invoice->tax_inclusive) ({{ __('net') }})@endif</td><td class="right">{{ $money($invoice->subtotal) }}</td></tr>
    @if ($invoice->tax1_amount !== 0 || $invoice->tax1_name)
        <tr><td>{{ $invoice->tax1_name }} ({{ rtrim(rtrim($invoice->tax1_rate, '0'), '.') }}%)</td><td class="right">{{ $money($invoice->tax1_amount) }}</td></tr>
    @endif
    @if ($invoice->tax2_amount !== 0 || $invoice->tax2_name)
        <tr><td>{{ $invoice->tax2_name }} ({{ rtrim(rtrim($invoice->tax2_rate, '0'), '.') }}%)</td><td class="right">{{ $money($invoice->tax2_amount) }}</td></tr>
    @endif
    <tr class="grand"><td>{{ __('Total') }}</td><td class="right">{{ $money($invoice->total) }}</td></tr>
    @foreach ($invoice->adjustments as $adjustment)
        <tr><td class="muted">{{ $adjustment->description ?? str_replace('_', ' ', $adjustment->reason->value) }}</td><td class="right">{{ $adjustment->kind->value === 'credit' ? '−' : '+' }}{{ $money($adjustment->amount) }}</td></tr>
    @endforeach
    <tr><td>{{ __('Paid') }}</td><td class="right">−{{ $money($invoice->amount_paid) }}</td></tr>
    <tr class="grand"><td>{{ __('Balance due') }}</td><td class="right">{{ $money($invoice->balance) }}</td></tr>
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
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Gateway') }}</th><th>{{ __('Reference') }}</th><th class="right">{{ __('Amount') }}</th></tr></thead>
            <tbody>
                @foreach ($payments as $transaction)
                    <tr>
                        <td>{{ $transaction->occurred_at->toDateString() }}</td>
                        <td>{{ str_replace('_', ' ', $transaction->gateway) }}</td>
                        <td>{{ $transaction->gateway_txn_id ?? '—' }}</td>
                        <td class="right">{{ $transaction->amount_out > 0 ? '−'.$money($transaction->amount_out) : $money($transaction->amount_in) }}</td>
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
