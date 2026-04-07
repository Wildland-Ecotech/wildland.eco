<script>
    let { color } = $props();
</script>
<style>
    h2, h2 {
        display: block;
    }
    .button.full {
        background-color: rgba(46, 207, 158, 0.25);
        color: #fff;
    }
    .button.hire {
        padding-left: 5.8rem;
        padding-right: 5.8rem;
    }
    @media screen and (max-width: 599px) {
        .button {
            width: 100%;
            margin-bottom: 12px;
        }
    }

    .footer {
        position: relative;
        padding: 4rem 2rem;
    }
    @media screen and (min-width: 600px) {
        .footer {
            padding: 16rem 6rem 10rem;
        }
    }
    .footer::before {
        content: '';
        display: block;
        position: absolute;
        bottom: 0;
        right: 0;
        width: 100%;
        height: 100%;
        background-repeat: no-repeat;
        background-position: bottom right;
    }
    .footer-content {
        display: block;
        position: relative;
        z-index: 1;
    }
    @media screen and (min-width: 600px) {
        .footer-content {
            width: 65%;
        }
    }
    .footer--green {
        background-image: linear-gradient(to bottom, rgba(169, 180, 38, 0), rgba(16, 56, 24, 1) 50%);
    }
    .footer--green::before {
        background-image: url('$lib/images/bubbles-green.svg');
    }
</style>

<div class="footer footer--{color}">
    <div class="footer-content">
        <h3>Want to make a difference?</h3>
        <h1>Help us restore wildlands for future generations.</h1>
        <div class="buttons">
            <a class="button hire full" href="mailto:wildland@wetfish.net">Get In Touch</a>
        </div>
        <p style="font-size: 14px; opacity: 0.6; margin-top: 2em;">Wildland Ecotech is a 501(c)(3) nonprofit organization.</p>
    </div>
</div>