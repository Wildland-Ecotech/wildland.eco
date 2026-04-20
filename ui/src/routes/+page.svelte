<svelte:head>
    <title>Wildland Ecotech — Restoring Landscapes, Building Abundance</title>
</svelte:head>

<style lang="scss">
    .cta-wrapper {
        position: absolute;
        z-index: 10;
        top: 100px;
        left: 50%;
        transform: translateX(-50%);

        canvas {
            position: absolute;
            pointer-events: none;
        }
    }

    .social-cta {
        position: relative;
        width: 320px;
        border-radius: 14px;
        border: 4px solid #ff0000;
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(4px);
        padding: 16px 24px;
        text-align: center;
        box-sizing: border-box;
        animation: rainbow-border 4s linear infinite;

        .cta-text {
            position: relative;
            font-family: satoshi;
            font-size: 18px;
            color: #fff;
            margin-bottom: 0.6em;
        }

        .cta-links {
            position: relative;
            display: flex;
            justify-content: center;
            gap: 0.75em;
            flex-wrap: wrap;
        }

        .cta-links a {
            font-family: satoshi;
            font-size: 16px;
            color: #2ECF9E;
            text-decoration: none;
            padding: 0.3em 0.5em;
            display: flex;
            align-items: center;
            gap: 0.4em;

            svg {
                width: 18px;
                height: 18px;
                fill: currentColor;
            }

            &:hover {
                color: #fff;
            }
        }

        .cta-divider {
            color: rgba(255, 255, 255, 0.3);
            font-size: 16px;
            align-self: center;
        }
    }

    @media screen and (min-width: 768px) {
        .social-cta {
            width: 580px;
            padding: 20px 32px;

            .cta-text {
                font-size: 21px;
            }

            .cta-links a {
                font-size: 18px;
                padding: 0.3em 1em;

                svg {
                    width: 20px;
                    height: 20px;
                }
            }

            .cta-divider {
                font-size: 18px;
            }
        }
    }

    @keyframes rainbow-border {
        0% { border-color: #ff0000; }
        16% { border-color: #ff8800; }
        33% { border-color: #ffff00; }
        50% { border-color: #00ff00; }
        66% { border-color: #0088ff; }
        83% { border-color: #8800ff; }
        100% { border-color: #ff0000; }
    }

    .content-background {
        .header {
            position: absolute;
            width: 100%;
            min-height: 1600px;
            background-image:url('$lib/images/bg-nature.png');
            background-position: left;
            background-repeat: no-repeat;
            background-size: cover;
        }

        .gradient {
            position: absolute;
            width: 100%;
            height: 800px;
            top: 800px;
            background-image: linear-gradient(0deg,#010902ff 0%, #01090200 100%);
            z-index: 1;
        }

        .fireflies {
            position: absolute;
            z-index: 0;
            width: 100%;
            min-height: 1600px;
            background-image:url('$lib/images/bg-fireflies.png');
            background-position: left;
            background-repeat: no-repeat;
            background-size: cover;
            opacity: 0.75;
            top: 1600px;
        }
    }

    strong {
        text-decoration: underline;
    }

    .content {
        display: flex;
        justify-content: flex-start;

        .text {
            padding: 1em;
        }

        &.one {
            padding-top: 275px;
        }

        &.two {
            padding-top: 100px;
        }

        &.three {
            padding-top: 150px;
            padding-bottom: 150px;
            flex-direction: column;
        }
    }

    @media screen and (min-width: 460px) {
        .content {
            padding-left: 2em;
            padding-right: 2em;

            &.one {
                padding-top: 325px;

                h1 {
                    width: 60%;
                }
            }
        }
    }

    @media screen and (min-width: 768px) {
        .content {
            padding: 500px 2em 0;

            .text {
                width: 80%;
            }

            &.two {
                justify-content: flex-end;
            }
        }
    }

    @media screen and (min-width: 1024px) {
        .content {
            padding: 500px 8em 0;

            &.one {
                h1 {
                    width: 33%;
                }

                .text {
                    width: 66%;
                }
            }

            &.two {
                justify-content: flex-end;
           }
        }
    }

    @media screen and (min-width: 1600px) {
        .nature {
            min-height: 1400px;
        }

        .content {
            padding: 700px 16em 4em;
            display: flex;
            justify-content: flex-start;

            .text {
                width: 80%;
            }

            &.one {
                padding-top: 400px;

                h1 {
                    width: 33%;
                }

                .text {
                    width: 44%;
                }
            }

            &.two {
                padding-top: 300px;
                justify-content: flex-end;
                height: 800px;

                .text {
                    width: 50%;
                }
            }

            &.three {
                padding-top: 300px;
                padding-bottom: 0;
            }
        }
    }
</style>

<script>
    import { onMount } from 'svelte';
    import DisappearingContent from "$lib/components/DisappearingContent.svelte";
    import Accordion from '$lib/components/Accordion.svelte';
    import Blob from '$lib/components/Blob.svelte';
    import Footer from "$lib/components/Footer.svelte";

    const accordionOptions = [
        {id: 'leaves', title: 'Collecting Leaves'},
        {id: 'water', title: 'Wetland Restoration'},
        {id: 'fire', title: 'Wildfire Mitigation'},
        {id: 'permaculture', title: 'Permaculture'},
    ];

    let ctaEl;
    let canvasEl;

    onMount(() => {
        if (!canvasEl || !canvasEl.getContext) return;
        const ctx = canvasEl.getContext('2d');
        if (!ctx) return;

        ctaEl.style.borderColor = 'transparent';
        ctaEl.style.animation = 'none';

        const borderWidth = 4;
        const radius = 16;
        const pad = 4;
        const dpr = window.devicePixelRatio || 1;

        function updateCanvas() {
            const boxWidth = ctaEl.offsetWidth;
            const boxHeight = ctaEl.offsetHeight;
            const w = boxWidth + pad * 2;
            const h = boxHeight + pad * 2;

            canvasEl.width = w * dpr;
            canvasEl.height = h * dpr;
            canvasEl.style.width = w + 'px';
            canvasEl.style.height = h + 'px';
            canvasEl.style.top = -pad + 'px';
            canvasEl.style.left = -pad + 'px';
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

            return { w, h };
        }

        let dims = updateCanvas();

        const resizeObserver = new ResizeObserver(() => {
            dims = updateCanvas();
        });
        resizeObserver.observe(ctaEl);

        function roundedRect(x, y, rw, rh, r) {
            ctx.beginPath();
            ctx.moveTo(x + r, y);
            ctx.lineTo(x + rw - r, y);
            ctx.arcTo(x + rw, y, x + rw, y + r, r);
            ctx.lineTo(x + rw, y + rh - r);
            ctx.arcTo(x + rw, y + rh, x + rw - r, y + rh, r);
            ctx.lineTo(x + r, y + rh);
            ctx.arcTo(x, y + rh, x, y + rh - r, r);
            ctx.lineTo(x, y + r);
            ctx.arcTo(x, y, x + r, y, r);
            ctx.closePath();
        }

        function draw(time) {
            const { w, h } = dims;
            ctx.clearRect(0, 0, w, h);

            const angle = -(time / 1000);
            const grad = ctx.createConicGradient(angle, w / 2, h / 2);

            for (let i = 0; i <= 6; i++) {
                grad.addColorStop(i / 6, `hsl(${(i / 6) * 360}, 100%, 60%)`);
            }

            ctx.strokeStyle = grad;
            ctx.lineWidth = borderWidth;

            const half = borderWidth / 2;
            const inset = pad - half;
            roundedRect(inset, inset, w - inset * 2, h - inset * 2, radius);
            ctx.stroke();

            animationId = requestAnimationFrame(draw);
        }

        let animationId = requestAnimationFrame(draw);

        return () => {
            cancelAnimationFrame(animationId);
            resizeObserver.disconnect();
        };
    });
</script>

<section class="nature">
    <div class="content-background">
        <div class="header"></div>
        <div class="gradient"></div>
        <div class="fireflies" alt="A forest background with grasses in the foreground and fireflies on the left"></div>
    </div>

    <div class="cta-wrapper">
        <canvas bind:this={canvasEl}></canvas>
        <div class="social-cta" bind:this={ctaEl}>
            <div class="cta-text">Follow our socials for weekly progress reports</div>
            <div class="cta-links">
                <a href="https://www.instagram.com/wildland.ecotech" target="_blank" rel="noopener noreferrer">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><rect x="2" y="2" width="20" height="20" rx="5" ry="5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="17.5" cy="6.5" r="1.5"/></svg>
                    Instagram
                </a>
                <span class="cta-divider">|</span>
                <a href="https://www.linkedin.com/company/wildland-ecotech" target="_blank" rel="noopener noreferrer">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><rect x="2" y="2" width="20" height="20" rx="3" ry="3" fill="none" stroke="currentColor" stroke-width="2"/><line x1="8" y1="11" x2="8" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="8" y1="8" x2="8" y2="8.01" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/><path d="M12 17v-3.5c0-1.5 1-2.5 2.5-2.5s2.5 1 2.5 2.5V17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="11" x2="12" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    LinkedIn
                </a>
            </div>
        </div>
    </div>

    <DisappearingContent>
        <div class="content one" id="mission">
            <div class="text">
                <div class="section-number">01</div>
                <h3>— Our Mission</h3>
                <h1>Building Abundance</h1>

                <div class="paragraphs">
                    <p>
                        Wildland Ecotech is committed to restoring land which has been degraded by human activity.
                        Our mission is to develop technologies that accelerate the regeneration of natural ecosystems.
                    </p>

                    <p>
                        We help manage over 1000 acres across North America and work with volunteers to ensure that it stays wild.
                    </p>

                    <p>
                        Since 2020 we've planted hundreds of trees, created dozens of wetlands, sequestered hundreds of tons of CO2, and built off-grid weather monitoring systems for habitat restoration.
                    </p>

                    <p>
                        By putting regenerative agriculture into practice, we're simultaneously growing food for people and restoring wildfire burn scars for the environment.
                    </p>
                </div>
            </div>
        </div>

        <div class="content two" id="programs">
            <div class="text">
                <div class="section-number">02</div>
                <h3>— What We Do</h3>
                <h1>Working With Nature</h1>

                <Accordion options={accordionOptions}>
                    {#snippet leaves()}
                        <p>
                            Every year our volunteers go out into local neighborhoods to collect leaves from people who would otherwise be throwing them away in the dump.
                            These leaves are the perfect mulch and compost material for protecting young trees and creating water catchment structures.
                        </p>

                        <p>
                            Since 2023 we've collected an average of 1,000 bags of leaves every autumn, equating to approximately 10 tons of carbon sequestered every year from leaves alone.
                        </p>

                        <p>
                            We've used these leaves along with logs from the wildfire to create hugelkultur terraces along 2 acres of burn scar so far, with plans to continue expanding our restoration efforts every year.
                        </p>
                    {/snippet}

                    {#snippet water()}
                        <p>
                            We build water catchment structures out of dead trees in wildfire burn scars, slowing down the water and giving it time to absorb into the ground.
                            This captured ground water recharges high-elevation aquifers and brings new life to old springs.
                        </p>

                        <p>
                            A common technique for this type of work is called Low-Tech Process-Based Restoration (or PBR for short).
                            These techniques include beaver bio-mimicry, using locally available materials like logs, mud, and rocks to create Beaver Dam Analogs (BDAs).
                        </p>

                        <p>
                            By specifically targeting mountain streams we store water in the ground longer, reducing drought and flood risk downstream in the valleys where most cities and towns are located.
                        </p>
                    {/snippet}

                    {#snippet fire()}
                        <p>
                            Wildfires create unstable landscapes prone to flash flooding and erosion.
                            Water catchment structures reduce flooding while catching sediment and debris.
                            This is a benefit for downstream water users by providing naturally filtered ground water.
                        </p>

                        <p>
                            By cutting dead tree limbs and downed trees in the aftermath of wildfires, we gain valuable resources.
                            We've built hiking trails, hugelkultur terraces, and beaver dams while simultaneously reducing fire risk.
                        </p>

                        <p>
                            We reduce fuel loads, put carbon directly back into the soil by burying logs, and improve water retention to increase the growth speed and viability of the trees we plant.
                        </p>
                    {/snippet}

                    {#snippet permaculture()}
                        <p>
                            It comes in many names: permaculture, regenerative agriculture, agroforestry; but the principles are the same.
                            <strong>First:</strong> Observe the natural landscape, existing conditions, and study the land's history.
                            <strong>Then:</strong> Use locally available materials to create diverse microclimates that foster life.
                        </p>

                        <p>
                            Since 2020, our volunteers have been working to transform wildfire burn scars back into productive forests.
                            The goal is to create permaculture food forests which provide food for people as well as a restored habitat for all the plants and animals that are native to the area.
                        </p>

                        <p>
                            In 2025 we started a new experiment - using dead trees to build raised beds for growing annual food crops.
                            These raised beds are built on the downhill slope side of the hugelkultur terraces we made over the past few years.
                            This allows the uphill side of the terraces to be left undisturbed, perfect for growing native trees, shrubs, and perennial crops.
                        </p>
                    {/snippet}
                </Accordion>
            </div>
        </div>

        <div class="content three" id="projects">
            <div class="text">
                <div class="section-number">03</div>
                <h3>— In The Field</h3>
                <h1>Our Projects</h1>
            </div>

            <div class="projects">
                <div class="project">
                    <Blob type="5">
                        <img src="/content/nature-burn-scar.jpg" alt="Restoration work at the High Park burn scar in Northern Colorado" />
                    </Blob>
                    <div class="button wide dark">High Park Burn Scar</div>
                </div>

                <div class="project">
                    <Blob type="4">
                        <img src="/content/nature-pond.jpg" alt="Wetland recovery at the Fish Pit near the GE Moreau Superfund site in New York" />
                    </Blob>
                    <div class="button wide dark">GE Moreau Superfund</div>
                </div>
            </div>
        </div>
        <Footer color="green"></Footer>
    </DisappearingContent>
</section>