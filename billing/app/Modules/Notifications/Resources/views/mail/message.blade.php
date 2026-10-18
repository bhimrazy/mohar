<x-mail::message :preheader="$preheader ?? null" :preferences-url="$preferencesUrl ?? null" :raw="$raw ?? false">
{!! $body !!}
@if (filled($action ?? null))
{{-- System-owned slot: the layout prints it after the body, outside the body's Markdown pass. --}}
<x-slot:action>{!! $action !!}</x-slot:action>
@endif
</x-mail::message>
