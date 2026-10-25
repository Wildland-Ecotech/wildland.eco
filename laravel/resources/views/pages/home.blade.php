<x-layout>
    <section class="nature">
        <div class="content-background">
            <div class="header"></div>
            <div class="gradient"></div>
            <div class="fireflies"></div>
        </div>

        <div class="donate-cta" data-rainbow-border>
            <canvas></canvas>
            <div class="cta-text">Get involved and help us fight back against wildfires</div>

            <div class="cta-links">
                <a href="{{ route('donate') }}">Donate</a>
                <span class="cta-divider" aria-hidden="true">|</span>
                <a href="mailto:volunteer@wildland.eco">Volunteer</a>
            </div>
        </div>

        <x-disappearing-content>
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

                    {{-- Native <details> elements sharing a name, so one section is open at a time without JavaScript; see resources/js/accordion.js --}}
                    <div class="accordion" data-accordion>
                        <details class="accordion-item" name="programs" id="leaves" open>
                            <summary class="button wide">Collecting Leaves</summary>

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

                            <p>
                                <a class="accordion-link" href="{{ route('leaves') }}">Learn more about leaf collection</a>
                            </p>
                        </details>

                        <details class="accordion-item" name="programs" id="water">
                            <summary class="button wide">Wetland Restoration</summary>

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
                        </details>

                        <details class="accordion-item" name="programs" id="fire">
                            <summary class="button wide">Wildfire Mitigation</summary>

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
                        </details>

                        <details class="accordion-item" name="programs" id="permaculture">
                            <summary class="button wide">Permaculture</summary>

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
                        </details>

                        <div class="tap-hint" data-accordion-hint>
                            <span class="tap-hint-inner">
                                <span class="tap-hint-emoji">👆</span>
                                tap to learn more
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content three" id="projects">
                <div class="text">
                    <div class="section-number">03</div>
                    <h3>— In The Field</h3>
                    <h1>Our Projects</h1>
                </div>

                <div class="projects">
                    <a class="project" href="#high-park" data-modal-open>
                        <x-blob :type="5">
                            <img src="{{ asset('content/nature-burn-scar.jpg') }}" alt="Restoration work at the High Park burn scar in Northern Colorado">
                        </x-blob>
                        <div class="button wide dark">High Park Burn Scar</div>
                    </a>

                    <a class="project" href="#ge-moreau" data-modal-open>
                        <x-blob :type="4">
                            <img src="{{ asset('content/nature-pond.jpg') }}" alt="Wetland recovery at the Fish Pit near the GE Moreau Superfund site in New York">
                        </x-blob>
                        <div class="button wide dark">GE Moreau Superfund</div>
                    </a>
                </div>
            </div>

            <x-footer color="green" />
        </x-disappearing-content>

        <x-modal id="high-park" title="High Park Burn Scar" close-href="#projects">
            <div class="modal-layout">
                <div class="modal-blob">
                    <x-blob :type="5">
                        <img src="{{ asset('content/nature-burn-scar.jpg') }}" alt="High Park Burn Scar">
                    </x-blob>
                </div>

                <div class="modal-card">
                    <h1>High Park Burn Scar</h1>

                    <p>
                        Since 2020, Wildland Ecotech has been working with dozens of landowners across 1,000+ acres
                        in Colorado's High Park burn scar to restore landscapes damaged by the 2012 wildfire.
                    </p>

                    <p>
                        Our volunteers have built over 70 Beaver Dam Analogs across several properties, planted
                        hundreds of trees, and created permaculture food forests with hugelkultur terraces made
                        from wildfire-reclaimed dead timber. This work recharges aquifers, reduces future wildfire
                        risk, and grows food for the community.
                    </p>

                    <div class="buttons">
                        <a class="button dark" href="https://www.instagram.com/wildland.ecotech" target="_blank" rel="noopener noreferrer">
                            <x-social-icon name="instagram" />
                            Instagram
                        </a>

                        <a class="button dark" href="https://www.linkedin.com/company/wildland-ecotech" target="_blank" rel="noopener noreferrer">
                            <x-social-icon name="linkedin" />
                            LinkedIn
                        </a>

                        <a class="button dark" href="https://www.facebook.com/people/Wildland-Ecotech/61593137854373/" target="_blank" rel="noopener noreferrer">
                            <x-social-icon name="facebook" />
                            Facebook
                        </a>
                    </div>
                </div>
            </div>
        </x-modal>

        <x-modal id="ge-moreau" title="GE Moreau Superfund" close-href="#projects">
            <div class="modal-layout">
                <div class="modal-blob">
                    <x-blob :type="4">
                        <img src="{{ asset('content/nature-pond.jpg') }}" alt="GE Moreau Superfund">
                    </x-blob>
                </div>

                <div class="modal-card">
                    <h1>GE Moreau Superfund</h1>

                    <p>
                        Adjacent to the GE Moreau Superfund site in Upstate New York, this 18-acre property is a former
                        sand mine where decades of extraction hit the water table, creating a bowl-shaped depression now
                        designated as a wetland. Surrounded by forest and bordered by a historic cemetery, the land has
                        naturally recovered over the past 50 years into a thriving ecosystem of ponds, fish, turtles,
                        snails, and migratory birds.
                    </p>

                    <p>
                        We've partnered with local community members who use ATV trails in the surrounding area to
                        organize cleanups and create opportunities for residents to get involved in land stewardship.
                        Our long-term goals include water and soil sampling to study bioremediation potential and
                        installing solar-powered monitoring equipment for remote wildlife observation.
                    </p>

                    <div class="buttons">
                        <a class="button dark" href="https://www.instagram.com/wildland.ecotech" target="_blank" rel="noopener noreferrer">
                            <x-social-icon name="instagram" />
                            Instagram
                        </a>

                        <a class="button dark" href="https://www.linkedin.com/company/wildland-ecotech" target="_blank" rel="noopener noreferrer">
                            <x-social-icon name="linkedin" />
                            LinkedIn
                        </a>

                        <a class="button dark" href="https://www.facebook.com/people/Wildland-Ecotech/61593137854373/" target="_blank" rel="noopener noreferrer">
                            <x-social-icon name="facebook" />
                            Facebook
                        </a>
                    </div>
                </div>
            </div>
        </x-modal>
    </section>
</x-layout>
