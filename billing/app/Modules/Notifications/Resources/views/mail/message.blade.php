<x-mail::message :preheader="$preheader ?? null" :preferences-url="$preferencesUrl ?? null" :raw="$raw ?? false" :frame="$frame ?? null" :support-email="$supportEmail ?? null" :subject="$subject ?? null" :action-token="$actionToken ?? null" :notes="$notes ?? null">
{!! $body !!}
@if (filled($action ?? null))
{{-- System-owned slot: the layout prints it as the email's button, outside the body. --}}
<x-slot:action>{!! $action !!}</x-slot:action>
@endif
</x-mail::message>
