<x-layout title="Leave the Leaves — Wildland Ecotech">
    <x-content-page class="leaves-page">
        <section class="intro" id="leave-the-leaves">
            <h3>— Leaf Program</h3>
            <h1>Leave the Leaves</h1>

            <div class="prose lead">
                <p>
                    Fallen leaves aren't waste. Left on your yard, they help retain ground water, recharge aquifers,
                    and create habitat for wild animals and pollinators right outside your door.
                </p>

                <p>
                    So this year, skip the rake. Let your grass grow into a meadow, let the wildflowers come back,
                    and only mow walking paths. When we restore the habitat around our homes, and everywhere else
                    in our lives, we take climate change into our own hands.
                </p>
            </div>
        </section>

        <div class="section-divider"></div>

        <section id="donate-leaves">
            <h3>— If You Do Rake</h3>
            <h1>Donate Your Leaves</h1>

            <div class="prose">
                <p>
                    We know not everyone wants to let their yard go wild, and many bags of leaves end up in the
                    landfill, a big waste of natural resources. If you rake your leaves, give them to us instead.
                </p>

                <p>
                    All donated leaves are used in the High Park burn scar, where they become mulch and compost
                    for building terraces and adding nutrients and biomass to the soil. Spread across the land,
                    they help retain moisture, regenerate soil, prevent erosion, and rebuild the forest floor in
                    areas devastated by wildfire.
                </p>
            </div>

            <div class="apply-cta">
                <a class="button full" href="{{ route('leaves.apply') }}">Apply to Donate Leaves</a>
            </div>

            <div class="options">
                <div class="option-card" id="drop-off">
                    <div class="option-icon">📍</div>
                    <h2>Drop-Off</h2>
                    <div class="badge">Preferred</div>

                    <p>
                        Drop-off is free and available year-round at our two locations in Fort Collins.
                        Once your application has been reviewed and approved, we'll contact you with the details
                        for your drop-off location, and you can drop off your leaves whenever it's convenient.
                    </p>

                    <p>
                        Please stack your bags neatly within the drop-off zone.
                    </p>
                </div>

                <div class="option-card" id="pickup">
                    <div class="option-icon">🚚</div>
                    <h2>Pickup</h2>

                    <p>
                        If you don't have a vehicle that can carry bags of leaves, or you have too many bags to bring
                        yourself, our volunteers can pick them up.
                    </p>

                    <p>
                        Pickups take a lot of volunteer time, so they need to be scheduled 1 to 2 weeks in advance
                        so we can plan an efficient route.
                    </p>
                </div>
            </div>
        </section>

        <div class="section-divider"></div>

        <section id="guidelines">
            <h3>— Before You Bag</h3>
            <h1>Leaf Guidelines</h1>

            <div class="prose">
                <ul class="rules">
                    <li>
                        <strong>Paper bags only.</strong>
                        All bags must be paper compostable lawn bags. We will not accept any lawn material in plastic bags.
                    </li>

                    <li>
                        <strong>Compostable yard waste only.</strong>
                        Leaves, small twigs, lawn clippings, and other small compostable yard material.
                    </li>

                    <li>
                        <strong>Nothing else.</strong>
                        Check every bag for plastic, metal, chemicals, and any other non-compostable material.
                    </li>
                </ul>

                <p class="notice">
                    If we find any non-compostable or non-biodegradable material in your bags, we will no longer
                    accept leaves from your household. Everything we receive is spread across restoration sites,
                    and human-made products and waste must never end up there.
                </p>
            </div>
        </section>

        <div class="section-divider"></div>

        <section id="privacy">
            <h3>— Your Information</h3>
            <h1>Privacy</h1>

            <div class="prose">
                <p>
                    Your name, address, phone number, and email stay private. We use them to coordinate your
                    drop-off or pickup, and we never share them.
                </p>

                <p>
                    What we do share is the story of your leaves. Grant programs ask us to show the impact of
                    our work, and seeing where the leaves come from inspires more people to get involved.
                    So we may share:
                </p>

                <ul>
                    <li>The general area your leaves came from, like "South-East Fort Collins"</li>
                    <li>When you dropped them off or we picked them up</li>
                    <li>How many bags you gave us</li>
                    <li>Photos of the bags</li>
                </ul>

                <p>
                    You might see these details in our grant applications and reports, on our website and
                    social media, or in our flyers, zines, and brochures.
                </p>
            </div>
        </section>

        <div class="section-divider"></div>

        <section id="apply">
            <h3>— Ready to Donate?</h3>

            <div class="apply-cta">
                <p>Apply for drop-off or pickup, and we'll be in touch once we've reviewed your application.</p>
                <a class="button full" href="{{ route('leaves.apply') }}">Apply to Donate Leaves</a>
            </div>
        </section>

        <x-slot:footer>
            <x-footer
                heading="Questions about leaves?"
                title="Help us turn yard waste into restored habitat."
            />
        </x-slot:footer>
    </x-content-page>
</x-layout>
