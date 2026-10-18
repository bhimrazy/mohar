@extends('billing::pdf.layout')


@section('title', __('Quote :number', ['number' => $quote->displayNumber()]))

@section('content')
<table class="header">
    <tr>
        <td>
            <h1>{{ __('Quote') }} {{ $quote->displayNumber() }}</h1>
            <div class="muted">{{ $quote->subject }}</div>
            <div class="muted">{{ __('Valid until') }}: {{ $quote->valid_until?->toDateString() ?? '—' }}</div>
        </td>
        <td class="right"><strong>{{ $company['name'] }}</strong><br><span class="muted">{{ $company['email'] }}</span></td>
    </tr>
</table>
<div>{{ __('Prepared for') }}: <strong>{{ $quote->client->display_name }}</strong></div>
@if ($quote->proposal_text)
    <div class="section">{!! nl2br(e($quote->proposal_text)) !!}</div>
@endif
<table class="lines">
    <thead><tr><th>{{ __('Description') }}</th><th class="right">{{ __('Qty') }}</th><th class="right">{{ __('Unit') }}</th><th class="right">{{ __('Discount') }}</th><th class="right">{{ __('Amount') }}</th></tr></thead>
    <tbody>
        @foreach ($quote->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="right">{{ $item->quantity }}</td>
                <td class="right">{{ $money($item->unit_amount) }}</td>
                <td class="right">{{ (float) $item->discount_percent > 0 ? rtrim(rtrim($item->discount_percent, '0'), '.').'%' : '—' }}</td>
                <td class="right">{{ $money($item->amount) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
<table class="totals">
    <tr><td>{{ __('Subtotal') }}</td><td class="right">{{ $money($quote->subtotal) }}</td></tr>
    @if ($quote->tax1_amount !== 0)
        <tr><td>{{ $quote->tax1_name }}</td><td class="right">{{ $money($quote->tax1_amount) }}</td></tr>
    @endif
    @if ($quote->tax2_amount !== 0)
        <tr><td>{{ $quote->tax2_name }}</td><td class="right">{{ $money($quote->tax2_amount) }}</td></tr>
    @endif
    <tr class="grand"><td>{{ __('Total') }}</td><td class="right">{{ $money($quote->total) }}</td></tr>
    <tr><td colspan="2" class="right muted">{{ __('All amounts in :currency', ['currency' => $quote->currency]) }}</td></tr>
</table>
@if ($quote->customer_notes)
    <div class="section"><h2>{{ __('Notes') }}</h2>{!! nl2br(e($quote->customer_notes)) !!}</div>
@endif
@endsection
