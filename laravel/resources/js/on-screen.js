// Reports when an element comes on screen and when it leaves, so continuous
// animations can pause while nobody can see them and leave the browser free
// to scroll smoothly. Elements that are not displayed at all, such as blobs
// inside a closed modal, count as off screen.

// Report elements as on screen a little before they scroll into view, so
// their animation is already running when they appear
const MARGIN = '200px';

export function watchOnScreen(element, callback) {
    if (!('IntersectionObserver' in window)) {
        callback(true);
        return;
    }

    new IntersectionObserver((entries) => {
        callback(entries[entries.length - 1].isIntersecting);
    }, { rootMargin: MARGIN }).observe(element);
}
