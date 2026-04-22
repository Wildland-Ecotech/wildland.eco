<script>
    let { open = false, title = '', onclose, children } = $props();

    function handleBackdropClick(e) {
        if (e.target === e.currentTarget) {
            onclose();
        }
    }

    function handleKeydown(e) {
        if (open && e.key === 'Escape') {
            onclose();
        }
    }
</script>

<style lang="scss">
    .modal-backdrop {
        position: fixed;
        inset: 0;
        z-index: 2000;
        background: rgba(0, 0, 0, 0.7);
        backdrop-filter: blur(8px);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2em;
        animation: fade-in 0.25s ease-out;
    }

    .modal-wrapper {
        position: relative;
        max-width: 800px;
        width: 100%;
        animation: slide-up 0.3s ease-out;
    }

    .close-button {
        position: absolute;
        top: -48px;
        right: 0;
        background: none;
        border: none;
        color: #2ECF9E;
        font-size: 24px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: satoshi;
        padding: 0.5em;
        z-index: 10;

        &:hover {
            color: #fff;
        }
    }

    @media screen and (max-width: 599px) {
        .close-button {
            top: 165px;
            right: 0.5em;
            background: none;
            border-radius: 0;
        }
    }

    @keyframes fade-in {
        0% { opacity: 0; }
        100% { opacity: 1; }
    }

    @keyframes slide-up {
        0% { transform: translateY(20px); opacity: 0; }
        100% { transform: translateY(0); opacity: 1; }
    }
</style>

{#if open}
    <div
        class="modal-backdrop"
        onclick={handleBackdropClick}
        role="dialog"
        aria-modal="true"
        aria-label={title}
    >
        <div class="modal-wrapper">
            <button class="close-button" onclick={onclose} aria-label="Close">✕</button>
            {@render children?.()}
        </div>
    </div>
{/if}

<svelte:window onkeydown={handleKeydown} />