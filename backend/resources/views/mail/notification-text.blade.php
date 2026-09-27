Hi {{ $firstName ?: 'there' }},

{{ $heading }}
@if ($text)

{{ $text }}
@endif
@if ($link)

Open in NaSeRy: {{ $link }}
@endif

Change your notification settings: {{ $settings }}
