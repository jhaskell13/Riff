{{--
    The weekly digest.

    A plain HTML view on purpose, never a markdown mailable: markdown mail
    parses the whole template, so a band's post could inject links into
    every subscriber's inbox. Blade escapes by default, which is what is
    wanted for every line of user text below.

    Never name a variable $message here -- Laravel reserves it for the
    Message object in mail views.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>This week on Riff</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#1c1c1c;">
<div style="max-width:560px;margin:0 auto;padding:24px 16px;">

    <p style="margin:0 0 20px;font-size:15px;line-height:1.5;">
        Hi {{ $name }}, here's what's happening.
    </p>

    @if ($myShows->isNotEmpty())
        <h2 style="margin:0 0 8px;font-size:15px;font-weight:600;">You're going to</h2>
        <ul style="margin:0 0 24px;padding-left:18px;font-size:14px;line-height:1.6;">
            @foreach ($myShows as $show)
                <li style="margin-bottom:6px;">
                    <a href="{{ route('shows.show', $show) }}" style="color:#1976d2;text-decoration:none;">
                        {{ \App\Http\Resources\ShowCardResource::displayTitle($show) }}
                    </a>
                    — {{ $show->localStart()->isoFormat('ddd, MMM D, h:mm A') }}
                    @if ($show->venue?->name || $show->location_name)
                        at {{ $show->venue?->name ?? $show->location_name }}
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($followedShows->isNotEmpty())
        <h2 style="margin:0 0 8px;font-size:15px;font-weight:600;">Artists you follow, playing soon</h2>
        <ul style="margin:0 0 24px;padding-left:18px;font-size:14px;line-height:1.6;">
            @foreach ($followedShows as $show)
                <li style="margin-bottom:6px;">
                    <a href="{{ route('shows.show', $show) }}" style="color:#1976d2;text-decoration:none;">
                        {{ \App\Http\Resources\ShowCardResource::displayTitle($show) }}
                    </a>
                    — {{ $show->localStart()->isoFormat('ddd, MMM D') }}
                    @if ($show->venue?->name || $show->location_name)
                        at {{ $show->venue?->name ?? $show->location_name }}
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($updates->isNotEmpty())
        <h2 style="margin:0 0 8px;font-size:15px;font-weight:600;">From artists you follow</h2>
        @foreach ($updates as $post)
            <div style="margin:0 0 14px;padding:12px;background:#ffffff;border:1px solid #e3e3e3;border-radius:8px;">
                <a href="{{ route('artists.show', $post->artist) }}" style="color:#1c1c1c;font-weight:600;font-size:14px;text-decoration:none;">
                    {{ $post->artist?->name }}
                </a>
                <p style="margin:6px 0 0;font-size:14px;line-height:1.5;color:#3c3c3c;">
                    {{ \Illuminate\Support\Str::limit($post->content, 220, '…') }}
                </p>
            </div>
        @endforeach
        <div style="height:10px;"></div>
    @endif

    @if (count($nearbyShows))
        <h2 style="margin:0 0 8px;font-size:15px;font-weight:600;">Coming up near you</h2>
        <ul style="margin:0 0 24px;padding-left:18px;font-size:14px;line-height:1.6;">
            @foreach ($nearbyShows as $show)
                <li style="margin-bottom:6px;">
                    <a href="{{ $show['url'] }}" style="color:#1976d2;text-decoration:none;">{{ $show['title'] }}</a>
                    @if (! empty($show['placeName'])) — {{ $show['placeName'] }} @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if (count($listings))
        <h2 style="margin:0 0 8px;font-size:15px;font-weight:600;">Looking for</h2>
        <ul style="margin:0 0 24px;padding-left:18px;font-size:14px;line-height:1.6;">
            @foreach ($listings as $listing)
                <li style="margin-bottom:6px;">
                    <a href="{{ $listing['url'] }}" style="color:#1976d2;text-decoration:none;">{{ $listing['title'] }}</a>
                    @if (! empty($listing['area'])) — {{ $listing['area'] }} @endif
                </li>
            @endforeach
        </ul>
    @endif

    <p style="margin:24px 0 0;font-size:14px;">
        <a href="{{ $sceneUrl }}" style="color:#1976d2;text-decoration:none;">Open your scene on Riff</a>
    </p>

    <hr style="margin:24px 0 12px;border:0;border-top:1px solid #e0e0e0;">

    <p style="margin:0;font-size:12px;line-height:1.6;color:#6b6b6b;">
        You get this because you have a Riff account.
        <a href="{{ $settingsUrl }}" style="color:#6b6b6b;">Choose what you hear about</a>, or
        {{-- Signed, and works without signing in: stopping email must never
             require getting back into an account. --}}
        <a href="{{ $unsubscribeUrl }}" style="color:#6b6b6b;">stop these emails</a>.
    </p>
</div>
</body>
</html>
