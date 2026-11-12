import L from '@/Components/Map/leaflet';

/**
 * Leaflet icon factories.
 *
 * Icons are divIcons rather than images so they inherit the app's theme
 * tokens (see the .riff-pin / .riff-cluster rules in app.css) and so a
 * marker can change state by swapping a class instead of loading a file.
 *
 * Artist names are user input: every label is written with textContent,
 * never interpolated into an HTML string.
 */

const TYPE_ICONS = {
    band: 'groups',
    solo: 'person',
    rapper: 'mic',
    producer: 'tune',
    dj: 'headphones',
    other: 'music_note',
};

/**
 * Where each kind sits around its point: a quadrant, as [x, y] signs.
 *
 * Different kinds routinely share a point -- a show at its venue, a
 * private venue and a one-off show at a town's centre, where that town's
 * artists also are -- and stacked pins of different kinds can't be clicked
 * apart. markercluster only separates markers *within* a group, so each
 * kind gets a fixed quadrant instead: at a shared point the kinds form a
 * small 2x2 grid, never a stack. Predictable beats clever here: a listener
 * learns "shows are up and to the right of their place".
 *
 * The cost is that a lone pin sits a few pixels off its true point; every
 * point but a public venue's is a town centre, so nothing real is lost.
 */
export const KIND_SLOTS = {
    artists: [-1, -1],
    shows: [1, -1],
    venues: [-1, 1],
    listings: [1, 1],
};

const KIND_GLYPHS = {
    artists: 'groups',
    shows: 'event',
    venues: 'storefront',
    listings: 'campaign',
};

const SLOT_GAP = 1;

/** Anchor a w x h icon so it fills its kind's quadrant around the point. */
function slotAnchor(kind, w, h) {
    const [sx, sy] = KIND_SLOTS[kind] ?? [0, 0];

    return [
        sx < 0 ? w + SLOT_GAP : sx > 0 ? -SLOT_GAP : w / 2,
        sy < 0 ? h + SLOT_GAP : sy > 0 ? -SLOT_GAP : h / 2,
    ];
}

function glyph(name) {
    const icon = document.createElement('i');
    icon.className = 'material-icons';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = name;

    return icon;
}

function pinIcon(element, kind, w, h) {
    return L.divIcon({
        html: element,
        className: '',
        iconSize: [w, h],
        iconAnchor: slotAnchor(kind, w, h),
    });
}

export function artistIcon(artist, { active = false } = {}) {
    const pin = document.createElement('div');
    pin.className = `riff-pin${active ? ' riff-pin--active' : ''}`;
    pin.appendChild(glyph(TYPE_ICONS[artist.type] ?? TYPE_ICONS.other));

    return pinIcon(pin, 'artists', 30, 30);
}

/**
 * A show: a calendar tile with the month and the day, so a glance at the
 * map says *when* as well as where.
 */
export function showIcon(show, { active = false } = {}) {
    const pin = document.createElement('div');
    pin.className = `riff-pin riff-pin--show${active ? ' riff-pin--active' : ''}`;

    const leaf = document.createElement('span');
    leaf.className = 'riff-pin__date';

    const month = document.createElement('span');
    month.className = 'riff-pin__month';
    month.textContent = show.month ?? '';

    const day = document.createElement('span');
    day.className = 'riff-pin__day';
    day.textContent = show.day ?? '';

    leaf.append(month, day);
    pin.appendChild(leaf);

    return pinIcon(pin, 'shows', 30, 32);
}

/** A venue: a teal hexagon with a storefront. */
export function venueIcon(venue, { active = false } = {}) {
    const pin = document.createElement('div');
    pin.className = `riff-pin riff-pin--venue${active ? ' riff-pin--active' : ''}`;
    pin.appendChild(glyph('storefront'));

    return pinIcon(pin, 'venues', 32, 28);
}

/** A listing ("drummer wanted"): a purple speech bubble. */
export function listingIcon(listing, { active = false } = {}) {
    const pin = document.createElement('div');
    pin.className = `riff-pin riff-pin--listing${active ? ' riff-pin--active' : ''}`;
    pin.appendChild(glyph(listing.icon ?? 'campaign'));

    return pinIcon(pin, 'listings', 30, 30);
}

/**
 * Size grows with the count but flattens out, so a 5,000-artist bubble
 * stays roughly the size of a 500-artist one instead of covering a city.
 */
function bubbleSize(count) {
    return Math.round(Math.min(64, 32 + Math.log10(Math.max(count, 1)) * 14));
}

/**
 * Count bubble for one kind: its own colour, shape, and glyph, and its
 * kind's slot, so clusters of different kinds sit side by side too.
 */
export function clusterIcon(count, kind = 'artists') {
    const size = bubbleSize(count);
    const bubble = document.createElement('div');
    bubble.className = `riff-cluster riff-cluster--${kind}`;
    bubble.style.width = `${size}px`;
    bubble.style.height = `${size}px`;

    const icon = glyph(KIND_GLYPHS[kind] ?? 'place');
    icon.classList.add('riff-cluster__glyph');

    const label = document.createElement('span');
    label.style.fontSize = `${count >= 1000 ? 11 : 13}px`;
    label.textContent = count >= 1000 ? `${Math.round(count / 100) / 10}k` : String(count);

    bubble.append(icon, label);

    return pinIcon(bubble, kind, size, size);
}

/**
 * The viewer's own position: their profile area, or a location the
 * browser shared for this session only.
 */
export function youIcon() {
    const wrapper = document.createElement('div');
    wrapper.className = 'riff-you';

    const pulse = document.createElement('span');
    pulse.className = 'riff-you__pulse';
    wrapper.appendChild(pulse);

    return L.divIcon({
        html: wrapper,
        className: '',
        iconSize: [48, 48],
        iconAnchor: [24, 24],
    });
}

/**
 * Accessible label for a marker, read by screen readers and shown as the
 * browser tooltip.
 */
export function markerLabel(artist, unit) {
    if (artist.kind === 'show') {
        return [artist.title, `${artist.dateLabel}, ${artist.timeLabel}`, artist.place].filter(Boolean).join(' — ');
    }

    if (artist.kind === 'venue') {
        return [artist.name, artist.kindLabel, artist.area].filter(Boolean).join(' — ');
    }

    if (artist.kind === 'listing') {
        return [artist.title, artist.typeLabel, artist.area].filter(Boolean).join(' — ');
    }

    const parts = [artist.name, artist.location];

    if (artist.distance !== null && artist.distance !== undefined) {
        parts.push(`${artist.distance} ${unit} away`);
    }

    return parts.filter(Boolean).join(' — ');
}
