@props(['preheader' => null, 'preferencesUrl' => null])
@php
    $brand = app(\App\Contracts\Branding\BrandProvider::class)->brand();
    $settings = app(\App\Modules\Core\Support\Settings::class);
    $footerAddress = trim((string) ($settings->get('notifications.footer_address') ?: $settings->get('billing.seller.address', '')));
    $supportUrl = (string) ($settings->get('notifications.support_url') ?: (\Illuminate\Support\Facades\Route::has('client.support.tickets.index') ? route('client.support.tickets.index') : ''));
@endphp
{!! $slot !!}
@isset($subcopy)

{!! $subcopy !!}
@endisset

--
{{ $brand->name }}@if ($brand->tagline !== '') · {{ $brand->tagline }}@endif

@if ($footerAddress !== '')
{{ $footerAddress }}
@endif
@if ($supportUrl !== '')
{{ __('Get help') }}: {{ $supportUrl }}
@endif
@if (filled($preferencesUrl))
{{ __('Email preferences') }}: {{ $preferencesUrl }}
@endif
