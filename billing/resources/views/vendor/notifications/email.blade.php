{{--
    Framework and module notifications (MailMessage): password reset, email verification, security
    notices, staff invitations. Same branded layout as Notifications templates; the heading falls back
    to the subject, and the sign-off uses the brand name from BrandProvider (never config('app.name')).
--}}
@php($brandName = app(\App\Contracts\Branding\BrandProvider::class)->brand()->name)
<x-mail::message :preheader="$introLines[0] ?? null">
# {{ ! empty($greeting) ? $greeting : ($subject ?? ($level === 'error' ? __('Something went wrong') : __('Hello'))) }}

@foreach ($introLines as $line)
{{ $line }}

@endforeach
@isset($actionText)
<x-mail::button :url="$actionUrl" :color="$level === 'error' ? 'error' : 'primary'">
{{ $actionText }}
</x-mail::button>

@endisset
@foreach ($outroLines as $line)
{{ $line }}

@endforeach
@if (! empty($salutation))
{{ $salutation }}
@else
{{ __('— The :name team', ['name' => $brandName]) }}
@endif

@isset($actionText)
<x-slot:subcopy>
{{ __('Button not working? Paste this link into your browser:') }} <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
