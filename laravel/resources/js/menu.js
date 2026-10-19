// Menu overlay toggle.
//
// Menu buttons are links to /menu, so the menu works without JavaScript.
// When this script runs, clicking a menu button opens the overlay instead.

export function initMenu() {
    const overlay = document.querySelector('[data-menu-overlay]');
    const toggles = document.querySelectorAll('[data-menu-toggle]');

    if (!overlay || toggles.length === 0) {
        return;
    }

    function setOpen(open) {
        overlay.hidden = !open;

        toggles.forEach((toggle) => {
            toggle.setAttribute('aria-expanded', String(open));
        });
    }

    toggles.forEach((toggle) => {
        toggle.addEventListener('click', (event) => {
            event.preventDefault();
            setOpen(overlay.hidden);
        });
    });

    // Close the overlay when following one of its links, such as /#programs
    overlay.querySelectorAll('[data-menu-close]').forEach((link) => {
        link.addEventListener('click', () => setOpen(false));
    });

    window.addEventListener('keyup', (event) => {
        if (event.key === 'Escape' && !overlay.hidden) {
            setOpen(false);
        }
    });
}
