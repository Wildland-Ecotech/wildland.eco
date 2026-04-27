<svelte:head>
    <title>Donate — Wildland Ecotech</title>
</svelte:head>

<style lang="scss">
    .donate-page {
        overflow-x: hidden;
    }

    .backgrounds {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        z-index: 0;
        pointer-events: none;

        .mountains {
            position: absolute;
            width: 100%;
            height: 500px;
            background-image: url('$lib/images/bg-nature.png');
            background-position: center top;
            background-repeat: no-repeat;
            background-size: cover;
        }

        .gradient {
            position: absolute;
            width: 100%;
            height: 400px;
            top: 100px;
            background: linear-gradient(0deg, #010902 0%, rgba(1, 9, 2, 0) 100%);
            z-index: 1;
        }

        .gradient-fireflies {
            position: absolute;
            width: 100%;
            height: 300px;
            top: 900px;
            background: linear-gradient(0deg, rgba(1, 9, 2, 0) 0%, #010902 100%);
            z-index: 1;
        }

        .fireflies {
            position: absolute;
            z-index: 0;
            width: 100%;
            height: 1200px;
            background-image: url('$lib/images/bg-fireflies.png');
            background-position: center;
            background-repeat: no-repeat;
            background-size: cover;
            opacity: 0.5;
            top: 900px;
        }
    }

    .page-content {
        position: relative;
        z-index: 1;
        max-width: 960px;
        margin: 0 auto;
        padding: 120px 1.5em 0;
    }

    // Donate section
    .donate-section {
        text-align: center;
        margin-bottom: 4em;
        background: rgba(0, 0, 0, 0.5);
        border-radius: 16px;
        padding: 2em 2em 2.5em;

        h1 {
            margin-bottom: 0.1em;
        }

        .subtitle {
            font-family: satoshi;
            font-size: 18px;
            color: rgba(255, 255, 255, 0.6);
            margin: 0 auto 2em;
        }
    }

    .frequency-toggle {
        display: flex;
        justify-content: center;
        gap: 0;
        margin-bottom: 2em;
        max-width: 400px;
        margin-left: auto;
        margin-right: auto;

        .freq-btn {
            flex: 1;
            background: rgba(0, 0, 0, 0.3);
            border: 2px solid rgba(46, 207, 158, 0.3);
            color: rgba(255, 255, 255, 0.6);
            font-family: satoshi;
            font-size: 17px;
            font-weight: 600;
            padding: 0.7em 1em;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            white-space: nowrap;

            &:first-child {
                border-radius: 10px 0 0 10px;
            }

            &:last-child {
                border-radius: 0 10px 10px 0;
            }

            &:not(:first-child) {
                margin-left: -2px;
            }

            &.active {
                background: rgba(46, 207, 158, 0.25);
                border-color: #2ECF9E;
                color: #fff;
                z-index: 1;
            }

            &:hover:not(.active) {
                color: #fff;
            }
        }
    }

    .tier-grid {
        display: grid;
        gap: 1em;
        grid-template-columns: repeat(3, 1fr);
        max-width: 600px;
        margin: 0 auto 1.5em;
    }

    .tier-card {
        background: rgba(0, 0, 0, 0.3);
        border: 2px solid rgba(46, 207, 158, 0.3);
        border-radius: 12px;
        padding: 1.5em 1em;
        cursor: pointer;
        transition: all 0.2s;
        text-align: center;

        .amount {
            font-family: redhat;
            font-size: 36px;
            color: #2ECF9E;
            margin-bottom: 0.1em;
        }

        .period {
            font-family: satoshi;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.5);
        }

        &.active {
            border-color: #2ECF9E;
            background: rgba(46, 207, 158, 0.15);

            .amount {
                color: #fff;
            }
        }

        &:hover:not(.active) {
            border-color: rgba(46, 207, 158, 0.6);
        }
    }

    .custom-amount {
        max-width: 300px;
        margin: 0 auto 2em;

        label {
            display: block;
            font-family: satoshi;
            font-size: 15px;
            color: rgba(255, 255, 255, 0.5);
            margin-bottom: 0.5em;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;

            .dollar-sign {
                position: absolute;
                left: 1em;
                font-family: redhat;
                font-size: 20px;
                color: rgba(255, 255, 255, 0.4);
                pointer-events: none;
            }

            input {
                width: 100%;
                background: rgba(0, 0, 0, 0.4);
                border: 2px solid rgba(46, 207, 158, 0.3);
                border-radius: 10px;
                padding: 0.7em 1em 0.7em 2em;
                color: #fff;
                font-family: redhat;
                font-size: 20px;
                outline: none;
                box-sizing: border-box;

                &:focus {
                    border-color: #2ECF9E;
                }

                &::placeholder {
                    color: rgba(255, 255, 255, 0.3);
                }
            }
        }
    }

    .donate-button {
        display: inline-block;
        background: rgba(46, 207, 158, 0.25);
        border: 3px solid #2ECF9E;
        border-radius: 10px;
        color: #fff;
        font-family: satoshi;
        font-size: 21px;
        font-weight: 1000;
        padding: 0.7em 3em;
        cursor: pointer;
        text-decoration: none;
        transition: background 0.2s;

        &:hover {
            background: transparent;
        }
    }

    .tax-note {
        font-family: satoshi;
        font-size: 14px;
        color: rgba(255, 255, 255, 0.4);
        margin-top: 1em;
    }

    // Divider
    .section-divider {
        width: 100%;
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(46, 207, 158, 0.3), transparent);
        margin: 1em 0 3em;
    }

    // Story section
    .story {
        margin-bottom: 3em;
        text-align: center;

        .story-intro {
            font-family: satoshi;
            font-size: 18px;
            color: rgba(255, 255, 255, 0.7);
            max-width: 650px;
            margin: 0 auto 2em;
            line-height: 1.6;
        }

        .story-grid {
            display: grid;
            gap: 1.5em;
            grid-template-columns: 1fr;
            text-align: left;
        }

        .story-card {
            background: rgba(0, 0, 0, 0.3);
            border-top: 3px solid #2ECF9E;
            border-radius: 0 0 12px 12px;
            padding: 1.5em;

            .story-icon {
                font-size: 32px;
                margin-bottom: 0.4em;
            }

            h2 {
                font-size: 22px;
                margin: 0 0 0.5em;
            }

            p {
                font-size: 17px;
                margin: 0;
                color: rgba(255, 255, 255, 0.8);
                line-height: 1.5;
            }
        }
    }

    // Impact stats
    .impact {
        margin-bottom: 3em;
        text-align: center;

        .impact-grid {
            display: grid;
            gap: 1.5em;
            grid-template-columns: 1fr 1fr;
        }

        .impact-stat {
            .number {
                font-family: redhat;
                font-size: 40px;
                color: #2ECF9E;
            }

            .label {
                font-family: satoshi;
                font-size: 16px;
                color: rgba(255, 255, 255, 0.7);
                margin-top: 0.2em;
            }
        }
    }

    // Other ways
    .other-ways {
        text-align: center;
        margin-bottom: 2em;

        p {
            font-size: 18px;
            color: rgba(255, 255, 255, 0.7);
            max-width: 600px;
            margin: 0 auto 1.5em;
        }

        .social-buttons {
            display: flex;
            justify-content: center;
            gap: 1em;
            flex-wrap: wrap;
        }
    }

    @media screen and (max-width: 499px) {
        .page-content {
            padding: 100px 0.75em 0;
        }

        .donate-section {
            padding: 1.25em 1em 2em;
            margin-bottom: 2em;

            h1 {
                font-size: 48px;
            }

            .subtitle {
                font-size: 16px;
                margin-bottom: 1.5em;
            }
        }

        .frequency-toggle {
            margin-bottom: 1.5em;

            .freq-btn {
                font-size: 15px;
                padding: 0.6em 0.5em;
            }
        }

        .tier-grid {
            gap: 0.5em;
        }

        .tier-card {
            padding: 1em 0.25em;
            gap: 0.5em;

            .amount {
                font-size: 22px;
            }
        }

        .custom-amount {
            margin-bottom: 1.5em;
        }

        .donate-button {
            font-size: 18px;
            padding: 0.7em 2em;
        }

        .story {
            margin-bottom: 2em;

            .story-card {
                padding: 1.25em;

                h2 { font-size: 20px; }
                p { font-size: 16px; }
            }
        }

        .impact {
            margin-bottom: 2em;

            .impact-stat .number {
                font-size: 32px;
            }
        }
    }

    @media screen and (min-width: 768px) {
        .story .story-grid {
            grid-template-columns: 1fr 1fr;
        }

        .impact .impact-grid {
            grid-template-columns: repeat(4, 1fr);
        }
    }


    @media screen and (min-width: 1024px) {
        .donate-section .subtitle {
            font-size: 20px;
        }

        .story .story-card {
            h2 { font-size: 26px; }
            p { font-size: 18px; }
        }

        .tier-card .amount {
            font-size: 42px;
        }

        .impact .impact-stat .number {
            font-size: 48px;
        }
    }
</style>

<script>
    import DisappearingContent from "$lib/components/DisappearingContent.svelte";
    import Footer from "$lib/components/Footer.svelte";

    let frequency = $state('monthly');
    let selectedTier = $state(1);
    let customAmount = $state('');

    const tiers = {
        'one-time': [5, 20, 50],
        'monthly': [20, 50, 100],
        'annual': [100, 1000, 5000],
    };

    function periodLabel(freq) {
        if (freq === 'monthly') return '/mo';
        if (freq === 'annual') return '/yr';
        return '';
    }

    function selectTier(index) {
        selectedTier = index;
        customAmount = '';
    }

    function handleCustomFocus() {
        selectedTier = -1;
    }

    $effect(() => {
        frequency;
        selectedTier = 1;
        customAmount = '';
    });
</script>

<div class="donate-page">
    <div class="backgrounds">
        <div class="mountains"></div>
        <div class="gradient"></div>
        <div class="gradient-fireflies"></div>
        <div class="fireflies"></div>
    </div>

    <div class="page-content">
    <DisappearingContent>

        <div class="donate-section">
            <h3>— Support Our Mission</h3>
            <h1>Donate</h1>
            <p class="subtitle">
                Your tax-deductible donation protects communities from wildfire, drought,
                and flooding while restoring habitat for future generations.
            </p>

            <div class="frequency-toggle">
                <button
                    class="freq-btn"
                    class:active={frequency === 'one-time'}
                    onclick={() => frequency = 'one-time'}
                >
                    One-Time
                </button>
                <button
                    class="freq-btn"
                    class:active={frequency === 'monthly'}
                    onclick={() => frequency = 'monthly'}
                >
                    Monthly
                </button>
                <button
                    class="freq-btn"
                    class:active={frequency === 'annual'}
                    onclick={() => frequency = 'annual'}
                >
                    Annual
                </button>
            </div>

            <div class="tier-grid">
                {#each tiers[frequency] as amount, i}
                    <div
                        class="tier-card"
                        class:active={selectedTier === i}
                        onclick={() => selectTier(i)}
                        role="button"
                        tabindex="0"
                    >
                        <div class="amount">${amount.toLocaleString()}</div>
                        <div class="period">{periodLabel(frequency)}</div>
                    </div>
                {/each}
            </div>

            <div class="custom-amount">
                <label>Or enter a custom amount</label>
                <div class="input-wrapper">
                    <span class="dollar-sign">$</span>
                    <input
                        type="number"
                        placeholder="Other amount"
                        bind:value={customAmount}
                        onfocus={handleCustomFocus}
                        min="1"
                    />
                </div>
            </div>

            <button class="donate-button">
                {#if frequency === 'one-time'}
                    Donate {selectedTier >= 0 ? `$${tiers[frequency][selectedTier].toLocaleString()}` : customAmount ? `$${customAmount}` : ''}
                {:else if frequency === 'monthly'}
                    Donate {selectedTier >= 0 ? `$${tiers[frequency][selectedTier]}` : customAmount ? `$${customAmount}` : ''}/month
                {:else}
                    Donate {selectedTier >= 0 ? `$${tiers[frequency][selectedTier].toLocaleString()}` : customAmount ? `$${customAmount}` : ''}/year
                {/if}
            </button>

            <p class="tax-note">
                Wildland Ecotech is a 501(c)(3) nonprofit. All donations are tax-deductible. EIN: 33-3738514
            </p>
        </div>

        <div class="section-divider"></div>

        <div class="story">
            <h3>— What You're Supporting</h3>
            <h1>Our Work</h1>
            <p class="story-intro">
                Your donations fund hands-on ecological restoration across 1,000+ acres of
                wildfire-damaged and polluted land across the United States.
            </p>

            <div class="story-grid">
                <div class="story-card">
                    <div class="story-icon">🔥</div>
                    <h2>Wildfire Protection</h2>
                    <p>
                        We reduce fuel loads in burn scars, build water catchment structures that serve
                        as natural firebreaks, and restore wetlands that firefighters use as staging areas
                        during active wildfires.
                    </p>
                </div>
                <div class="story-card">
                    <div class="story-icon">💧</div>
                    <h2>Water Conservation</h2>
                    <p>
                        Our Beaver Dam Analogs slow water down in mountain streams, recharging aquifers
                        and reducing flash flood risk and sediment pollution downstream where communities
                        depend on clean water.
                    </p>
                </div>
                <div class="story-card">
                    <div class="story-icon">🍂</div>
                    <h2>Community Action</h2>
                    <p>
                        We collect over 1,000 bags of leaves each autumn from local neighborhoods,
                        turning waste into compost for habitat restoration. Volunteers of all ages and
                        skill levels power our work.
                    </p>
                </div>
                <div class="story-card">
                    <div class="story-icon">📚</div>
                    <h2>Education</h2>
                    <p>
                        Through hands-on workshops with CSU's STEM 4 Kids program, we teach the next
                        generation about water conservation, wetland ecology, and how beavers protect
                        landscapes from wildfire.
                    </p>
                </div>
            </div>
        </div>

        <div class="section-divider"></div>

        <div class="impact">
            <h3>— Our Impact</h3>
            <h1>By The Numbers</h1>

            <div class="impact-grid">
                <div class="impact-stat">
                    <div class="number">1,000+</div>
                    <div class="label">Acres Managed</div>
                </div>
                <div class="impact-stat">
                    <div class="number">70+</div>
                    <div class="label">Beaver Dam Analogs Built</div>
                </div>
                <div class="impact-stat">
                    <div class="number">500+</div>
                    <div class="label">Native Trees & Shrubs Planted</div>
                </div>
                <div class="impact-stat">
                    <div class="number">80+</div>
                    <div class="label">Volunteers</div>
                </div>
            </div>
        </div>

        <div class="section-divider"></div>

        <div class="other-ways">
            <h3>— Beyond Donations</h3>
            <h1>Other Ways to Help</h1>
            <p>
                Not everyone can give financially, and that's okay.
                Follow our work, share our story, or volunteer at a restoration event.
            </p>
            <div class="social-buttons">
                <a class="button dark" href="https://www.instagram.com/wildland.ecotech" target="_blank" rel="noopener noreferrer">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="width:18px;height:18px;vertical-align:middle;margin-right:0.3em;fill:currentColor;"><rect x="2" y="2" width="20" height="20" rx="5" ry="5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="17.5" cy="6.5" r="1.5"/></svg>
                    Instagram
                </a>
                <a class="button dark" href="https://www.linkedin.com/company/wildland-ecotech" target="_blank" rel="noopener noreferrer">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="width:18px;height:18px;vertical-align:middle;margin-right:0.3em;fill:currentColor;"><rect x="2" y="2" width="20" height="20" rx="3" ry="3" fill="none" stroke="currentColor" stroke-width="2"/><line x1="8" y1="11" x2="8" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="8" y1="8" x2="8" y2="8.01" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/><path d="M12 17v-3.5c0-1.5 1-2.5 2.5-2.5s2.5 1 2.5 2.5V17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="11" x2="12" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    LinkedIn
                </a>
                <a class="button dark" href="mailto:wildland@wetfish.net">
                    Volunteer With Us
                </a>
            </div>
        </div>
    </DisappearingContent>
    </div>

    <Footer
        heading="Thank you for your support"
        title="Together we can restore wildlands for future generations."
        buttonText="Back to Home"
        buttonHref="/"
        showDonate={false}
    />
</div>