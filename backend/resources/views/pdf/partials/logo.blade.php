{{-- Small logo for PDFs (inline data, no network). Missing file: just the name shows. --}}
@php($logo = resource_path('brand/nasery-logo.png'))
@if (is_file($logo))<img src="data:image/png;base64,{{ base64_encode(file_get_contents($logo)) }}" alt="" style="height:{{ $size ?? '14pt' }};width:{{ $size ?? '14pt' }};vertical-align:middle;margin-right:4pt;">@endif
