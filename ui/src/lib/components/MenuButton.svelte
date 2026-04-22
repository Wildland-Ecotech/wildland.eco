<style lang="scss">
    .menu-button {
        font-size: 24pt;
        border: 2px solid #0EE5A3;
        border-radius: 2px;
        cursor: pointer;
        background-color: RGBA(46, 207, 158, 0.25);
        color: #fff;
        height: 32px !important;
        padding: 0.1em 0.25em 0.3em 0.25em;
        text-decoration: none;
        display: flex;
        align-items: center;
        line-height: 1;

        &:hover {
            background-color: transparent;
            animation: trippy 2s linear infinite;
        }
    }

    @keyframes trippy {
        0% { backdrop-filter: hue-rotate(0deg); }
        50% { backdrop-filter: hue-rotate(180deg); }
        100% { backdrop-filter: hue-rotate(360deg); }
    }
</style>

<script>
    import { menuStore } from '$lib/store.js';

	let menuIsOpen;

	menuStore.subscribe((value) => {
		menuIsOpen = value;
	});

    function handleClick(event) {
        // With JS: prevent navigation and toggle overlay
        event.preventDefault();
        menuStore.set(!menuIsOpen);
    }

    function handleKeyUp(event) {
        if(menuIsOpen) {
            if(event.key === "Escape") {
                menuStore.set(false);
            }
        }
    }
</script>

<!-- Without JS: navigates to /menu. With JS: opens overlay -->
<a href="/menu" class="menu-button" onclick={handleClick} aria-label="Menu">≡</a>

<svelte:window onkeyup={handleKeyUp} />