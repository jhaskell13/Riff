/**
 * Leaflet and the plugins the map uses, as one object. Import Leaflet from
 * here, never from 'leaflet' directly.
 *
 * Leaflet's UMD build assigns its exports to window.L, and the plugins
 * (markercluster in particular) attach themselves to that global. An
 * `import * as L from 'leaflet'` namespace is a *copy* in a production
 * build, so plugin additions such as L.markerClusterGroup never appear on
 * it -- the map worked under the Vite dev server, where the interop
 * shares one object, and broke in `npm run build`. The default import is
 * module.exports itself: the same object the plugins extend.
 */
import Leaflet from 'leaflet';
import 'leaflet-providers';
import 'leaflet.markercluster';

// Fail loudly here rather than as "x is not a function" deep in onMounted.
if (typeof Leaflet.markerClusterGroup !== 'function' || typeof Leaflet.tileLayer?.provider !== 'function') {
    throw new Error('Leaflet plugins did not attach to the Leaflet instance the map uses.');
}

export default Leaflet;
