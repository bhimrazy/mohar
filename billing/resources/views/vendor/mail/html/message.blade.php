{{--
    Brand-aware message shell: logo (light + dark variants), body, and a footer with the company
    address, a support link and — for client mail whose category can be switched off — the email
    preferences link. Brand data comes from BrandProvider (Settings → Appearance → Brand).
--}}
@props(['preheader' => null, 'preferencesUrl' => null])
@php
    $brandProvider = app(\App\Contracts\Branding\BrandProvider::class);
    $brand = $brandProvider->brand();
    $brandLogo = $brandProvider->emailLogoUrl();
    $brandLogoDark = $brand->emailLogoDark;
    $settings = app(\App\Modules\Core\Support\Settings::class);
    $footerAddress = trim((string) ($settings->get('notifications.footer_address') ?: $settings->get('billing.seller.address', '')));
    $supportUrl = (string) ($settings->get('notifications.support_url') ?: (\Illuminate\Support\Facades\Route::has('client.support.tickets.index') ? route('client.support.tickets.index') : ''));
    $supportEmail = (string) $settings->get('core.company_email', config('mail.from.address'));
@endphp
<x-mail::layout :preheader="$preheader">
{{-- Header --}}
<x-slot:header>
<tr>
<td class="header-cell" align="center">
<a href="{{ config('app.url') }}" class="brand-link" style="text-decoration: none;">
@if ($brandLogo)
<img src="{{ $brandLogo }}" alt="{{ $brand->name }}" height="32" class="brand-logo-light" style="height: 32px; width: auto; max-width: 220px; border: 0;">
@if ($brandLogoDark)
<img src="{{ $brandLogoDark }}" alt="{{ $brand->name }}" height="32" class="brand-logo-dark" style="display: none; max-height: 0; height: 32px; width: auto; max-width: 220px; border: 0;">
@endif
@else
<span class="brand-name">{{ $brand->name }}</span>
@endif
</a>
</td>
</tr>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<tr>
<td>
<table class="footer" align="center" width="560" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="footer-cell" align="center">
<p><strong>{{ $brand->name }}</strong>@if ($brand->tagline !== '') · {{ $brand->tagline }}@endif</p>
@if ($footerAddress !== '')
<p>{!! nl2br(e($footerAddress)) !!}</p>
@endif
<p>
@if ($supportUrl !== '')
<a href="{{ $supportUrl }}">{{ __('Get help') }}</a>
@elseif ($supportEmail !== '')
<a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
@endif
@if (filled($preferencesUrl))
&nbsp;·&nbsp; <a href="{{ $preferencesUrl }}">{{ __('Email preferences') }}</a>
@endif
</p>
</td>
</tr>
</table>
</td>
</tr>
</x-slot:footer>
</x-mail::layout>
