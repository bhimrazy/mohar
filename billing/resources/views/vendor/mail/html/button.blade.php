{{-- Primary action in the brand accent (client theme's primary colour). Full width on small screens. --}}
@props([
    'url',
    'color' => 'primary',
    'align' => 'left',
])
@php
    $brand = app(\App\Contracts\Branding\BrandProvider::class)->brand();
    $background = $color === 'error' ? '#b42318' : ($brand->accent ?? '#4f4bc4');
    $foreground = $color === 'error' ? '#ffffff' : ($brand->accentForeground ?? '#ffffff');
@endphp
<table class="action" align="{{ $align }}" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}" style="border-radius: 8px; background-color: {{ $background }};">
<a href="{{ $url }}" class="button" target="_blank" rel="noopener" style="background-color: {{ $background }}; border: 1px solid {{ $background }}; color: {{ $foreground }};">{!! $slot !!}</a>
</td>
</tr>
</table>
