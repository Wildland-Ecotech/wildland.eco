<script>
    import MenuButton from '$lib/components/MenuButton.svelte';
</script>

<style lang="scss">
    .top-nav {
        position: fixed;
        display: flex;
        justify-content: space-between;
        align-items: center;
        width: calc(100% - 2em);
        margin: 1em;
        z-index: 100;
    }

    .wordmark {
        font-family: redhat;
        font-size: 24px;
        color: #fff;
        text-decoration: none;
        letter-spacing: 0.02em;
        background-color: rgba(0, 0, 0, 0.3);
        padding: 0.3em 0.6em;
        border-radius: 10px;

        .eco {
            color: #2ECF9E;
        }

        &:hover {
            animation: trippy 2s linear infinite;
        }
    }

    @media screen and (min-width: 1024px) {
        .top-nav {
            width: calc(100% - 8em);
            margin: 1em 4em;
        }

        .wordmark {
            font-size: 28px;
        }
    }

    @keyframes trippy {
        0% { filter: hue-rotate(0deg); }
        50% { filter: hue-rotate(180deg); }
        100% { filter: hue-rotate(360deg); }
    }
</style>

<div class="top-nav">
    <a href="/" class="wordmark" aria-label="Wildland Ecotech Home Page">
        Wildland <span class="eco">Ecotech</span>
    </a>

    <MenuButton />
</div>