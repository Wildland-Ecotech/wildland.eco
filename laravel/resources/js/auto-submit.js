// Menus that apply as soon as they change, such as the Sort menu on the
// admin Contacts page.
//
// Each menu with `data-auto-submit` sits in a plain GET form with its own
// button, so it works without JavaScript. When this script runs, changing
// the menu submits its form, keeping what's in the form's other fields,
// such as a search.

export function initAutoSubmit() {
    document.querySelectorAll('[data-auto-submit]').forEach((field) => {
        field.addEventListener('change', () => {
            if (field.form) {
                field.form.requestSubmit();
            }
        });
    });
}
