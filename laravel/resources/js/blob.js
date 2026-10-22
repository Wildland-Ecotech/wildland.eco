// Morphs each blob between the shapes defined in blob-shapes.blade.php.
//
// Each SVG outline is sampled into radii at evenly spaced angles around
// the center, so any two shapes can be blended point by point. The blob
// grows to the union of the two shapes before shrinking into the next
// one. Blobs with data-blob-floor never shrink below their base shape.
// Without JavaScript, blobs keep their static clip-path shape.
//
// While a blob is off screen (including inside a closed modal), its morphing
// stops and its spinning background layers pause (.is-paused in _blob.scss),
// so the animations never compete with scrolling.

import { watchOnScreen } from './on-screen';

const SVG_NS = 'http://www.w3.org/2000/svg';
const SHAPE_COUNT = 10;
const NUM_ANGLES = 64;
const ARC_SAMPLES = 512;
const MORPH_DURATION = 5000;
const CENTER = 0.5;

function samplePathPolar(pathD, svg) {
    const path = document.createElementNS(SVG_NS, 'path');
    path.setAttribute('d', pathD);
    svg.appendChild(path);

    const totalLength = path.getTotalLength();
    const arcPoints = [];
    for (let i = 0; i < ARC_SAMPLES; i++) {
        const pt = path.getPointAtLength((i / ARC_SAMPLES) * totalLength);
        arcPoints.push([pt.x, pt.y]);
    }
    svg.removeChild(path);

    const radii = new Array(NUM_ANGLES).fill(0);
    for (const [x, y] of arcPoints) {
        const dx = x - CENTER;
        const dy = y - CENTER;
        let angle = Math.atan2(dy, dx);
        if (angle < 0) angle += 2 * Math.PI;

        const slot = Math.round((angle / (2 * Math.PI)) * NUM_ANGLES) % NUM_ANGLES;
        const dist = Math.sqrt(dx * dx + dy * dy);
        if (dist > radii[slot]) radii[slot] = dist;
    }

    for (let i = 0; i < NUM_ANGLES; i++) {
        if (radii[i] === 0) {
            const prev = radii[(i - 1 + NUM_ANGLES) % NUM_ANGLES];
            const next = radii[(i + 1) % NUM_ANGLES];
            radii[i] = (prev + next) / 2;
        }
    }

    return radii;
}

function radiiToPolygon(radii) {
    const points = [];
    for (let i = 0; i < radii.length; i++) {
        const angle = (i / radii.length) * 2 * Math.PI;
        const x = (CENTER + radii[i] * Math.cos(angle)) * 100;
        const y = (CENTER + radii[i] * Math.sin(angle)) * 100;
        points.push(`${x}% ${y}%`);
    }
    return `polygon(${points.join(', ')})`;
}

function easeInOut(t) {
    return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
}

function animateBlob(element, allRadii) {
    const hasFloor = element.hasAttribute('data-blob-floor');
    const baseIndex = Number(element.dataset.blobShape) - 1;
    const baseRadii = allRadii[baseIndex];

    let currentIndex = baseIndex;
    let targetIndex;
    const currentRadii = baseRadii.slice();
    let fromRadii = currentRadii.slice();
    let toRadii;

    function pickNewTarget() {
        let newIndex;
        do {
            newIndex = Math.floor(Math.random() * allRadii.length);
        } while (newIndex === currentIndex);

        fromRadii = currentRadii.slice();
        toRadii = allRadii[newIndex];
        targetIndex = newIndex;
    }

    pickNewTarget();
    let morphStart = performance.now();

    // Blobs start paused until they are reported on screen. pausedAt lets a
    // morph continue from where it stopped instead of jumping ahead.
    let onScreen = false;
    let frame = null;
    let pausedAt = morphStart;

    function animate(time) {
        if (!onScreen) {
            frame = null;
            pausedAt = time;
            return;
        }

        if (pausedAt !== null) {
            morphStart += time - pausedAt;
            pausedAt = null;
        }

        const elapsed = time - morphStart;
        const rawT = Math.min(elapsed / MORPH_DURATION, 1);
        const t = easeInOut(rawT);

        for (let i = 0; i < NUM_ANGLES; i++) {
            const from = fromRadii[i];
            const to = toRadii[i];
            const unionRadius = Math.max(from, to);

            let morphed;
            if (t < 0.5) {
                const t2 = t * 2;
                morphed = from + (unionRadius - from) * t2;
            } else {
                const t2 = (t - 0.5) * 2;
                morphed = unionRadius + (to - unionRadius) * t2;
            }

            currentRadii[i] = hasFloor ? Math.max(baseRadii[i], morphed) : morphed;
        }

        element.style.clipPath = radiiToPolygon(currentRadii);

        if (rawT >= 1) {
            currentIndex = targetIndex;
            pickNewTarget();
            morphStart = time;
        }

        frame = requestAnimationFrame(animate);
    }

    const container = element.closest('.blob-container') ?? element;

    watchOnScreen(container, (visible) => {
        onScreen = visible;
        container.classList.toggle('is-paused', !visible);

        if (visible && frame === null) {
            frame = requestAnimationFrame(animate);
        }
    });
}

export function initBlobs() {
    const blobs = document.querySelectorAll('[data-blob]');

    if (blobs.length === 0) {
        return;
    }

    // Read the shape outlines from the clip-path definitions
    const shapePaths = [];
    for (let i = 1; i <= SHAPE_COUNT; i++) {
        const path = document.querySelector(`#blob-${i} path`);

        if (!path) {
            return;
        }

        shapePaths.push(path.getAttribute('d'));
    }

    // Measure the paths in a temporary SVG attached to the page
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.style.cssText = 'position:absolute;width:0;height:0;overflow:hidden';
    document.body.appendChild(svg);
    const allRadii = shapePaths.map((d) => samplePathPolar(d, svg));
    document.body.removeChild(svg);

    blobs.forEach((element) => animateBlob(element, allRadii));
}
