// Fades content out as it scrolls up under the fixed top nav, using a
// mask image recalculated on scroll and resize. Without JavaScript the
// content simply scrolls under the nav.

export function initDisappearingContent() {
    const elements = document.querySelectorAll('[data-disappearing-content]');

    if (elements.length === 0) {
        return;
    }

    function update() {
        elements.forEach((element) => {
            const box = element.getBoundingClientRect();
            const bottom = box.height + box.top;

            const mask = `linear-gradient(
                0deg,
                rgba(0, 0, 0, 1),
                rgba(0, 0, 0, 1) ${bottom - 150}px,
                transparent ${bottom - 65}px
            )`;

            element.style.setProperty('mask-image', mask);
            element.style.setProperty('-webkit-mask-image', mask);
        });
    }

    update();
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
}
