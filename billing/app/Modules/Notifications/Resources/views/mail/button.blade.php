{{--
    The primary action (design §3 block 7): a bulletproof button, inline on desktop and full width on phones (50 px
    tall, label centred), in the theme accent. One <a class="button"> and one href, so a private link appears once.
--}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" class="action btn-full"><tr>
<td align="center" class="btn-td" bgcolor="{{ $background }}" style="border-radius:8px;background-color:{{ $background }};mso-padding-alt:14px 28px;">
<a href="{{ $url }}" class="button" target="_blank" rel="noopener" style="display:inline-block;padding:14px 28px;border:1px solid {{ $background }};border-radius:8px;background-color:{{ $background }};color:{{ $foreground }};font-family:{{ \App\Modules\Notifications\Mail\MailPalette::FONT }};font-size:16px;line-height:20px;font-weight:600;text-align:center;text-decoration:none;mso-padding-alt:0;font-variant-numeric:tabular-nums;">{{ $label }}</a>
</td>
</tr></table>
