{{--
    Direction A, "two-card receipt" (docs/design/email/email-design.md §3, §5): the brand above two cards. Card 1 is
    the summary (label, amount or title, status, opening, one button, downloads, key facts); card 2 the record (line
    items and totals, then the notes). The footer follows. Every email uses it: Notifications templates (body already
    final HTML, `raw`, with a system-computed `frame`) and framework notifications (Markdown slot, no frame).
    Brand data comes from BrandProvider; colours from MailPalette.
--}}
@props(['preheader' => null, 'preferencesUrl' => null, 'raw' => false, 'frame' => null, 'supportEmail' => null, 'subject' => null, 'actionToken' => null, 'notes' => null])
@php
    $palette = \App\Modules\Notifications\Mail\MailPalette::current();
    $l = $palette->light;
    $frame ??= new \App\Modules\Notifications\Mail\MailFrame;
    // Buttons come only from the template's own action lines (marked with $actionToken) or, in framework mail, from
    // its <x-mail::button>; never from a link in merged or client content.
    $parts = \App\Modules\Notifications\Support\EmailBody::parts($raw ? (string) $slot : (string) Illuminate\Mail\Markdown::parse((string) $slot), $actionToken, actionTables: ! $raw);
    $parts['notes'] = trim($parts['notes']."\n".(string) $notes);
    $systemAction = isset($action) && trim((string) $action) !== '' ? (string) $action : null;
    // A system button (ticket link) is the action; a link the template adds next to it becomes the quiet link.
    $secondary = $systemAction !== null ? ($parts['action'] ?? $parts['secondary']) : $parts['secondary'];
    $primary = $systemAction ?? ($parts['action'] !== null ? \App\Modules\Notifications\Support\EmailBody::button($parts['action']['url'], $parts['action']['label']) : null);
    $facts = [...$parts['facts'], ...array_map(fn (array $row): array => [e($row[0]), e($row[1])], $frame->facts)];
    $heroTitle = $frame->amount === null ? $parts['title'] : null;
    $recordTitle = $frame->amount !== null ? $parts['title'] : null;
    $hasRecord = $frame->lines !== null || trim($parts['notes']) !== '';
    $links = [...$frame->downloads, ...($secondary !== null ? [$secondary] : [])];
    $tone = $frame->statusTone ?? 'accent';
    $toneLight = $palette->tone($tone);
    $footer = \App\Modules\Notifications\Mail\MailFooter::make($preferencesUrl, $supportEmail, $frame);

    $brandProvider = app(\App\Contracts\Branding\BrandProvider::class);
    $brand = $brandProvider->brand();
    // The host's brand only (product standards 2026-10-18): its opt-in email logo (opaque, so it survives colour
    // inversion), else its uploaded mark beside the name as live text, else the name alone. Never the product's mark.
    $logo = $brandProvider->emailLogoUrl();
    $mark = $brand->emailMark;

    $text = fn (string $colour, string $size = '16px', string $line = '24px'): string => "margin:0;font-size:{$size};line-height:{$line};color:{$colour};";
    $card = 'width:100%;background-color:'.$l['card'].';border-radius:16px;border-collapse:separate;';
    $hairline = '<tr><td class="px pxa" style="padding:0 24px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="bd" style="border-top:1px solid '.$l['border'].';font-size:0;line-height:0;height:1px;">&nbsp;</td></tr></table></td></tr>';
@endphp
<x-mail::layout :preheader="$preheader" :subject="$subject" :palette="$palette">
{{-- Brand --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:0 0 20px;">
@if ($logo !== null)
<img class="brand-logo" src="{{ $logo }}" alt="{{ $brand->name }}" height="40" style="display:block;height:40px;width:auto;max-width:240px;border:0;color:{{ $l['strong'] }};font-size:18px;font-weight:700;line-height:40px;">
@else
<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
@if ($mark !== null)
<td valign="middle" style="padding-right:10px;"><img class="brand-logo" src="{{ $mark }}" width="28" height="28" alt="" style="display:block;width:28px;height:28px;border:0;border-radius:6px;"></td>
@endif
<td valign="middle" class="tx-strong brand-name" style="font-size:18px;line-height:28px;font-weight:700;letter-spacing:-.01em;color:{{ $l['strong'] }};">{{ $brand->name }}</td>
</tr></table>
@endif
</td></tr></table>

{{-- Card 1: summary --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg-card bd" style="{{ $card }}">
<tr><td class="px pxa" style="padding:40px 24px 24px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td valign="top">
@if (filled($frame->label))
<p class="tx-muted" style="{{ $text($l['muted'], '15px', '22px') }}">{{ $frame->label }}</p>
@endif
@if ($frame->amount !== null)
<p class="tx-strong amount amt-a" style="margin:6px 0;font-size:36px;line-height:42px;font-weight:700;letter-spacing:-.025em;color:{{ $l['strong'] }};font-variant-numeric:tabular-nums;">{{ $frame->amount }}</p>
@elseif (filled($heroTitle))
<h1 class="tx-strong h1" style="margin:6px 0 8px;font-size:30px;line-height:36px;font-weight:700;letter-spacing:-.015em;color:{{ $l['strong'] }};overflow-wrap:anywhere;word-break:break-word;">{{ $heroTitle }}</h1>
@endif
@if ($frame->hasStatus())
<p style="{{ $text($l['body']) }}"><span class="dotc-{{ $tone }}" aria-hidden="true" style="color:{{ $toneLight['dot'] }};font-size:13px;">&#9679;</span>&nbsp;<span class="tx-{{ $tone }}" style="color:{{ $toneLight['text'] }};font-weight:700;">{{ $frame->statusLabel }}</span>@if (filled($frame->statusWhen))<span class="tx-muted" style="color:{{ $l['muted'] }};"> · {{ $frame->statusWhen }}</span>@endif</p>
@endif
</td>
@if ($frame->document)
<td class="hide-sm thumb" valign="top" align="right" width="56" style="display:none;mso-hide:all;width:56px;padding-left:16px;"><img src="{{ asset('brand/email/doc-light.png') }}" width="56" height="70" alt="" class="brand-logo logo-l" style="display:block;width:56px;height:70px;border:0;"><!--[if !mso]><!--><div class="logo-d" style="display:none;max-height:0;overflow:hidden;mso-hide:all;"><img class="brand-logo" src="{{ asset('brand/email/doc-dark.png') }}" width="56" height="70" alt="" style="display:block;width:56px;height:70px;border:0;"></div><!--<![endif]--></td>
@endif
</tr></table></td></tr>
@if (filled($recordTitle) && ! $hasRecord)
<tr><td class="px pxa" style="padding:0 24px 16px;"><h2 class="tx-strong" style="margin:0;font-size:18px;line-height:26px;font-weight:700;color:{{ $l['strong'] }};">{{ $recordTitle }}</h2></td></tr>
@endif
@if (trim($parts['intro']) !== '')
<tr><td class="px pxa prose" style="padding:0 24px 8px;">
{!! $parts['intro'] !!}
</td></tr>
@endif
@if ($primary !== null)
<tr><td class="px pxa" style="padding:8px 24px 24px;">
{!! $primary !!}
</td></tr>
@endif
@if ($links !== [])
{!! $hairline !!}
<tr><td class="px pxa" style="padding:16px 24px 4px;"><p style="margin:0;">
@foreach ($links as $link)
<a href="{{ $link['url'] }}" class="link" target="_blank" rel="noopener" style="color:{{ $l['link'] }};font-size:15px;line-height:26px;font-weight:600;text-decoration:none;white-space:nowrap;"><span aria-hidden="true">@if ($loop->index < count($frame->downloads))&darr;@else&rarr;@endif&nbsp;</span><span style="text-decoration:underline;">{{ $link['label'] }}</span></a>@if (! $loop->last)<span class="tx-muted" aria-hidden="true" style="color:{{ $l['muted'] }};">&nbsp;&nbsp;&nbsp;</span>@endif
@endforeach
</p></td></tr>
@endif
@if ($facts !== [])
<tr><td class="px pxa" style="padding:12px 24px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
@foreach ($facts as [$key, $value])
<tr>
<td class="tx-muted f-k" valign="top" style="padding:8px 12px 8px 0;font-size:15px;line-height:22px;color:{{ $l['muted'] }};">{!! $key !!}</td>
<td class="tx-strong f-v" valign="top" align="right" style="padding:8px 0;font-size:15px;line-height:22px;color:{{ $l['strong'] }};font-weight:600;text-align:right;overflow-wrap:anywhere;word-break:break-word;font-variant-numeric:tabular-nums;">{!! $value !!}</td>
</tr>
@endforeach
</table></td></tr>
@endif
@isset($subcopy)
<tr><td class="px pxa tx-muted subcopy" style="padding:16px 24px 0;font-size:13px;line-height:20px;color:{{ $l['muted'] }};overflow-wrap:anywhere;word-break:break-word;">{!! Illuminate\Mail\Markdown::parse((string) $subcopy) !!}</td></tr>
@endisset
<tr><td style="padding:0 0 32px;font-size:0;line-height:0;">&nbsp;</td></tr>
</table>

@if ($hasRecord)
{{-- Card 2: the record --}}
<div style="height:16px;line-height:16px;font-size:0;">&nbsp;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg-card bd" style="{{ $card }}">
<tr><td style="padding:12px 0 0;font-size:0;line-height:0;">&nbsp;</td></tr>
@if (filled($recordTitle) || $frame->lines !== null)
<tr><td class="px pxa" style="padding:20px 24px 16px;"><h2 class="tx-strong" style="margin:0;font-size:18px;line-height:26px;font-weight:700;color:{{ $l['strong'] }};overflow-wrap:anywhere;word-break:break-word;">{{ $recordTitle ?? $frame->lines['heading'] }}</h2></td></tr>
@endif
@if ($frame->lines !== null)
<tr><td class="px pxa" style="padding:0 24px 24px;"><table width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
<thead><tr>
<th scope="col" align="left" class="tx-muted bd" style="padding:0 0 8px;border-bottom:1px solid {{ $l['border'] }};font-size:13px;line-height:18px;letter-spacing:.06em;text-transform:uppercase;font-weight:600;color:{{ $l['muted'] }};text-align:left;">{{ $frame->lines['heading'] }}</th>
<th scope="col" align="right" class="tx-muted bd" style="padding:0 0 8px;border-bottom:1px solid {{ $l['border'] }};font-size:13px;line-height:18px;letter-spacing:.06em;text-transform:uppercase;font-weight:600;color:{{ $l['muted'] }};text-align:right;">{{ __('Amount') }}</th>
</tr></thead>
<tbody>
@foreach ($frame->lines['items'] as $item)
<tr>
<td class="bd" valign="top" style="padding:12px 12px 12px 0;border-bottom:1px solid {{ $l['border'] }};overflow-wrap:anywhere;word-break:break-word;">
<p class="tx-strong" style="margin:0;font-size:15px;line-height:22px;color:{{ $l['strong'] }};font-weight:600;">{{ $item['name'] }}</p>
@if (filled($item['meta']))
<p class="tx-muted" style="margin:0;font-size:14px;line-height:20px;color:{{ $l['muted'] }};">{{ $item['meta'] }}</p>
@endif
</td>
<td class="tx-strong bd" valign="top" align="right" style="padding:12px 0;border-bottom:1px solid {{ $l['border'] }};font-size:15px;line-height:22px;color:{{ $l['strong'] }};text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;">{{ $item['amount'] }}</td>
</tr>
@endforeach
@foreach ($frame->lines['totals'] as $total)
@if ($total['total'])
<tr><td colspan="2" style="padding:0;height:10px;font-size:0;line-height:0;">&nbsp;</td></tr>
<tr><td class="tx-strong bd" style="padding:12px 12px 4px 0;border-top:2px solid {{ $l['strong'] }};font-size:16px;line-height:24px;font-weight:700;color:{{ $l['strong'] }};">{{ $total['label'] }}</td><td class="tx-strong bd" align="right" style="padding:12px 0 4px;border-top:2px solid {{ $l['strong'] }};font-size:16px;line-height:24px;font-weight:700;color:{{ $l['strong'] }};text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;">{{ $total['amount'] }}</td></tr>
@else
<tr><td class="tx-muted" style="padding:8px 12px 0 0;font-size:15px;line-height:22px;color:{{ $l['muted'] }};">{{ $total['label'] }}</td><td class="tx-body" align="right" style="padding:8px 0 0;font-size:15px;line-height:22px;color:{{ $l['body'] }};text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;">{{ $total['amount'] }}</td></tr>
@endif
@endforeach
</tbody></table></td></tr>
@endif
@if (trim($parts['notes']) !== '')
@if ($frame->lines !== null)
{!! $hairline !!}
@endif
<tr><td class="px pxa prose prose-notes" style="padding:16px 24px 8px;">
{!! $parts['notes'] !!}
</td></tr>
@endif
<tr><td style="padding:0 0 24px;font-size:0;line-height:0;">&nbsp;</td></tr>
</table>
@endif

{{-- Footer --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="px footer" align="center" style="padding:28px 24px 0;text-align:center;">
<p class="tx-muted" style="margin:0;font-size:14px;line-height:21px;color:{{ $l['muted'] }};text-align:center;"><strong class="tx-strong" style="color:{{ $l['strong'] }};">{{ $footer->brand }}</strong>@if ($footer->tagline !== '') · {{ $footer->tagline }}@endif</p>
@if ($footer->address !== '')
<p class="tx-muted" style="margin:0;padding-top:4px;font-size:13px;line-height:20px;color:{{ $l['muted'] }};text-align:center;">{{ $footer->address }}</p>
@endif
@if ($footer->help !== null)
<p class="tx-muted footer-help" style="margin:0;padding-top:12px;font-size:14px;line-height:21px;color:{{ $l['muted'] }};text-align:center;">{{ $footer->help }}</p>
@endif
@if ($footer->helpUrl !== '' || $footer->preferencesUrl !== null)
<p class="tx-muted" style="margin:0;padding-top:6px;font-size:14px;line-height:22px;color:{{ $l['muted'] }};text-align:center;">
@if ($footer->helpUrl !== '')<a href="{{ $footer->helpUrl }}" class="link" target="_blank" rel="noopener" style="color:{{ $l['link'] }};font-weight:600;text-decoration:underline;">{{ __('Help centre') }}</a>@endif
@if ($footer->helpUrl !== '' && $footer->preferencesUrl !== null)<span class="tx-muted" aria-hidden="true" style="color:{{ $l['muted'] }};">&nbsp;&nbsp;·&nbsp;&nbsp;</span>@endif
@if ($footer->preferencesUrl !== null)<a href="{{ $footer->preferencesUrl }}" class="link" target="_blank" rel="noopener" style="color:{{ $l['link'] }};font-weight:600;text-decoration:underline;">{{ __('Email preferences') }}</a>@endif
</p>
@endif
@if ($footer->security || $footer->reason !== null)
<p class="tx-muted" style="margin:0;padding-top:10px;font-size:13px;line-height:20px;color:{{ $l['muted'] }};text-align:center;">{{ $footer->security ? __('Security emails are always sent and can\'t be turned off.') : $footer->reason }}</p>
@endif
</td></tr></table>
</x-mail::layout>
