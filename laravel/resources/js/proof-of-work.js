// Proof-of-work spam protection for forms with data-proof-of-work; see
// app/Services/ProofOfWork.php for the challenge format.
//
// As soon as the page loads, this searches for a nonce such that
// SHA-256("<challenge>:<nonce>") starts with the prefix given in the challenge
// (such as "dddd"), so the answer is usually ready before the form is filled in.
// If the form is submitted first, the button shows "Verifying…" until the
// answer is found, then the form submits.
//
// A page left open goes stale: the session behind its CSRF token and its
// challenge both expire after two hours. Once the page has been open for
// STALE_AFTER, this fetches a fresh CSRF token and challenge from the form's
// data-form-tokens-url (FormTokenController) before submitting, and solves
// the new challenge. It also refreshes them ahead of time when the visitor
// comes back to the tab, so the submit doesn't wait.
//
// This is the one form enhancement the server requires: without JavaScript,
// the form shows a notice with an email address to apply by instead.

// Refresh tokens that are older than this, well before they expire
const STALE_AFTER = 30 * 60 * 1000;

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
    const csrfInput = form.querySelector('input[name="_token"]');
    const tokensUrl = form.dataset.formTokensUrl;

    // crypto.subtle is only available over HTTPS and on localhost
    if (!challengeInput || !nonceInput || !button || !window.crypto?.subtle) {
        return;
    }

    let issuedAt = Date.now();
    let work = start(challengeInput.value);
    let refreshing = null;
    let waiting = false;

    // Solves a challenge, filling in the nonce only if the challenge hasn't
    // been replaced in the meantime
    function start(challenge) {
        nonceInput.value = '';

        return solve(challenge).then((nonce) => {
            if (challengeInput.value === challenge) {
                nonceInput.value = nonce;
            }
        });
    }

    function isStale() {
        return Date.now() - issuedAt > STALE_AFTER;
    }

    // Fetches a fresh CSRF token and challenge, and starts solving the new
    // challenge. Several calls at once share one request.
    function refresh() {
        if (!tokensUrl || !csrfInput) {
            return Promise.resolve();
        }

        refreshing ??= fetch(tokensUrl, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Form tokens request failed with ${response.status}`);
                }

                return response.json();
            })
            .then((tokens) => {
                csrfInput.value = tokens.csrf;
                challengeInput.value = tokens.challenge;
                issuedAt = Date.now();
                work = start(tokens.challenge);
            })
            .finally(() => {
                refreshing = null;
            });

        return refreshing;
    }

    // Coming back to a tab left open: refresh ahead of the submit. A failure
    // here is retried when the form is submitted.
    const refreshIfStale = () => {
        if (document.visibilityState === 'visible' && isStale()) {
            refresh().catch(() => {});
        }
    };

    document.addEventListener('visibilitychange', refreshIfStale);
    window.addEventListener('focus', refreshIfStale);

    form.addEventListener('submit', async (event) => {
        if (nonceInput.value && !isStale()) {
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
            if (isStale()) {
                // If this fails, the form is sent with its current tokens, and
                // the server explains any problem
                await refresh().catch(() => {});
            }

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
