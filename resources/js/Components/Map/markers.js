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

export function artistIcon(artist, { active = false } = {}) {
    const pin = document.createElement('div');
    pin.className = `riff-pin${active ? ' riff-pin--active' : ''}`;

    const glyph = document.createElement('i');
    glyph.className = 'material-icons';
    glyph.setAttribute('aria-hidden', 'true');
    glyph.textContent = TYPE_ICONS[artist.type] ?? TYPE_ICONS.other;
    pin.appendChild(glyph);

    return L.divIcon({
        html: pin,
        className: '',
        iconSize: [30, 30],
        iconAnchor: [15, 30],
        popupAnchor: [0, -28],
    });
}

/**
 * Size grows with the count but flattens out, so a 5,000-artist bubble
 * stays roughly the size of a 500-artist one instead of covering a city.
 */
function bubbleSize(count) {
    return Math.round(Math.min(64, 30 + Math.log10(Math.max(count, 1)) * 14));
}

export function clusterIcon(count) {
    const size = bubbleSize(count);
    const bubble = document.createElement('div');
    bubble.className = 'riff-cluster';
    bubble.style.width = `${size}px`;
    bubble.style.height = `${size}px`;
    bubble.style.fontSize = `${count >= 1000 ? 11 : 13}px`;
    bubble.textContent = count >= 1000 ? `${Math.round(count / 100) / 10}k` : String(count);

    return L.divIcon({
        html: bubble,
        className: '',
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2],
    });
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
    const parts = [artist.name, artist.location];

    if (artist.distance !== null && artist.distance !== undefined) {
        parts.push(`${artist.distance} ${unit} away`);
    }

    return parts.filter(Boolean).join(' — ');
}
