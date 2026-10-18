<x-mail::message :preheader="$preheader ?? null" :preferences-url="$preferencesUrl ?? null">
{!! $body !!}
</x-mail::message>
