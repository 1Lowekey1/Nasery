{{-- NaSeRy header: the logo travels inside the e-mail (inline attachment, ~9 KB), so it shows
     without loading anything from the site. If the image is blocked, the name still reads. --}}
@php($logo = resource_path('brand/nasery-logo.png'))
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 4px;">
    <tr>
        @if (isset($message) && is_file($logo))
            <td style="padding-right:10px;vertical-align:middle;"><img src="{{ $message->embed($logo) }}" width="40" height="40" alt="" style="display:block;border:0;"></td>
        @endif
        <td style="vertical-align:middle;font-size:20px;font-weight:bold;color:{{ $color ?? '#1f2937' }};">NaSeRy</td>
    </tr>
</table>
