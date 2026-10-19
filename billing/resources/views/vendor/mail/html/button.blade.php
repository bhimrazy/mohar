{{-- Framework notifications' <x-mail::button>: the same markup as Notifications templates (notifications::mail.button). --}}
@props(['url', 'color' => 'primary'])
{!! \App\Modules\Notifications\Support\EmailBody::button((string) $url, trim(strip_tags((string) $slot))) !!}
