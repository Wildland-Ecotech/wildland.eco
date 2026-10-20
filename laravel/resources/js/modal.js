// Modal enhancements. Without JavaScript, links to #modal-id show the
// modal through the CSS :target selector. With JavaScript, those links open
// the modal without changing the URL, and the modal also closes with
// Escape or a click on the backdrop. Focus moves into the modal on open
// and returns to the link that opened it on close.

export function initModals() {
    const modals = document.querySelectorAll('[data-modal]');

    if (modals.length === 0) {
        return;
    }

    let opener = null;

    function isShown(modal) {
        return modal.classList.contains('is-open')
            || (modal.matches(':target') && !modal.classList.contains('is-closed'));
    }

    function open(modal, trigger) {
        modal.classList.remove('is-closed');
        modal.classList.add('is-open');
        opener = trigger;
        modal.querySelector('[data-modal-close]')?.focus();
    }

    function close(modal) {
        modal.classList.remove('is-open');
        modal.classList.add('is-closed');
        opener?.focus();
        opener = null;
    }

    document.querySelectorAll('[data-modal-open]').forEach((link) => {
        const modal = document.getElementById(link.getAttribute('href').slice(1));

        if (!modal) {
            return;
        }

        link.addEventListener('click', (event) => {
            event.preventDefault();
            open(modal, link);
        });
    });

    modals.forEach((modal) => {
        modal.querySelectorAll('[data-modal-close]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                close(modal);
            });
        });

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                close(modal);
            }
        });
    });

    window.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        modals.forEach((modal) => {
            if (isShown(modal)) {
                close(modal);
            }
        });
    });
}
