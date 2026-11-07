// The admin Map page's map (pages/admin/map.blade.php): Leaflet with
// OpenStreetMap's tiles, and a dot for each point the page lists in
// #map-data, colored by kind (the .map-dot styles in _admin.scss, matching
// .map-legend's swatches), with a popup naming it and linking to the
// contact. A pickup in a planned trailer load is ringed, and a load's stops
// are numbered in route order. app.js loads this only on that page, so
// Leaflet isn't in every page's script.
//
// While building or editing a trailer load (#trailer-load), clicking a
// pickup's dot checks or unchecks its box in the list, the chosen dots are
// numbered (an edited load's stops in their route order, from #load-order,
// then the others in the list's order, as they'll be saved), and the
// progress bar follows the chosen bags. The list's boxes and the form work
// without this script.

import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

// Fort Collins, for a map with nothing on it yet
const HOME = [40.5853, -105.0844];

// From this share of the capacity, a load is nearly full (TrailerLoad::NEARLY_FULL)
const NEARLY_FULL = 0.9;

// Adds or removes a pickup from the load being built; set by initBuilder
let toggle = () => {};

export function initMap(element) {
    const data = document.getElementById('map-data');
    const points = data ? JSON.parse(data.textContent) : [];
    const builder = document.getElementById('trailer-load');

    // Scrolling the page shouldn't zoom the map; the buttons and pinching do
    const map = L.map(element, { scrollWheelZoom: false });

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map);

    const markers = points.map((point) => {
        const marker = L.marker([point.lat, point.lng], {
            icon: dot(point, point.number ?? null),
            title: point.name,
        });

        if (builder && point.id) {
            // Choosing: a click adds or removes the pickup, and hovering names it
            marker.bindTooltip(label(point));
            marker.on('click', () => toggle(point.id));
        } else {
            marker.bindPopup(popup(point));
        }

        return { point, marker: marker.addTo(map) };
    });

    if (markers.length === 0) {
        map.setView(HOME, 12);
    } else if (markers.length === 1) {
        map.setView(markers[0].marker.getLatLng(), 15);
    } else {
        map.fitBounds(L.featureGroup(markers.map(({ marker }) => marker)).getBounds().pad(0.1));
    }

    if (builder) {
        initBuilder(builder, markers);
    }
}

// A new load's boxes and the dots, kept in step, with the progress bar
function initBuilder(form, markers) {
    const bags = JSON.parse(document.getElementById('load-bags')?.textContent ?? '{}');
    const order = JSON.parse(document.getElementById('load-order')?.textContent ?? '[]').map(String);
    const capacity = form.querySelector('[name="capacity"]');
    const bar = form.querySelector('[data-load-bar]');
    // Each pickup's box is on the page twice: in the table, and in the list shown on phones
    const boxes = () => [...document.querySelectorAll('input[type="checkbox"][form="trailer-load"]')];

    // The chosen pickups' IDs, once each: an edited load's stops in route
    // order, then the rest in the list's order
    const chosen = () => {
        const checked = [...new Set(boxes().filter((box) => box.checked).map((box) => box.value))];

        return [...order.filter((id) => checked.includes(id)), ...checked.filter((id) => !order.includes(id))];
    };

    const update = () => {
        const ids = chosen();

        markers.forEach(({ point, marker }) => {
            if (point.id) {
                const number = ids.indexOf(String(point.id)) + 1;
                marker.setIcon(dot(point, number || null));
            }
        });

        progress(bar, ids.length, ids.reduce((total, id) => total + (bags[id] ?? 0), 0), parseInt(capacity.value, 10) || 0);
    };

    toggle = (id) => {
        const same = boxes().filter((box) => box.value === String(id));
        const checked = !(same[0]?.checked ?? false);

        same.forEach((box) => {
            box.checked = checked;
        });
        update();
    };

    document.addEventListener('change', (event) => {
        const box = event.target;

        if (box instanceof HTMLInputElement && box.form === form && box.type === 'checkbox') {
            boxes().filter((other) => other.value === box.value).forEach((other) => {
                other.checked = box.checked;
            });
            update();
        }
    });
    capacity.addEventListener('input', update);

    update();
}

// The progress bar's fill, color, and words, as TrailerLoad::progress() writes them
function progress(bar, stops, bags, capacity) {
    const share = capacity > 0 ? bags / capacity : (bags > 0 ? 2 : 0);
    const room = capacity - bags;
    let tone = 'good';

    if (share > 1) {
        tone = 'over';
    } else if (share >= NEARLY_FULL) {
        tone = 'full';
    }

    bar.classList.remove('good', 'full', 'over');
    bar.classList.add(tone);
    bar.querySelector('.load-bar-fill').style.setProperty('--fill', `${Math.round(Math.min(share, 1) * 100)}%`);

    let rest = `${room.toLocaleString('en-US')} ${room === 1 ? 'bag' : 'bags'} of room`;

    if (room < 0) {
        rest = `${(-room).toLocaleString('en-US')} over`;
    } else if (room === 0) {
        rest = 'Full';
    }

    bar.querySelector('.load-bar-label').textContent = `${stops} ${stops === 1 ? 'stop' : 'stops'} · ${bags.toLocaleString('en-US')} of ${capacity.toLocaleString('en-US')} bags · ${rest}`;
}

// A dot in its kind's color: ringed for a pickup in a planned load, and
// larger with a number for a stop or a chosen pickup
function dot(point, number) {
    const span = document.createElement('span');
    const marked = number !== null || point.ringed;
    const size = point.kind === 'site' ? 22 : (marked ? 28 : 18);

    span.className = `map-dot ${point.kind}${marked ? ' marked' : ''}`;

    if (number !== null) {
        span.textContent = number;
    }

    return L.divIcon({ html: span, className: 'map-marker', iconSize: [size, size] });
}

// The words for a dot while choosing, such as "Dana Cruz · Ready now · 30
// bags", as text so nothing in them is read as HTML
function label(point) {
    const content = document.createElement('span');

    content.textContent = point.note ? `${point.name} · ${point.note}` : point.name;

    return content;
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
