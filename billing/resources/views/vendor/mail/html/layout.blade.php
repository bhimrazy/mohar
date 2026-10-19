{{--
    Email shell, direction A "two-card receipt" (docs/design/email/email-design.md §4, §7, §9). Table-based, inline
    styles carry the light design (clients that strip <style> still fit a phone: mobile-first sizes), the <style>
    block restores desktop sizes and adds the dark palette for prefers-color-scheme and Outlook.com. A centred
    640 px column, fluid below it, fixed by an MSO table for Outlook desktop. The theme CSS is inlined after render.
--}}
@props(['preheader' => null, 'subject' => null, 'palette' => null])
@php
    $palette ??= \App\Modules\Notifications\Mail\MailPalette::current();
    $l = $palette->light;
    $width = \App\Modules\Notifications\Mail\MailPalette::WIDTH;
    $locale = str_replace('_', '-', app()->getLocale());
    $label = filled($subject) ? $subject : app(\App\Contracts\Branding\BrandProvider::class)->brand()->name;
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="ltr" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $label }}</title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
<style>table,td,th,p,a,span,div,h1,h2{font-family:Arial,Helvetica,sans-serif !important}.pxa{padding-left:48px !important;padding-right:48px !important}.amt-a{font-size:48px !important;line-height:56px !important}</style><![endif]-->
<style>
.prose a{color:{{ $l['link'] }}}
a:focus-visible{outline:2px solid {{ $l['accent'] }};outline-offset:2px}
@media only screen and (max-width:620px){
.frame-pad{padding:12px 10px 24px !important}
.px{padding-left:20px !important;padding-right:20px !important}
.amount{font-size:34px !important;line-height:40px !important}
.h1{font-size:24px !important;line-height:30px !important}
.btn-full{width:100% !important}
.btn-full .button{display:block !important}
.hide-sm{display:none !important}
}
@media only screen and (max-width:420px){
.f-k,.f-v{display:block !important;width:100% !important;text-align:left !important}
.f-k{padding-bottom:0 !important}
.f-v{padding-top:2px !important}
.amount{font-size:30px !important;line-height:36px !important}
}
@media only screen and (max-width:360px){
.frame-pad{padding:8px 6px 20px !important}
.px{padding-left:16px !important;padding-right:16px !important}
.amount{font-size:26px !important;line-height:32px !important}
}
@media only screen and (min-width:621px){
.pxa{padding-left:48px !important;padding-right:48px !important}
.amt-a{font-size:48px !important;line-height:56px !important}
.thumb{display:table-cell !important}
}
{!! $palette->darkCss() !!}
</style>
{!! $head ?? '' !!}
</head>
<body class="bg-frame" style="margin:0;padding:0;width:100%;background-color:{{ $l['frame'] }};">
<div role="article" aria-roledescription="email" aria-label="{{ $label }}" lang="{{ $locale }}" dir="ltr" class="bg-frame" data-mail-layout="brand" style="background-color:{{ $l['frame'] }};font-family:{{ \App\Modules\Notifications\Mail\MailPalette::FONT }};font-size:16px;line-height:24px;">
@if (filled($preheader))
<div class="preheader" style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:{{ $l['frame'] }};opacity:0;">{{ $preheader }}{!! str_repeat('&#8199;&#65279;&#847; ', 40) !!}</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg-frame wrapper" style="background-color:{{ $l['frame'] }};">
<tr><td align="center" class="frame-pad" style="padding:32px 16px 40px;">
<!--[if mso]><table role="presentation" width="{{ $width }}" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<div style="max-width:{{ $width }}px;margin:0 auto;">
{{ $slot }}
</div>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</div>
</body>
</html>
