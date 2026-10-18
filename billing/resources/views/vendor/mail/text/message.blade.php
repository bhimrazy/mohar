@props(['preheader' => null, 'preferencesUrl' => null])
@php
    $brand = app(\App\Contracts\Branding\BrandProvider::class)->brand();
    $settings = app(\App\Modules\Core\Support\Settings::class);
    $footerAddress = trim((string) ($settings->get('notifications.footer_address') ?: $settings->get('billing.seller.address', '')));
    $supportUrl = (string) ($settings->get('notifications.support_url') ?: (\Illuminate\Support\Facades\Route::has('client.support.tickets.index') ? route('client.support.tickets.index') : ''));
    // Support address (never a no-reply mailbox): notifications.support_email, else the billing email.
    $supportEmail = app(\App\Modules\Notifications\Support\SupportAddresses::class)->general();
    $helpLine = $supportEmail ? str_replace('{{ support.email }}', $supportEmail, (string) $settings->get('notifications.footer_help_text', 'Questions? Reply to this email or write to {{ support.email }}.')) : null;
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
@if ($helpLine)
{{ $helpLine }}
@endif
@if ($supportUrl !== '')
{{ __('Help centre') }}: {{ $supportUrl }}
@endif
@if (filled($preferencesUrl))
{{ __('Email preferences') }}: {{ $preferencesUrl }}
@endif
