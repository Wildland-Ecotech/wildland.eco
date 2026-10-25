// Proof-of-work spam protection for forms with data-proof-of-work; see
// app/Services/ProofOfWork.php for the challenge format.
//
// As soon as the page loads, this searches for a nonce such that
// SHA-256("<challenge>:<nonce>") starts with the prefix given in the challenge
// (such as "dddd"), so the answer is usually ready before the form is filled in.
// If the form is submitted first, the button shows "Verifying…" until the
// answer is found, then the form submits.
//
// This is the one form enhancement the server requires: without JavaScript,
// the form shows a notice with an email address to apply by instead.

export function initProofOfWork() {
    const forms = document.querySelectorAll('form[data-proof-of-work]');

    forms.forEach(setUpForm);

    // A page restored from the back/forward cache, such as returning from
    // the payment page with the browser's Back button, still holds a
    // challenge the server has already used. Reload it for a fresh one.
    if (forms.length) {
        window.addEventListener('pageshow', (event) => {
            if (event.persisted) {
                window.location.reload();
            }
        });
    }
}

function setUpForm(form) {
    const challengeInput = form.querySelector('[data-proof-of-work-challenge]');
    const nonceInput = form.querySelector('[data-proof-of-work-nonce]');
    const button = form.querySelector('[data-proof-of-work-button]');

    // crypto.subtle is only available over HTTPS and on localhost
    if (!challengeInput || !nonceInput || !button || !window.crypto?.subtle) {
        return;
    }

    const work = solve(challengeInput.value).then((nonce) => {
        nonceInput.value = nonce;
    });

    let waiting = false;

    form.addEventListener('submit', async (event) => {
        if (nonceInput.value) {
            return;
        }

        event.preventDefault();

        if (waiting) {
            return;
        }

        waiting = true;

        const label = button.textContent;
        button.disabled = true;
        button.textContent = 'Verifying…';

        try {
            await work;
            // Submits without firing another submit event
            form.submit();
        } catch {
            button.disabled = false;
            button.textContent = label;
            waiting = false;
        }
    });
}

async function solve(challenge) {
    const prefix = challenge.split('.')[2];
    // Each byte is two hex digits, so only the first few bytes need converting
    const bytesToCheck = Math.ceil(prefix.length / 2);
    const encoder = new TextEncoder();

    for (let nonce = 0; ; nonce++) {
        const digest = await crypto.subtle.digest('SHA-256', encoder.encode(`${challenge}:${nonce}`));

        if (toHex(new Uint8Array(digest, 0, bytesToCheck)).startsWith(prefix)) {
            return String(nonce);
        }
    }
}

function toHex(bytes) {
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}
