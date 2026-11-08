# Riff development roadmap

Riff helps people find music made nearby by independent artists and gives those artists a useful way to be discovered. The map is the product's center: it should turn a location into a short list of local artists worth hearing, then make it easy to listen, follow, attend a show, and share the discovery.

## Product direction

Build a reliable local discovery loop before expanding into a broad social network or live production platform:

**Find local artists → hear their music → follow or attend → return for updates.**

Treat artist identity as a profile for a band, solo act, rapper, producer, or other music maker. Build first for a useful experience in one populated area, then broaden geography as artist density grows. Use approximate, artist-chosen public locations; never reveal a person's live or home location.

## Current foundation

- Laravel 13, Inertia 3, and Vue 3 app with authentication and email verification scaffolding, Pint formatting, and CI gating the deploy.
- Artist records with genre, biography, hometown, image URL, website, and member relationships.
- Leaflet map with marker clustering and a user city/state location.
- Geocoding through Google Maps with a fallback location and cache.
- Early post/comment/like models and UI, but the feed currently uses hard-coded sample posts and the store path is incomplete.
- Early livestream models, pages, events, and broadcast endpoints; the experience and access controls are not yet production-ready.
- Ticketmaster event lookup service exists but is not connected to the visible map flow.

## Roadmap

### 0. Make the prototype dependable

**Goal:** Establish a trustworthy, secure baseline before inviting local artists and listeners.

- Fix artist ownership and membership authorization: only an authorized artist member can edit, delete, or start/manage that artist's streams. Validate artist membership at stream creation and protect every state-changing endpoint.
- Complete the artist profile lifecycle: show, edit, update, delete/archive, image upload, and public profile page. Make delete behavior explicit and avoid accidental deletion of other members' shared artist identities.
- Repair feed persistence: fix the misspelled `contnet` field, remove debug `dd()`, validate and save posts, and return real feed data. Replace mock posts and local-only likes/comments with server-backed behavior or hide these controls until implemented.
- Fix map correctness: return artist IDs and profile data, display clickable markers/cards, and filter to an explicit nearby radius. Do not silently plot failed geocodes at the same default coordinates.
- Harden external API handling: add timeouts, status/error handling, configuration checks, and bounded caching. Remove `dd()` from Ticketmaster errors. Avoid one remote geocode request per artist during every map build; persist coordinates and geocode on profile save/update.
- Add focused automated coverage for map output, artist authorization, feed creation, and stream authorization. Make external-service tests deterministic with fakes.
- ~~Replace the stock Laravel README with Riff setup, environment variables, local development, and deployment guidance.~~ Done.

**Done when:** A new user can create an account, make or manage an artist identity, find it on the map, and view a real profile; unauthorized edits are rejected and expected flows have automated coverage.

### 1. Ship the local discovery MVP

**Goal:** Make the map valuable even for a first-time visitor.

- Design an onboarding path for listeners and artists; collect only the location fields needed for local discovery and explain public visibility.
- Create a useful artist profile: name, artist type, genres, short bio, location, photo, links, and at least one playable music link or track embed.
- Make map markers open a compact artist card with distance/area, genre, music preview, profile link, and follow action. Add a synchronized list view and clear empty/loading/error states.
- Add search and filters for genre, artist type, and radius; support map movement/re-centering and mobile layouts.
- Seed a pilot area with consented artist profiles and make the empty-map state invite artists to add themselves.
- Add basic reporting, blocking, profile editing, and moderation workflow before public growth.

**Success signals:** Visitors reach an artist profile from the map; profiles have playable music; listeners follow artists; artists complete profiles; users return to the map. Establish pilot baselines before setting numeric targets.

### 2. Turn discovery into local participation

**Goal:** Help people act on discovery and help artists build an audience.

- Add follows and a small, artist-first update feed sourced from actual artist posts. Keep posts oriented around music, releases, rehearsals, and shows.
- Add local events: artist-submitted shows/open mics, venue and date details, RSVP/save, and map integration. Clearly label imported event listings versus artist-confirmed information.
- Connect the existing Ticketmaster service only where it improves local coverage; cache and degrade gracefully when the provider is unavailable.
- Add notifications for followed artists' new music, posts, and upcoming shows with user controls and sensible frequency limits.
- Add profile completeness and discovery quality signals so artists know how to become easier to find; don't rank solely by popularity.

**Success signals:** Follows convert to listens, saves, RSVPs, and repeat visits; local artists receive profile visits from outside their existing audience.

### 3. Add community features deliberately

**Goal:** Support connection around local music without creating a generic engagement feed.

- Add comments/reactions only after persistence, moderation, rate limits, and notification controls are reliable.
- Add an artist join-request workflow with explicit member roles and approval; the current create form's search/request interaction is only a stub.
- Add collaboration discovery (looking for bandmates, collaborators, or session musicians) as structured, opt-in listings with location/genre/instrument filters.
- Add venue, organizer, and community profiles only if pilot users demonstrate a need.
- Provide account privacy, data export/deletion, content reporting, spam controls, and moderation audit trails.

### 4. Explore live experiences after validating demand

**Goal:** Test live formats without making real-time media infrastructure a prerequisite for Riff's core value.

- Start with scheduled listening sessions, live chat around an external stream link, or short artist-hosted audio/video events using a managed streaming provider.
- For true livestreaming, use a provider designed for real-time media; store stream metadata and provider references in Riff, not video chunks in app requests. Define latency, bandwidth, moderation, recording, and operating-cost limits before launch.
- Add reminders, attendance, replay links, and artist controls only after the pilot shows repeat use.
- Treat live jams as a separate research prototype: synchronized remote performance needs low-latency audio, timing, device/network handling, and musician testing. Run a small technical spike with explicit latency goals before committing to a product feature.

**Decision gate:** Continue live investment only if artists and listeners repeatedly use scheduled live events and the experience meets latency, reliability, safety, and cost targets.

## Cross-cutting quality work

- **Location and privacy:** Store normalized coordinates and a chosen public area; let artists change or hide their map location. Explain location use and avoid exposing exact residential addresses.
- **Accessibility and mobile:** Keyboard-operable map alternatives, readable contrast, screen-reader artist lists, responsive cards, and reduced-motion support.
- **Reliability:** Queue slow imports/geocoding, observe provider errors, track failed jobs, and keep discovery usable during external API outages.
- **Performance:** Paginate feeds, bound map queries to visible/radius areas, index location/filter fields appropriately, and avoid loading every artist globally.
- **Security and abuse:** Authorization policies, request validation, upload limits/scanning, rate limits, CSRF-safe actions, moderation tools, and dependency/security updates.
- **Operations:** CI for formatting and automated tests, environment/configuration documentation, backups, error monitoring, and a lightweight release checklist.

## Recommended next milestone

Complete **Phase 0** and then release **Phase 1** to a small pilot area. Keep the map, artist profile, playable music, and follow action as the narrow first release. Defer a full social timeline, donations, and remote live jams until real pilot behavior demonstrates they strengthen local discovery.

## Product questions to validate in the pilot

- Do listeners want discovery centered on where they live, where they are now, or a manually selected area?
- What makes an artist profile compelling enough to stop on the map: a music preview, upcoming show, genre, or recommendation?
- Will artists maintain profiles themselves, and what is the least burdensome verification/moderation model?
- Does a local feed help people discover artists, or does it distract from map-led discovery?
- Which event sources and streaming providers are affordable and useful in the first target area?
