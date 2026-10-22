// Fades content out as it scrolls up under the fixed top nav, using a
// mask image recalculated on scroll and resize. Without JavaScript the
// content simply scrolls under the nav.
//
// The fade is positioned from the top of the content, which stays put when
// something below it changes height (opening a <details> element, showing a
// form field). The mask therefore stays correct without being recalculated,
// and never flickers while the page grows or shrinks.

// Distance from the top of the window where content is fully faded out, and
// where it is fully visible again, in pixels
const FADED_AT = 65;
const VISIBLE_AT = 150;

export function initDisappearingContent() {
    const elements = document.querySelectorAll('[data-disappearing-content]');

    if (elements.length === 0) {
        return;
    }

    function update() {
        elements.forEach((element) => {
            const top = element.getBoundingClientRect().top;

            const mask = `linear-gradient(
                to bottom,
                transparent ${FADED_AT - top}px,
                rgba(0, 0, 0, 1) ${VISIBLE_AT - top}px
            )`;

            element.style.setProperty('mask-image', mask);
            element.style.setProperty('-webkit-mask-image', mask);
        });
    }

    update();
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
}
