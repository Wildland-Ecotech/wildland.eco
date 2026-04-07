<svelte:head>
    <title>Wildland Ecotech — Restoring Landscapes, Building Abundance</title>
</svelte:head>

<style lang="scss">
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
</script>

<section class="nature">
    <div class="content-background">
        <div class="header"></div>
        <div class="gradient"></div>
        <div class="fireflies" alt="A forest background with grasses in the foreground and fireflies on the left"></div>
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
