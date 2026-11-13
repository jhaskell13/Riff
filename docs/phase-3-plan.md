# Phase 3 implementation plan — make the artist page a home for fans

Status: **complete** (November 12, 2026). All eight steps built.

Originally approved (November 12, 2026), with the owner's decisions recorded at the bottom. Revisions from that review: a wall can be opened to any verified user (artist opt-in) rather than followers-only being the single option; legacy posts are **re-seeded, not backfilled**, while the project is pre-launch; and "your scene" gets its own routes and controllers while `/feed` survives, demoted to the end of the nav, as the seed of a future followed-entity digest.

This turns Phase 3 of [ROADMAP.md](../ROADMAP.md) into eight PR-sized steps. As in Phase 2, each step ships on its own, keeps CI green on SQLite, MySQL and Postgres, and leaves the app better if we pause after it.

**What this phase is.** Phase 2 made the scene visible. Phase 3 makes *following* an artist mean something: their page becomes the place fans hear from them, talk to each other, and click through to buy the record — and Riff learns how to tap someone on the shoulder when their band books a show.

**The order and why.** Support links first because they're independent of everything and are the shortest path to a real success signal. Then the authorship schema, because the wall, the replies, the moderation, the home page and the notifications all read it. Then the wall and replies (the long-hidden comments schema finally ships). Then artist-level moderation, *before* show threads, so a new discussion surface is never un-moderatable for a release. Then "your scene", then notifications — in that order, because **the email digest is "your scene", by email**, and building the query once is the point.

**Conventions that apply to every step** (per CLAUDE.md, carried from Phase 2): policies gate every state-changing route; persist only `validated()` and create owned rows through relations (`MassAssignmentTest` enforces it); "enum" columns are plain strings validated with `Rule::in` (the CI matrix exists because SQLite tolerates what real drivers reject); every new surface joins `ReportStoreRequest::SUBJECTS` and `ModerationAction`; new Inertia pages need `npm run build` before `php artisan test`; model `@property` docblocks stay current for PHPStan level 6; every test runs offline (`Mail::fake()`, `Notification::fake()`, `Http::fake()`).

**Product guardrails this phase must not break.** Nothing here becomes a global timeline or an algorithmic feed — "your scene" is ordered by date and distance, never by engagement, and like counts never drive ranking (principle 2). Riff still handles no money (principle 4): support links point out. Listener locations stay private (principle 5): fan counts are counts, never names or pins.

---

## Step 1 — Support links and fan counts — ✅ BUILT (November 12, 2026)

**Why first:** entirely self-contained, no schema anyone else depends on, and it's the one thing in this phase that pays an artist back immediately. It also starts the measurement the Phase 4 payments decision gate needs.

- **`artist_support_links`**: `artist_id`, `type`, `url`, `label` (optional, short), `position`, `clicks` (unsigned counter), timestamps. `type` is a string from `config('riff.support.types')` — `bandcamp`, `store`, `merch`, `tickets`, `tip_jar`, `other` — with labels and an icon per type, so the form can't offer what validation rejects (the `reportReasons` pattern).
- **`App\Services\SupportLink`** mirrors `MusicLink`: strict parse to a canonical URL, derive the type where the host says it (`*.bandcamp.com` → `bandcamp`), reject anything that isn't http(s), cap length, and **never** build an embed — support links are always link-outs rendered with `rel="noopener noreferrer nofollow"` and a visible external-link marker. `App\Rules\SupportUrl` is what the request uses. A `tip_jar` host allowlist is worth having (Ko-fi, Patreon, PayPal.me, Venmo, Cash App); for `store`/`other` any valid URL passes, because an artist's own shop is on their own domain.
- Managed from the Artist Zone edit form (`ArtistPolicy::update`), capped at `config('riff.support.max_links')` (6), reorderable. `clicks` and `artist_id` are not fillable; links are created through `$artist->supportLinks()`.
- **Rendered** as a "Support this artist" block on the public artist page, above the fold on mobile — typed icon, label, host shown so nobody clicks blind. Guests see it too; this needs no account.
- **Click measurement, deliberately crude:** `POST /artists/{artist}/support/{link}/click` behind a `support-clicks` limiter increments the counter and returns 204; the client beacons it and navigates regardless, so a blocked beacon never costs the artist a sale. **No per-user rows** — we want "did support links get clicked", not who clicked. Members see their own counts in the Artist Zone.
- **Fan counts and local fans:** the public page already has `followers_count`. Add `local_followers_count` — followers whose stored point is within `config('riff.scenes.radius_km')` of the artist's point — computed with the same arithmetic-only distance expression the map uses, bound as `(? * 1.0)` (the SQLite float-against-expression trap), and cached. **Both counts are shown**; below `config('riff.fans.local_floor')` (5) the local count renders as **"< 5"** rather than an exact number, because "1 local fan" plus a small town is close to naming someone. No list, no map, ever.
- **Pilot data / consent:** real acts get only the research-verified Bandcamp link-out already in the dataset; every other support link is on a fictional entry. `PilotDatasetTest` gains that assertion.
- Tests: parse/reject table for each type, cap enforced, non-member can't add, click endpoint increments and is throttled, local count hidden under the floor, guest sees the block.

**Done when:** a band adds its Bandcamp and a tip jar, a logged-out visitor clicks through from the public page, and the band can see the clicks happened. ✅

**Notes from building it**
- **No `url` mutator on the model.** Canonicalising needs the sibling `type`, and `fill()` follows the incoming array's order rather than `$fillable`'s, so a mutator reading `$attributes['type']` would have been correct only by luck. It lives in `ArtistSupportLinks::sync()` instead, which has both values in hand.
- **Support links are queried, not eager-loaded, for the public page.** A loaded relation serialises with its model, which would have put `clicks` into the public payload no matter what the resource chose to emit. There's a test asserting the public page carries no click count.
- **The dataset's consent rule was extended, not reused.** Support links are seeded onto **fictional acts only**, and `PilotDatasetTest` now fails if a real act carries one. A support section is an *invitation to pay*, which is a stronger attribution than listing a link — the existing rule covered the research-verified Bandcamp link-out, but "this act asks to be paid here" is a claim nobody verified. Flagged rather than assumed: if the owner reads it the other way, the real acts' already-verified Bandcamp URLs could be promoted into the section by relaxing that one assertion.
- **Invented acts must never link anywhere a stranger could own** (owner note, November 12): "The Silver Mill" is exactly the kind of name a real band may already have taken on Bandcamp or Ko-fi. Fictional seed links now use reserved `example.*` domains, or — where a type requires a known host — that service's own homepage. `PilotDatasetTest` enforces it. The same rule retired `ArtistFactory::withMusic()`'s real third-party tracks (a Spotify track, a YouTube video, a SoundCloud track) in favour of valid-shaped but all-zero identifiers: the Listen section and the "has music" filter still demo, and the player resolves to the provider's own "not found" instead of a real musician's work.
- The parser's reject list is where the real work is — credentials in the host (`https://bandcamp.com@evil.example`), non-http(s) schemes, IP and bare hostnames, odd ports, interior control characters. Surrounding whitespace is trimmed instead, since a trailing newline is an ordinary paste and refusing it would be a bad error for a good link.
- **Not done in this step:** support links on map cards (the card is a preview, and the section belongs on the page), and anything about the Phase 2 "scene banner on the map" proposal.

## Step 2 — Posts with explicit context — ✅ BUILT (November 12, 2026)

**Why here:** this is the schema everything downstream reads. Today a post carries `user_id` (NOT NULL) and a nullable `artist_id`, so "the band announced this" and "a fan wrote this" are indistinguishable — and `FeedStoreRequest` doesn't even accept `artist_id`, so artist-voice posts exist only in the seeder. This step makes the voice explicit and gives every post a home.

- **Two axes, named:**
  - `posts.artist_id` becomes **the wall the post lives on** (which artist's page it appears on). Required for everything new.
  - `posts.author_context` (string, `artist` | `fan`) is **the voice**.
  - `posts.user_id` stays as **who actually typed it** — kept always, for the audit trail and for artist-level moderation, but **never serialised publicly when the context is `artist`**. A band speaks as the band; which member hit post is internal. `PostResource` enforces this, and a test asserts the member's name and id are absent from an artist-voice payload.
  - An artist-voice post has `artist_id` = the author = the wall, which is correct: artists post on their own page.
- **Writing:** `POST /artists/{artist}/posts`, two paths in one controller. Context `artist` requires `ArtistPolicy::update` (membership is the authority to speak as the band). Context `fan` is Step 3. Created through relations; `author_context` is set by the controller from the authorization result, **not taken from the request** — a request-supplied voice is a spoofing endpoint.
- **Reading:** `Post::onWall($artist)` plus the existing `published()`. Index on `(artist_id, status, published_at)` for the wall, keeping the existing `(status, published_at)` for the home page in Step 6.
- **No backfill: the seed data is rewritten** (decision 3). The migration adds the columns with the constraints they should have, and `PilotSeeder`/`DatabaseSeeder` produce wall-anchored posts with an explicit voice. Valid only pre-launch — see the decision for when this stops being allowed.
- **Surfaces:** the artist page grows an "Updates" section (artist-voice posts, newest first, paginated), and the Artist Zone grows a composer per artist with a clear "posting as **The Silver Mill**" affordance — the roadmap's success signal is artists posting as their identity *without prompting*, so the voice has to be obvious before they type, not after.
- `posts` limiter stays; `@property` docblocks added to `Post` (and `Comment`, which has none today).
- Tests: a non-member can't post as the artist, a member can, the member's identity never appears in artist-voice output, context can't be set from the request, wall query ignores other artists' posts, legacy rows survive the migration on all three drivers.

**Done when:** a member posts an update from the Artist Zone, it renders on the public page as the artist with no member name anywhere in the payload, and a guest can read it. ✅ (built together with step 3, since they share the schema)

## Step 3 — The fan wall and replies — ✅ BUILT (November 12, 2026)

**Why here:** the wall is the product promise of the phase, and replies are the long-hidden `comments`/`comment_likes` schema finally shipping.

- **Fan posts:** context `fan`, same endpoint, rendered as the person. Gated by `artists.wall_mode` — `fans` (default) | `everyone` (the artist's opt-in to any verified user) | `members_only` (closed) — so the artist can open or close the wall without archiving anything (decision 2). Members always post as the artist by default on their own page but may post as themselves elsewhere.
- **Comments become polymorphic.** `comments.post_id` becomes `commentable_type`/`commentable_id`, so Step 5's show threads reuse the same table instead of inventing phantom post rows that every post query then has to filter out. The table is pre-pilot and tiny; this is the right moment. Comments also gain `author_context` and `artist_id`, so **the artist's replies are marked as coming from the artist** — the roadmap calls for exactly that, and it's the single most important signal on a wall.
- **Depth is capped at one level** (`parent_id` may only point at a top-level comment; the request rejects deeper). Threads stay readable on a phone and the render stays two queries, not a recursive walk. See decision 4.
- **Reactions stay small:** `post_likes`/`comment_likes` ship as they are, with the integer `likes` counters. **Nothing is ever ordered by them** — walls and threads are chronological, full stop. A test asserts no ordering clause reads `likes`.
- **Blocking already works in the other direction and must keep working:** a listener who blocks an artist sees neither that artist's wall posts nor its artist-voice replies anywhere. Reuse the `hasBlocks()` / `NOT IN … orWhereNull` shape from `FeedController`, remembering that a `NOT IN` drops NULL rows.
- New limiters: `wall` (fan posts, tighter than `posts`) and `comments`. Both per-minute and per-day, like `venues`.
- Tests: fan post renders as the fan, artist reply is marked as the artist, a non-follower can't post when `wall_mode` is `fans`, depth cap, blocked artist's wall content is invisible to the blocker, ordering is chronological.

**Done when:** a fan comments on the band's announcement, another fan replies, and the band answers in its own voice — all on the band's page, visible to a logged-out reader. ✅

### Notes from building steps 2 and 3

**Changed from the plan**
- **`wall_mode` is three states, not two** (owner decision 2): `fans` (default) | `everyone` (the artist's opt-in) | `members_only`.
- **Comment reporting moved up from step 4.** Shipping a comment surface with no way to report it would have broken the cross-cutting rule that every new surface is reportable, so `comment` joined `ReportStoreRequest::SUBJECTS` and `ModerationAction::RemoveComment` here. *Artist-level* moderation (hiding, wall bans) is still step 4; the `comments.status` column already carries a `hidden` state for it.
- **Asking for the artist voice without membership is refused, not downgraded.** `Wall::voiceFor()` still falls back to the fan voice (it is the safe default for any internal caller), but the controller authorizes against what was actually asked for. Someone who ticks "post as the band" and silently gets a post under their own name is surprised in public, on someone else's page.

**Three things that bit, all now covered by tests**
- **`posts.comments` was an integer counter column shadowing the relation** — the same trap CLAUDE.md documents for `likes`. `$post->comments` returned `0`, so eager loading worked and reading it did not. Nothing had ever maintained the column; it is dropped, and comment counts come from `withCount()`.
- **An N+1 of permissions and reactions.** Every post and reply asked the policies "can I delete / reply here?" (three `exists()` each) and `isLikedBy()` (one more). A busy wall ran 42 queries against 18 for an empty one. Fixed with `App\DTO\WallViewer` (computed once per render, passed into the resources) and a `withExists('likers as is_liked')` in the wall query. `WallTest` asserts the count **does not grow** with contents. A memo on the `User` model was tried first and **rejected**: it went stale as soon as a follow was written outside the follow controller, which a test caught.
- **Nested resource collections serialise as `{"data": [...]}`**, so the client would have been iterating an object. Wall children are mapped and `resolve()`d to plain arrays.

**Seed data was rewritten, not backfilled** (decision 3). `PilotSeeder` now seeds four wall conversations — a question the band answers, fans agreeing with each other, a practice-space offer, and a question nobody has answered yet — on fictional acts only, since a wall of invented fans saying invented things about a real band attributes them a community they never had. Wall seeding is idempotent **per act**, which `PilotSeedTest` now checks: re-running gave a band the same announcement twice.

## Step 4 — Artist-level moderation — ✅ BUILT (November 12, 2026)

**Why before show threads:** a new discussion surface should never ship without the people who own it being able to clean it. Step 3 opened a wall; this step hands over the broom before Step 5 opens another.

- **Three member powers, all recoverable:** hide a wall post, hide a comment (status flips, the row stays — the same "nothing destructive" rule the platform queue follows), and **remove a fan from this page's wall** (`artist_wall_bans`: `artist_id`, `user_id`, `banned_by`, `reason`, timestamps). A banned fan can still *follow* and read — a ban is about the wall, not the fandom — and sees a plain "you can't post here" state rather than a silent failure.
- **Hidden ≠ reported.** Artist-level hiding is local to that page. **Platform reports still go to the single `POST /reports` queue**: `comment` joins `ReportStoreRequest::SUBJECTS`, and `ModerationAction` gains `remove_comment`. An artist hiding something does not close a platform report about it, and a moderator's decision outranks the artist's.
- Members see a hidden-items view for their own wall so a mistaken hide is undoable, and so moderation isn't a black hole.
- An audit trail on each action (`hidden_by`, `hidden_at`) — the same shape as the report trail, for the same reason.
- Tests: a member hides and unhides; a non-member can't; a banned fan can't post but can still read and follow; an artist hide doesn't resolve a report; a moderator's `remove_comment` survives the artist unhiding it.

**Done when:** a band hides one unpleasant comment and removes its author from the wall, without a platform moderator being involved, and nothing is destroyed. ✅

### Notes from building step 4 (and the wall revisions that came with it)

**Owner notes addressed first**
- **The wall is now the last card on the artist page.** It grows without bound, so everything factual about an artist reads before it.
- **Replies are indented behind a rail** and get a smaller avatar; aligned with their siblings they were indistinguishable from them.
- **Comment and reply pagination.** `GET posts/{post}/comments` and `GET comments/{comment}/replies` (public, `map` limiter) back "Show N more comments" and "See N more replies". The client starts at **page 1 and deduplicates by id**, because these endpoints page by `comments_per_page` while the wall ships only `comments_per_post` — asking for "page 2" would have silently skipped everything between. A test covers it.
- **Seeded conversations were the real gap.** The data existed (the owner's database was from an intermediate code state and had no replies), but only 5 of 95 artists had a wall. Now: scripted walls on every fictional pilot act plus 14 scattered ones (**19 walls, 23 posts, 80 comments, 38 replies**), with one deliberately busy wall — 8 top-level comments and a 4-reply thread — so both "show more" controls appear in the demo without anyone typing. Scripts live in `Database\Seeders\Support\WallScripts`.

**What artist-level moderation is, and is not**
- Members **hide and unhide**; they never delete or archive. `status` flips, the row stays, and there is no artist-level route that destroys a fan's words. Getting something off Riff means **reporting** it, so a second person decides — and hiding something never closes a report about it.
- **A moderator's `removed` outranks an artist's `hidden`.** Unhide restores only what the artist hid; hide refuses to relabel a removed row as the artist's doing. Both directions are tested, because this is the whole reason the two words are different.
- **The trail is append-only** (`wall_moderation_events`): a hide and its undo are two rows. Members see the full history per entity, newest first, not just the current state — a row hidden, restored and hidden again tells a different story. Emitted to members only.
- **A ban is about the wall, not the fandom.** A barred listener keeps following, keeps reading, and keeps everything they already wrote; hiding that is a separate decision. A member can't be banned from their own wall.

**Structural changes this forced**
- **`App\Services\WallReader`** now owns every wall read. Three callers (the page and the two thread endpoints) have to agree on the rules, and *who sees hidden content* is one of them.
- The status vocabulary moved to **`App\Models\Concerns\HasWallStatus`** as `visibleStatuses(bool $includeHidden)` — a plain list fed to `whereIn`, not a query scope. Most of these queries are built inside eager-load closures where the receiver is a `Relation`, and a scope there resolves for neither the reader nor PHPStan (it failed static analysis as a scope).
- The members' extra eager load (the trail) is covered by its own query-count test, the same guard the wall itself has.

## Step 5 — Show threads — ✅ BUILT (November 12, 2026)

- Each show gets a short discussion ("who's going?", "set times?") using the Step 3 polymorphic comments on the `Show` subject. **It closes after the date** — `config('riff.shows.thread_closes_after_hours')` past the start (reuse the existing `still_on_hours` logic for "tonight's show hasn't ended") — after which the thread is read-only, not deleted. Nothing lingers, the same rule that keeps one-off locations off the map.
- Who may post: signed-in users; guests get the `intent=fan` register prompt the rest of the app uses. Cancelled shows keep a read-only thread (ticket holders still need the banner and the context).
- Moderation: the **host artist's** members moderate the thread (they own the night), plus the platform queue. Reuse Step 4's machinery wholesale.
- **Attendee list stays opt-in**, which `show_rsvps.is_public` already encodes: the thread shows the public count, and only names of fans who chose to be listed. This step must not become a back door to the private ones — a test asserts a private RSVP's name never reaches the payload.

**Done when:** a Friday show has a "who's going?" thread that answers itself, and by Sunday it's read-only. ✅

### Notes from building it

**The polymorphism paid, but only after one more abstraction.** Comments already hung off anything; what did not was everything *around* them — who may post, who moderates, whether the thread is open. Without a contract those would have become `instanceof Post` chains in the controller, the policy, the reader and the moderation controller. `App\Contracts\Commentable` collapses them into four questions the subject answers, and the whole of step 5's behaviour is: `Show` implements it. No second comment controller, no second reader, no second component.

- **One flat thread per show**, not a wall of posts — a show is one subject, so the conversation is one conversation. `CommentThread` is reused verbatim; it gained a `replyRoute` prop and nothing else.
- **Closing** is `thread_closes_after_hours` (24), deliberately longer than `still_on_hours` (4): "that was great" is worth saying the morning after, "what time are you on?" is not worth asking a week later. A cancelled show closes too. Both go **read-only, not hidden** — and the page says *why*, because a missing box reads as a broken site.
- **Owned by the host artist**, as asked. Moderation reuses step 4 wholesale: the same hide/unhide routes, the same append-only trail (kept against the host), the same "artists hide, moderators remove". `ArtistPolicy::postOnWall` is reused too, which means **a wall ban also bars someone from that artist's show threads** — both are the same artist's space, and a ban covering only half of it is a gap someone would find. Flagged here because it is a judgment call, not something the plan specified.
- **The attendee rule held.** Posting in a thread does not create an RSVP, and the thread does not surface private attendees; both have tests, since the thread sits right beside the opt-in list.
- **Seed data:** threads on three upcoming fictional-host shows (a question the host answers, fans arranging a lift between themselves, an unanswered one) plus the cancelled show, so the closed-and-still-readable state is visible. No past show exists in the seed set, so the *time-based* close is covered by tests rather than by the demo.

## Step 6 — "Your scene" takes over the feed's place in the nav — ✅ BUILT (November 12, 2026)

- The signed-in home becomes **your scene**: posts from artists you follow, their upcoming shows, and new shows and listings near you — **ordered by date and distance, never by engagement**. A guest's home stays the map (`/` already redirects there).
- **`/feed` is not deleted** (decision 8). Your scene gets its own route (`/scene`), controller and page; the existing feed keeps its route, controller and page, and moves to the end of the nav. It is scaffolding for a future followed-entity digest, not a surface to extend — principle 2 still rules out what it does today.
- Composed from what already exists, the way `SceneView` composes the map's searches: followed-artist walls via Step 2's index, plus `SceneMapSearch` shows/venues/listings around the viewer's own point and radius. Do not reimplement scene rules — "upcoming", "active", "blocked" must keep meaning exactly one thing.
- **Personal, therefore uncached**, following the `MapFilters::hiddenFor` rule: this view is per-user by definition, so it gets no shared cache and must instead be *bounded* — paginated, with each section capped, and benchmarked against a user following many artists before it ships.
- A listener who follows nobody gets a real empty state that points at the map and their own town's scene page, not a blank screen.
- Nav: "Your scene" takes the slot "Feed" holds today, and both are auth-only (`AuthenticatedLayout` renders for guests, so they have to be guarded).
- Tests: only followed artists' posts appear, blocked artists never appear, ordering is date/distance, a guest is sent to the map, pagination bounded, query count flat as the follow list grows.

**Done when:** a fan who follows three bands opens Riff signed in and sees what those bands said and when they next play, with nothing in it chosen by popularity. ✅

### Notes from building it

- **Two halves, deliberately.** The top is about the bands you chose (their updates, their next dates); the bottom is about where you are (what's on nearby, whoever is playing). The "around you" half is `SceneMapSearch` with the viewer's own point, exactly as `SceneView` composes a scene page, so the home page and the map cannot disagree.
- **Artist-voice posts only.** A followed wall also carries what *fans* wrote there, and pulling that in would turn a page about the bands you chose into a stream of strangers — which is the global feed this replaces. There's a test for it.
- **Uncached and therefore bounded.** It is per-viewer by definition, so it follows the `MapFilters::hiddenFor` rule: no shared cache, and every section has a hard limit from `config('riff.scene_home')` with the updates paginating. A query-count test asserts the cost does not grow with the follow list.
- **The feed is kept, demoted, and marked.** `/feed` keeps its route, controller and page and moves to the *end* of the nav; `/scene` takes the slot it held. Both the route comment and CLAUDE.md now say plainly that it must not be extended in its current form — a global timeline is what principle 2 rules out — and that it exists as the starting point for the followed-entity digest the owner wants to return to.
- **A parse error got past PHPStan**, which is worth remembering: an unparenthesised nested ternary meant the file never compiled, so static analysis reported "no errors" on a file it could not read. The tests caught it as "Premature end of PHP process". Analysis passing is not the same as the code parsing.

### Owner notes addressed in the same round (from step 5 review)

- **Badges now say standing, not names.** `App\DTO\ThreadBadges`: `Artist` on a wall, `Host` on a show, `On the bill` for another confirmed act, and `Member` / `Member of X` for someone in one of those bands posting as themselves. The wall previously repeated the band's name in a badge directly under the band's name. Built once per render; building it per post was an N+1 the existing query-count test caught immediately.
- **Show threads no longer require a follow.** A show is a public event, not the host's page, so `Commentable::commentsFollowWallMode()` is false for a `Show` and any verified user may post. Bans and blocks still apply — the artist's own exclusions are not a side door.
- **Other acts on the bill can speak as themselves.** `voiceArtistFor()` grants the artist voice to the host *or* any confirmed act the user belongs to; pending acts get nothing, since they appear on no public surface until they have agreed to be on one.
- A test fixture bug surfaced here and is worth knowing: `lineup_entries.status` is **not fillable** (only `Lineup::sync()` and the confirm/decline endpoints write it) and defaults to `confirmed`, so passing `status` to `create()` silently produced a confirmed entry and inverted a test. Fixtures must `forceFill` it.

## Step 7 — In-app notifications — ✅ BUILT (November 12, 2026)

- Add Laravel's `notifications` table (none exists today) and `App\Notifications\*`. In-app first, email in Step 8, so the delivery channel is a separate release from the eventing.
- **What generates one** — each anchored to an artist, show, or thread, never to "activity in general":
  - a followed artist posts an update or announces a show;
  - someone replies to your wall post or your comment;
  - **you're added to a bill, or re-asked after a material change** — this is the explicit debt Phase 2 Step 3 left ("for now the invite waits in the Artist Zone"). Paying it here is the highest-value notification in the phase, because an unanswered lineup invite is a show that doesn't appear;
  - opt-in: a new show in your scene.
- **Preferences are per artist and capped.** Global defaults on the user, plus a per-artist override on the `artist_follows` pivot (`notify` / level) — following a band you love shouldn't mean the same volume as following a band you're curious about. Frequency caps are enforced in the sender, not left to the templates.
- Notifications are **collapsed, not streamed**: five posts from one band in a day is one notification, so the bell never becomes the algorithmic feed this project refuses to build.
- A bell in the layout with an unread count, a list page, mark-read. Blocking an artist clears and suppresses its notifications.
- Tests: `Notification::fake()` throughout; a blocked artist generates none, per-artist mute respected, collapsing works, the lineup invite reaches every member of the invited artist.

**Done when:** a band lists a show and its fans — and the other band on the bill — know about it without opening the Artist Zone. ✅

### Notes from building it

**Foundations, not a product.** Six kinds (`App\Support\NotificationType`): replies, new fans, wall posts, artist updates, new shows, and lineup invitations. Laravel's standard `notifications` table, unmodified, because in-app messaging (Phase 4) will write to it too — the explicit ask was that this be the base for that, and adding a kind should be a subclass plus a config entry with nothing in the client changing.

**One funnel.** `Notifier::send()` asks the three questions every trigger would otherwise have to remember: does this person want the kind, have they **blocked** the artist it is about, and is it already in their bell. It also drops the actor. Two bugs the tests caught prove the point of centralising it:
- The Notifier *claimed* in a comment to drop the actor and did not — someone replying to their own post was notified about it. Notifications now carry `causedBy($user)` and the filter is real.
- Reply routing used the speaker's *voice* artist rather than the thread's owner, so a stranger's question on a show thread reached nobody: a non-member has no voice artist. It uses `moderatingArtist()` now.

**Folding** is what keeps the bell from becoming a feed: a `collapseKey()` merges into an *unread* notification about the same thing inside the window and bumps a count ("and 3 more"). A read notification never folds into — once you have seen it, the next one is news again.

**Two dials, because there are two questions.** `users.notification_preferences` (JSON, defaulting from config) answers "do I want this kind at all"; `artist_follows.notify` answers "do I want it from *this* band", and is reachable from the artist page without unfollowing. Mutes apply only to the kinds that arrive *because* of a follow — a reply to your own words is not a subscription. `lineup_invite` is `required` and cannot be switched off, since an unanswered invitation keeps somebody else's show unlisted.

**The Phase 2 debt is paid.** `Lineup::sync()` now asks the invited acts' members, and re-asks after a material change — but only those who had already answered, since someone still sitting on the first invitation does not need a second. A pending act's own fans hear nothing until it confirms, because announcing it would name an act that has not agreed to be named.

**Not in this step:** any email. The lineup invitation's immediate email (decision 5) lands with step 8, where the mail infrastructure does. `riff.notifications` config carries the collapse window, bell limit and page size.

**Gotcha worth knowing:** notifications are `ShouldQueue` and the local queue is the database driver with no worker, so the seeded demo bell was five rows in `jobs` until `Notifier::send()` grew an `immediately:` flag for seeders and commands.

## Step 8 — Email digest — ✅ BUILT (November 12, 2026)

- A scheduled, queued `riff:send-digests` building from **Step 6's query** — the digest is "your scene, by email", which is why Step 6 comes first.
- Frequency per user (`off` | `daily` | `weekly`, default weekly), hard-capped, with nothing sent when there's nothing to say. A **signed unsubscribe URL** in every send (no login required to stop email — that's table stakes), and a one-click "fewer emails" that lands on the preference page.
- **Plain HTML mail view, never a markdown mailable, and never a variable named `$message`** — both learned the hard way on `ListingReplyMail` (markdown parses user text; `$message` is reserved by Laravel and silently clobbered the reply body). Fan post titles and wall excerpts are user input and go through the same discipline.
- **Per-event email stays off by default.** The one candidate for an immediate email is the lineup invite, because it's actionable and time-sensitive (see decision 5).
- Watch the failed-jobs table — the roadmap asks for that before real users arrive, and a digest run is the first scheduled job with real volume.
- Tests: `Mail::fake()`; nothing sent to an unsubscribed or empty-scene user, cap respected, unsubscribe link works signed-out, no HTML injection from post content.

**Done when:** a weekly email tells a fan what their bands are doing, and one click stops it forever. ✅

### Notes from building it

- **Four sections, in the order someone cares about them:** the shows you said you were going to (the only thing you have already committed to), then when the artists you follow next play, then what they said since your last digest, then what's on around you. Built from the same searches the map and "your scene" use.
- **Three rules in `DigestSender`:** only people who asked, never twice inside the window, never an empty one. The third matters most — a person with no news is skipped *and their `digest_sent_at` is left alone*, so their window keeps growing until there is something worth sending.
- **A real bug the tests caught:** the sender used `->each()`, which chunks — but sending updates `digest_sent_at`, the very column the query filters on, so the set shifted under the cursor and people were silently skipped. It uses a bounded `get()` now.
- **A second one:** `preferences` was validated `required`, so a form of toggles with everything switched off rejected exactly the person who wanted silence. It is `present` now.
- **The text part is deliberately unescaped.** `text/plain` has no HTML context, and Blade's default put `&amp;` in front of real band names in people's inboxes. The HTML view still escapes everything, and the comment in the text view says loudly that the reasoning does not transfer.
- The lineup invitation now sends its immediate email (decision 5) — the only per-event mail in Riff.

## Owner notes addressed in the same round

- **Notifications are a dropdown now**, bounded by `bell_limit` and ending in "See all and settings"; the list is fetched when the menu opens rather than shared with every page (a count is cheap on every response, a list of titles is not).
- **Notifications deep-link to the thing, not the page.** Reply and post notifications carry `#comment-123` / `#post-456`, and `Composables/useHashHighlight.js` scrolls to it and flashes it — on mount *and* on every Inertia navigation, since clicking a second notification for the same page remounts nothing. If the target is on an older page of a wall it quietly does nothing rather than jumping somewhere wrong.
- **The flaky test is fixed and understood.** `MapSceneTest > a genre filter keeps venues with a matching bill coming up` left a show's host artist unpinned, and `ArtistFactory` assigns one or two genres *at random from all 32* — so the "jazz room" host drew Punk about one run in twenty and matched a punk filter. The host's genres are pinned now; 30 consecutive runs green.

## The pilot dataset's rules changed (owner decision, November 12, 2026)

The demo now seeds **real, already-public data**: real acts with their publicly credited members, factual bios, their own Bandcamp links, and real venues at their public addresses — because a recruiting demo built from the actual scene is worth more than one built from invented names, and none of it is private. Researched this session for Boundaries, Perennial, The Lonesome While, West End Blend, Joey Batts & Them, Anxious and Cheem.

**The one hard line the owner set — no contact details, ever — is now the dataset's main enforced rule.** No email or phone in the file, and every seeded account sits on an RFC-reserved `example.*` domain, so a demo digest or notification is *physically incapable* of reaching a real person. `PilotDatasetTest` asserts both, against the file and against the seeded database.

**Walls and show threads now carry simulated conversation on real acts**, written around their actual records and rooms. Worth being clear-eyed about: this is the one thing in the dataset that is invented *about a real subject*. It is what makes the demo show a populated scene rather than an empty directory, the seeders refuse production, and the dataset's `_meta` says so plainly.

Two things kept from the old rules, and why: **no real DIY space** (a club's address is public, a DIY space's is not — that distinction is the whole reason private venues exist in Riff), and **judgements stay fictional** (the report queue, hidden/archived profiles, a cancellation, a declined slot — being reported is not a public fact about anybody).

---

## Decisions (settled, November 12, 2026)

1. **Comments become polymorphic** (`commentable_type`/`commentable_id`), so show threads in Step 5 reuse them instead of each show carrying a phantom post row. ✅ Approved.
2. **Wall access is followers by default, with an artist opt-in to open it to any verified user.** `artists.wall_mode` is therefore three states, not two: `fans` (default) | `everyone` (opt-in) | `members_only` (closed). The opt-in is the artist's call to make, so the dial belongs on their page — but the default stays the one that makes being a fan mean something.
3. **Seed data is rewritten, not backfilled.** Schemas are still moving and nothing is live, so Step 2 ships the new columns as plain `NOT NULL`-where-they-should-be and the seeders are updated to produce wall-anchored posts with an explicit voice. No backfill migration, no wall-less legacy rows to render nowhere. **This choice is valid only while Riff is pre-launch** — the first real user's post ends it, and from then on this kind of change needs a backfill. Worth re-reading before Phase 4.
4. **Reply depth is one level.** Replies attach only to top-level comments; nesting may come later.
5. **The lineup invite sends an immediate email**, alongside its in-app notification, and is the only per-event email in the phase. The same "actionable and time-sensitive earns an immediate send" rule will carry over to in-app messaging in Phase 4.
6. **Support-link clicks are a bare counter**, no per-user rows.
7. **Artist pages show both the total and the local follower count.** Under the floor the local count renders as **"< 5"** rather than being hidden — an honest "there are some, not many" beats an absence the reader can't interpret.
8. **"Your scene" takes the nav slot the feed holds today, on its own routes and controller, and `/feed` stays.** The global feed is demoted to the end of the nav rather than deleted: it's the seed of a future in-app digest of *followed-entity* activity (artists, venues, genres, scenes — new releases, shows), which is a different thing from both the global timeline it is now and the place-and-date view "your scene" will be. Nothing is wired to that future yet; this step only makes sure Step 6 doesn't destroy the starting point. Note the tension to watch: `/feed` in its *current* global-timeline form is what ROADMAP principle 2 rules out, so it stays as scaffolding, not as a surface to grow.

## Deliberately out of scope

- **Payments.** Support links point out; native sales are Phase 4's decision gate.
- **In-app messaging**, and therefore reportable/blockable listing replies — Phase 4, as Step 6 of Phase 2 noted.
- **Member roles and join requests** — Phase 4, even though the wall makes the absence more visible.
- **Likes-driven ranking, a global timeline, follower leaderboards** — parked by principle, not by schedule.
- **The livestream prototype** — untouched; Phase 5 retires it.
- **Carried from Phase 2:** Step 8 (imported events, optional) and the "making scenes easier to find" proposals (the map's scene banner, "see the scene" in map cards). The scene banner is small and every map session passes through it — worth slotting in beside Step 1 if there's room, but it isn't Phase 3 work.
- **Carried from the Phase 2 audit:** the show page's four RSVP queries and the per-lineup-entry cache bump. Step 5 touches the show page; fix them there if it's free.

## Checkpoints

Steps 1–3 deliver the roadmap's "done when" for the fan side (follow a band, read its announcement, comment, click through to buy). Step 4 is the gate that makes 3 and 5 safe to leave running. Steps 6–8 are the "come back for the next show" half of the loop and are where the phase either earns retention or doesn't — measure notification opt-*out* rate from the first week, since the roadmap's own success signal is notifications bringing people back rather than prompting people to turn them off.
