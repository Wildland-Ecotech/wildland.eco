// Draws a rotating rainbow gradient border on a canvas behind an element.
// The element keeps its CSS color-cycling border when JavaScript or canvas
// conic gradients are unavailable.
//
// The canvas is positioned and sized in CSS to extend BORDER_WIDTH past
// each edge of the element (see .social-cta canvas in _home.scss).

const BORDER_WIDTH = 4;
const RADIUS = 16;

// Draw the border slightly inside the element's edge so fractional pixel
// sizes never leave a gap between the border and the element's background
const OVERLAP = 0.5;

function initRainbowBorder(element) {
    const canvas = element.querySelector('canvas');
    const ctx = canvas?.getContext?.('2d');

    if (!ctx || typeof ctx.createConicGradient !== 'function') {
        return;
    }

    element.style.border = 'none';
    element.style.animation = 'none';

    function updateCanvas() {
        // Exact rendered size, including fractions of a pixel
        const { width: w, height: h } = canvas.getBoundingClientRect();
        const dpr = window.devicePixelRatio || 1;

        canvas.width = Math.round(w * dpr);
        canvas.height = Math.round(h * dpr);

        // Map drawing coordinates (CSS pixels) onto the whole pixel buffer
        ctx.setTransform(canvas.width / w, 0, 0, canvas.height / h, 0, 0);

        return { w, h };
    }

    let dims = updateCanvas();

    new ResizeObserver(() => {
        dims = updateCanvas();
    }).observe(element);

    function roundedRect(x, y, rw, rh, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.lineTo(x + rw - r, y);
        ctx.arcTo(x + rw, y, x + rw, y + r, r);
        ctx.lineTo(x + rw, y + rh - r);
        ctx.arcTo(x + rw, y + rh, x + rw - r, y + rh, r);
        ctx.lineTo(x + r, y + rh);
        ctx.arcTo(x, y + rh, x, y + rh - r, r);
        ctx.lineTo(x, y + r);
        ctx.arcTo(x, y, x + r, y, r);
        ctx.closePath();
    }

    function draw(time) {
        const { w, h } = dims;
        ctx.clearRect(0, 0, w, h);

        const angle = -(time / 1000);
        const gradient = ctx.createConicGradient(angle, w / 2, h / 2);

        for (let i = 0; i <= 6; i++) {
            gradient.addColorStop(i / 6, `hsl(${(i / 6) * 360}, 100%, 60%)`);
        }

        ctx.strokeStyle = gradient;
        ctx.lineWidth = BORDER_WIDTH;

        // The stroke is centered on the path, so a path BORDER_WIDTH / 2 in
        // from the canvas edge places the border just outside the element
        const inset = BORDER_WIDTH / 2 + OVERLAP;
        roundedRect(inset, inset, w - inset * 2, h - inset * 2, RADIUS);
        ctx.stroke();

        requestAnimationFrame(draw);
    }

    requestAnimationFrame(draw);
}

export function initRainbowBorders() {
    document.querySelectorAll('[data-rainbow-border]').forEach(initRainbowBorder);
}
