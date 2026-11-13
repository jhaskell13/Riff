{{--
    The plain-text alternative to the digest.

    Output here is deliberately UNESCAPED ({!! !!}). This part is served as
    text/plain: there is no HTML context to escape into, and Blade's default
    escaping turned every "&" in a band's name into "&amp;" in people's
    inboxes. A mail client never renders a text/plain part as markup, so
    nothing here can become a tag or a link.

    **This reasoning applies to this file only.** The HTML view next to it
    escapes everything, and must keep doing so.
--}}
Hi {!! $name !!}, here's what's happening.
@if ($myShows->isNotEmpty())

YOU'RE GOING TO
@foreach ($myShows as $show)
- {!! \App\Http\Resources\ShowCardResource::displayTitle($show) !!} — {{ $show->localStart()->isoFormat('ddd, MMM D, h:mm A') }}
  {{ route('shows.show', $show) }}
@endforeach
@endif
@if ($followedShows->isNotEmpty())

ARTISTS YOU FOLLOW, PLAYING SOON
@foreach ($followedShows as $show)
- {!! \App\Http\Resources\ShowCardResource::displayTitle($show) !!} — {{ $show->localStart()->isoFormat('ddd, MMM D') }}
  {{ route('shows.show', $show) }}
@endforeach
@endif
@if ($updates->isNotEmpty())

FROM ARTISTS YOU FOLLOW
@foreach ($updates as $post)
- {!! $post->artist?->name !!}: {!! \Illuminate\Support\Str::limit($post->content, 220, '…') !!}
  {{ route('artists.show', $post->artist) }}
@endforeach
@endif
@if (count($nearbyShows))

COMING UP NEAR YOU
@foreach ($nearbyShows as $show)
- {!! $show['title'] !!}{!! empty($show['placeName']) ? '' : ' — '.$show['placeName'] !!}
  {{ $show['url'] }}
@endforeach
@endif
@if (count($listings))

LOOKING FOR
@foreach ($listings as $listing)
- {!! $listing['title'] !!}{!! empty($listing['area']) ? '' : ' — '.$listing['area'] !!}
  {{ $listing['url'] }}
@endforeach
@endif

Open your scene: {{ $sceneUrl }}

--
You get this because you have a Riff account.
Choose what you hear about: {{ $settingsUrl }}
Stop these emails: {{ $unsubscribeUrl }}
