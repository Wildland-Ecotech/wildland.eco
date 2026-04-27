<script lang="ts">
    import { onMount } from 'svelte';

    interface Props {
        children?: import('svelte').Snippet;
    }

    let { children }: Props = $props();
    let content: HTMLElement = $state();

    // TODO: For best performance only change styles on the content when it is visible on the page?
    function handleScroll() {
        const boundingBox = content.getBoundingClientRect();

        content.style['mask-image'] =
            `linear-gradient(
                0deg,
                rgba(0, 0, 0, 1),
                rgba(0, 0, 0, 1) ${((boundingBox.height + boundingBox.top) - 150)}px,
                transparent ${(boundingBox.height + boundingBox.top) - 65}px
            )`;

        // It's 2023 and mask image has been around for over 10 years... why is this still prefixed in chrome? 🙄
        content.style['-webkit-mask-image'] =
            `linear-gradient(
                0deg,
                rgba(0, 0, 0, 1),
                rgba(0, 0, 0, 1) ${((boundingBox.height + boundingBox.top) - 150)}px,
                transparent ${(boundingBox.height + boundingBox.top) - 65}px
            )`;
    }

    onMount(() => {
        handleScroll();
    });
</script>

<style>
    .disappearing-content {
        position: relative;
        z-index: 1;
    }
</style>

<div bind:this={content} class="disappearing-content">
    {@render children?.()}
</div>

<svelte:window onscroll={handleScroll} onresize={handleScroll} />