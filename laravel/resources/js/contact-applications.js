// The application menu on the admin edit page for timeline entries.
//
// The menu lists the entry's contact's applications. When the Contact menu
// (`data-contact-select`) changes to another contact, this script sets the
// application to None and disables it, showing the hint
// (`data-contact-changed-hint`) to choose one after saving. Changing back
// restores it. Without this script, the server refuses applications of
// another contact with a message.

export function initContactApplications() {
    const contact = document.querySelector('[data-contact-select]');
    const application = document.getElementById('leaf_application_id');
    const hint = document.querySelector('[data-contact-changed-hint]');

    if (!contact || !application) {
        return;
    }

    const original = contact.value;
    const chosen = application.value;

    contact.addEventListener('change', () => {
        const changed = contact.value !== original;

        application.disabled = changed;
        application.value = changed ? '' : chosen;

        if (hint) {
            hint.hidden = !changed;
        }
    });
}
