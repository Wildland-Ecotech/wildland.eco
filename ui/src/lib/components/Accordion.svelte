<script>
	let { options, ...sections } = $props();
    let activeValue = $state(options[0].id);
    let hasInteracted = $state(false);

    function setActiveValue(value) {
        activeValue = value;
        hasInteracted = true;

        // Wait 10ms to allow the content to be re-rendered before scrolling
        setTimeout(() => {
            const el = document.getElementById(value);
            const buttonPosition = el.getBoundingClientRect().top + window.scrollY;
            const windowHeight = window.innerHeight;

            // Adjust the scroll position to center the content on the screen
            window.scrollTo({top: buttonPosition - (windowHeight * 0.33)});
        }, 10);
    }
</script>

<style>
    .accordion-wrapper {
        position: relative;
    }

    .tap-hint {
        position: absolute;
        width: 90%;
        min-width: 325px;
        text-align: center;
        margin-top: 0.6em;
        margin-left: -1.5em;
        pointer-events: none;
    }

    .tap-hint-inner {
        position: relative;
        display: inline-block;
        font-family: satoshi;
        font-size: 19px;
        color: rgba(255, 255, 255, 0.5);
    }

    .tap-hint-emoji {
        position: absolute;
        right: 100%;
        margin-right: 0.3em;
        font-size: 24px;
        animation: bounce 1.2s ease-in-out infinite;
    }

    @keyframes bounce {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-5px); }
    }
</style>

<div class="accordion-wrapper">
    {#each options as option, index}
        <button
            id={option.id}
            class="button wide"
            class:dark={activeValue !== option.id}
            class:full={activeValue === option.id}
            onclick={() => setActiveValue(option.id)}
        >
            {option.title}
        </button>

        {#if activeValue === option.id}
            {@render sections[option.id]()}
        {/if}
    {/each}

    {#if !hasInteracted}
        <div class="tap-hint">
            <span class="tap-hint-inner">
                <span class="tap-hint-emoji">👆</span>
                tap to learn more
            </span>
        </div>
    {/if}
</div>