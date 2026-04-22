<svelte:head>
    <title>Menu — Wildland Ecotech</title>
</svelte:head>

<style lang="scss">
    .menu-page {
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 2em;
    }

    .links {
        list-style: none;
        padding: 0;
        margin: 0;
        text-align: center;

        li {
            margin: 0;
        }
    }

    .back {
        margin-top: 2em;
        font-family: satoshi;
        font-size: 16px;
        color: rgba(255, 255, 255, 0.5);
        text-decoration: none;

        &:hover {
            color: #2ECF9E;
        }
    }

    @media screen and (min-width: 1024px) {
        .links {
            :global(.button) {
                font-size: 32px;
                padding: 0.7em 1.5em;
            }
        }
    }
</style>

<div class="menu-page">
    <ul class="links">
        <li>
            <a class="button full" href="/">Home</a>
        </li>

        <li>
            <a class="button" href="/#programs">Our Programs</a>
        </li>

        <li>
            <a class="button" href="/#projects">Our Projects</a>
        </li>

        <li>
            <a class="button" href="mailto:hello@wildland.eco">Contact Us</a>
        </li>
    </ul>

    <a class="back" href="/">← Back to site</a>
</div>