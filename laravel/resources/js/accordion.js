// Accordion enhancements on top of native <details> elements:
// - the open section stays open when its button is clicked again
// - the "tap to learn more" hint disappears after the first click
// - the newly opened section's button scrolls to a third of the way down
//   the screen, so its content is in view
// Browsers without exclusive <details> support (the name attribute) also
// get the other sections closed here.

export function initAccordions() {
    document.querySelectorAll('[data-accordion]').forEach((accordion) => {
        const items = accordion.querySelectorAll('details');
        const hint = accordion.querySelector('[data-accordion-hint]');

        items.forEach((item) => {
            const summary = item.querySelector('summary');

            summary.addEventListener('click', (event) => {
                if (hint) {
                    hint.hidden = true;
                }

                if (item.open) {
                    event.preventDefault();
                    return;
                }

                items.forEach((other) => {
                    if (other !== item) {
                        other.open = false;
                    }
                });

                // Wait for the section to open before measuring its position
                setTimeout(() => {
                    const top = summary.getBoundingClientRect().top + window.scrollY;
                    window.scrollTo({ top: top - window.innerHeight * 0.33 });
                }, 10);
            });
        });
    });
}
