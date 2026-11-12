{{-- Plain text: nothing here is interpreted as HTML, so values are unescaped (escaping would print &amp; literally). --}}
{!! $replierName !!} replied to "{!! $listing?->title !!}" on Riff.

{!! $replyText !!}

How to reach them: {!! $contact !!}

Riff hasn't shared your email address with them, and replying to this email won't reach them — use the contact they gave above.

View your listing: {!! $url !!}
