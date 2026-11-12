{{-- Plain HTML on purpose, not a markdown mailable: markdown mail parses
     the whole template, so a replier could inject links into the poster's
     inbox. Here user text is only ever HTML-escaped. --}}
<!DOCTYPE html>
<html lang="en">
<body style="margin:0;padding:24px;background:#f4f4f5;font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#18181b;">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:10px;padding:28px;">
    <p style="margin:0 0 4px;font-size:13px;color:#71717a;">A reply on Riff</p>
    <h1 style="margin:0 0 16px;font-size:20px;">{{ $replierName }} replied to “{{ $listing?->title }}”</h1>

    <div style="padding:14px 16px;border-left:3px solid #8e6cd8;background:#f8f7fc;white-space:pre-line;font-size:15px;line-height:1.5;">{{ $replyText }}</div>

    <p style="margin:20px 0 4px;font-size:14px;"><strong>How to reach them:</strong></p>
    <p style="margin:0;font-size:15px;white-space:pre-line;">{{ $contact }}</p>

    <p style="margin:24px 0 0;font-size:13px;color:#52525b;line-height:1.5;">
        Riff hasn't shared your email address with them, and replying to this email won't reach them —
        use the contact they gave above.
    </p>
    <p style="margin:20px 0 0;">
        <a href="{{ $url }}" style="display:inline-block;padding:10px 18px;border-radius:999px;background:#d32f2f;color:#fff;text-decoration:none;font-size:14px;">View your listing</a>
    </p>
    <p style="margin:20px 0 0;font-size:12px;color:#71717a;">If this reply was abusive, open the listing on Riff and report it.</p>
</div>
</body>
</html>
