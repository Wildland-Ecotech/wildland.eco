// The admin Map page's map (pages/admin/map.blade.php): Leaflet with
// OpenStreetMap's tiles, and a dot for each point the page lists in
// #map-data, colored by kind, with a popup naming it and linking to the
// contact. app.js loads this only on that page, so Leaflet isn't in every
// page's script. The colors match .map-legend's swatches in _admin.scss.

import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const COLORS = {
    now: '#FF8A80',
    soon: '#FFD166',
    later: '#2ECF9E',
    other: '#5AA9E6',
    site: '#9AA0A6',
    preview: '#B388FF',
};

// Fort Collins, for a map with nothing on it yet
const HOME = [40.5853, -105.0844];

export function initMap(element) {
    const data = document.getElementById('map-data');
    const points = data ? JSON.parse(data.textContent) : [];

    // Scrolling the page shouldn't zoom the map; the buttons and pinching do
    const map = L.map(element, { scrollWheelZoom: false });

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map);

    const markers = points.map((point) => L.circleMarker([point.lat, point.lng], {
        radius: point.kind === 'site' ? 10 : 8,
        color: point.kind === 'site' ? '#000' : '#fff',
        weight: 2,
        fillColor: COLORS[point.kind] ?? COLORS.other,
        fillOpacity: 1,
    }).bindPopup(popup(point)).addTo(map));

    if (markers.length === 0) {
        map.setView(HOME, 12);
    } else if (markers.length === 1) {
        map.setView(markers[0].getLatLng(), 15);
    } else {
        map.fitBounds(L.featureGroup(markers).getBounds().pad(0.1));
    }
}

// The popup's name, linking to the contact, and its note, such as "Ready now
// · 40 bags", as text so nothing in them is read as HTML
function popup(point) {
    const content = document.createElement('div');
    const name = document.createElement('a');

    name.href = point.url;
    name.textContent = point.name;
    content.append(name);

    if (point.note) {
        content.append(document.createElement('br'), point.note);
    }

    return content;
}
