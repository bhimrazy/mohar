{{-- Same markup as resources/views/vendor/mail/html/button.blade.php, for template bodies rendered as HTML. --}}
<table class="action" align="left" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="left" style="border-radius: 8px; background-color: {{ $background }};">
<a href="{{ $url }}" class="button" target="_blank" rel="noopener" style="background-color: {{ $background }}; border: 1px solid {{ $background }}; color: {{ $foreground }};">{{ $label }}</a>
</td>
</tr>
</table>
