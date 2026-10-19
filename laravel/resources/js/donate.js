// Donate form enhancements: filters the custom amount as it is typed,
// clears selections when the frequency changes, and shows the selected
// amount on the donate button.
//
// The donate button stays disabled until Stripe is integrated
// (milestone 6 in docs/06-planned-features.md).

const MIN_AMOUNT = 1;
const MAX_AMOUNT = 9999;

export function initDonateForm() {
    const form = document.querySelector('[data-donate-form]');

    if (!form) {
        return;
    }

    const frequencyInputs = form.querySelectorAll('[data-frequency]');
    const tierInputs = form.querySelectorAll('[data-tier]');
    const customInput = form.querySelector('[data-custom-amount]');
    const button = form.querySelector('[data-donate-button]');

    function selectedFrequency() {
        return form.querySelector('[data-frequency]:checked');
    }

    function selectedTier() {
        return form.querySelector('[data-tier]:checked');
    }

    function validCustomAmount() {
        const amount = parseFloat(customInput.value);

        if (isNaN(amount) || amount < MIN_AMOUNT || amount > MAX_AMOUNT) {
            return null;
        }

        return amount;
    }

    function formatAmount(amount) {
        if (amount % 1 !== 0) {
            return `$${amount.toFixed(2)}`;
        }

        return `$${amount.toLocaleString()}`;
    }

    function updateButtonLabel() {
        const tier = selectedTier();
        const amount = tier ? Number(tier.value) : validCustomAmount();

        if (amount === null) {
            button.textContent = 'Select an amount';
            return;
        }

        const suffix = selectedFrequency()?.dataset.suffix ?? '';
        button.textContent = `Donate ${formatAmount(amount)}${suffix}`;
    }

    function handleCustomInput() {
        // Strip anything that isn't a digit or decimal point
        let raw = customInput.value.replace(/[^\d.]/g, '');

        // Only allow one decimal point
        const parts = raw.split('.');
        if (parts.length > 2) {
            raw = parts[0] + '.' + parts.slice(1).join('');
        }

        // Limit to 2 decimal places
        if (parts.length === 2 && parts[1].length > 2) {
            raw = parts[0] + '.' + parts[1].slice(0, 2);
        }

        // Cap at the maximum amount
        const amount = parseFloat(raw);
        if (!isNaN(amount) && amount > MAX_AMOUNT) {
            raw = String(MAX_AMOUNT);
        }

        customInput.value = raw;

        // Only deselect the tier once the custom amount is valid
        if (validCustomAmount() !== null) {
            tierInputs.forEach((input) => {
                input.checked = false;
            });
        }

        updateButtonLabel();
    }

    customInput.addEventListener('input', handleCustomInput);

    tierInputs.forEach((input) => {
        input.addEventListener('change', () => {
            customInput.value = '';
            updateButtonLabel();
        });
    });

    frequencyInputs.forEach((input) => {
        input.addEventListener('change', () => {
            tierInputs.forEach((tier) => {
                tier.checked = false;
            });
            customInput.value = '';
            updateButtonLabel();
        });
    });

    // Browsers can restore form state when navigating back to the page
    updateButtonLabel();
}
