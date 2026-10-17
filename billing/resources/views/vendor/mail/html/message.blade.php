{{-- Brand-aware mail layout: name, logo and tagline come from BrandProvider (Settings → Appearance → Brand). --}}
@php
    $brandProvider = app(\App\Contracts\Branding\BrandProvider::class);
    $brand = $brandProvider->brand();
    $brandLogo = $brandProvider->emailLogoUrl();
@endphp
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
@if ($brandLogo)
<img src="{{ $brandLogo }}" alt="{{ $brand->name }}" height="32" style="height: 32px; width: auto; max-width: 220px; border: 0;">
@else
{{ $brand->name }}
@endif
</x-mail::header>
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
<x-mail::footer>
© {{ date('Y') }} {{ $brand->name }}@if ($brand->tagline !== '') · {{ $brand->tagline }}@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
