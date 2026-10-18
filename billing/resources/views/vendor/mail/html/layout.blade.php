{{--
    Branded transactional layout for every email (Notifications templates and framework/Platform
    notifications alike). Light by default, with dark-mode overrides for clients that honour
    prefers-color-scheme (Apple Mail, iOS, Outlook.com). Table-based for Outlook; CSS is inlined by
    the Markdown renderer from themes/default.css, the <style> block below is kept for media queries.
--}}
@props(['preheader' => null, 'raw' => false])
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ app(\App\Contracts\Branding\BrandProvider::class)->brand()->name }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
@media only screen and (max-width: 600px) {
.inner-body { width: 100% !important; border-radius: 0 !important; border-left: 0 !important; border-right: 0 !important; }
.footer { width: 100% !important; }
.content-cell { padding: 28px 20px !important; }
.header-cell { padding: 24px 20px 16px !important; }
.button { display: block !important; width: 100% !important; text-align: center !important; box-sizing: border-box !important; }
.action, .action table { width: 100% !important; }
}
@media (prefers-color-scheme: dark) {
body, .wrapper, .body, .header-cell, .footer { background-color: #0f1115 !important; }
.inner-body { background-color: #171a21 !important; border-color: #2a2f3a !important; }
.content-cell, .content-cell p, .content-cell li, .content-cell td, .content-cell th { color: #c9ced8 !important; }
.content-cell h1, .content-cell h2, .content-cell h3, .content-cell strong, .footer strong { color: #f1f3f7 !important; }
.content-cell h1 + p, .footer p, .subcopy p { color: #9aa1ad !important; }
.content-cell table.facts, .table table { background-color: #1e222b !important; }
.facts td, .facts th, .table td, .table th { border-color: #2a2f3a !important; }
.content-cell hr, .subcopy { border-color: #2a2f3a !important; }
.brand-logo-light { display: none !important; }
.brand-logo-dark { display: inline-block !important; max-height: none !important; }
.brand-name { color: #f1f3f7 !important; }
}
</style>
{!! $head ?? '' !!}
</head>
<body>
@if (filled($preheader))
<div class="preheader" style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">{{ $preheader }}&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;</div>
@endif

<table class="wrapper" data-mail-layout="brand" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="560" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell">
{{-- raw: the body is already final HTML (Notifications templates) and is not parsed as Markdown again. --}}
{!! $raw ? $slot : Illuminate\Mail\Markdown::parse($slot) !!}

{!! $action ?? '' !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
