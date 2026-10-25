@extends('billing::pdf.layout')

@section('title', $title.' '.$note->number)

@section('content')
@if ($copy)
    <div class="right" style="margin-bottom: 8px"><span class="stamp info">{{ $copy }}</span></div>
@endif
<table class="header">
    <tr>
        <td>
            <h1>{{ $title }}</h1>
            <div style="font-size: 13px; font-weight: bold">{{ $note->number }}</div>
            <div class="muted">{{ __('Issued') }} {{ $issued }}</div>
        </td>
        <td class="right">
            @if ($seller)
                <strong>{{ $seller['name'] }}</strong><br>
                @if ($seller['address'])
                    {!! nl2br(e($seller['address'])) !!}<br>
                @endif
                @if ($seller['email'] || $seller['phone'])
                    <span class="muted">{{ implode(' · ', array_filter([$seller['email'], $seller['phone']])) }}</span><br>
                @endif
                @foreach ($seller['tax_ids'] as $taxId)
                    {{ $taxId['label'] }}: {{ $taxId['value'] }}@if (! $loop->last)<br>@endif
                @endforeach
            @else
                <span class="muted">{{ __('Seller details were not recorded on the invoice.') }}</span>
            @endif
        </td>
    </tr>
</table>

<table class="header">
    <tr>
        <td class="party">
            <div class="label">{{ __('Issued to') }}</div>
            @if ($buyer)
                <strong>{{ $buyer['name'] }}</strong>
                @foreach (array_filter([$buyer['contact'], ...$buyer['address'], $buyer['email']]) as $line)
                    <br>{{ $line }}
                @endforeach
                @if ($buyer['tax_id'])
                    <br>{{ $buyer['tax_id']['label'] }}: {{ $buyer['tax_id']['value'] }}
                @endif
            @else
                <span class="muted">{{ __('No billing details were recorded on the invoice.') }}</span>
            @endif
        </td>
        <td class="party right">
            <div class="label">{{ __('Against invoice') }}</div>
            <strong>{{ $note->invoice_number }}</strong><br>
            {{ __('Dated') }} {{ $invoiceDate }}
        </td>
    </tr>
</table>

<div class="section">
    <div class="label">{{ __('Reason') }}</div>
    <strong>{{ $note->reasonLabel() }}</strong><br>
    {!! nl2br(e($note->reason_text)) !!}
</div>

<table class="lines">
    <thead>
        <tr>
            <th>{{ __('Description') }}</th>
            <th class="right">{{ __('Qty') }}</th>
            <th class="right">{{ __('Net') }}</th>
            <th class="right">{{ __('Tax') }}</th>
            <th class="right">{{ __('Amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($note->lines as $line)
            <tr>
                <td>{{ $line->description }}@unless ($line->taxable)<br><span class="muted">{{ __('Not taxed') }}</span>@endunless</td>
                <td class="right">{{ $line->quantity }}</td>
                <td class="right">{{ $money($line->amount) }}</td>
                <td class="right">{{ $money($line->tax1_amount + $line->tax2_amount) }}</td>
                <td class="right">{{ $money($line->amount + $line->tax1_amount + $line->tax2_amount) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>{{ __('Taxable value') }}</td><td class="right">{{ $money($note->taxable_amount) }}</td></tr>
    @if ($note->exempt_amount !== 0)
        <tr><td>{{ __('Exempt value') }}</td><td class="right">{{ $money($note->exempt_amount) }}</td></tr>
    @endif
    @foreach ($taxes as $tax)
        <tr><td>{{ $tax['label'] }}</td><td class="right">{{ $money($tax['amount']) }}</td></tr>
    @endforeach
    <tr class="grand"><td>{{ $note->kind === 'credit' ? __('Total credited') : __('Total debited') }}</td><td class="right">{{ $money($note->total) }}</td></tr>
    <tr><td colspan="2" class="right muted">{{ __('All amounts in :currency', ['currency' => $note->currency]) }}</td></tr>
</table>

<div class="section muted">
    {{ $note->kind === 'credit'
        ? __('This credit note lowers the value of invoice :number by the total above.', ['number' => $note->invoice_number])
        : __('This debit note raises the value of invoice :number by the total above.', ['number' => $note->invoice_number]) }}
</div>
@endsection
