Hi {{ $firstName ?: 'there' }},

{{ $heading }}
@if ($text)

{{ $text }}
@endif
@if ($link)

Open in NaSeRy: {{ $link }}
@endif

@if ($settings)
Change your notification settings: {{ $settings }}
@else
You get this because you bought tickets on NaSeRy with this e-mail address.
@endif
