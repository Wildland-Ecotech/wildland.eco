<script>
    import { onMount } from 'svelte';

    let { type = null, children } = $props();

    let blobEl;

    // Use specified type or random fallback
    const initialType = type || Math.floor(Math.random() * 10) + 1;

    // Random blob types for background layers (different from image type)
    function pickUnique(count, max, exclude) {
        const picked = new Set();
        while (picked.size < count) {
            const n = Math.floor(Math.random() * max) + 1;
            if (n !== exclude) picked.add(n);
        }
        return [...picked];
    }

    const [bgType1, bgType2] = pickUnique(2, 10, initialType);

    const bg1Duration = 30 + Math.random() * 20;
    const bg2Duration = 35 + Math.random() * 25;
    const bg1Direction = Math.random() > 0.5 ? 'normal' : 'reverse';
    const bg2Direction = bg1Direction === 'normal' ? 'reverse' : 'normal';

    // All blob paths from Blobs.svelte
    const blobPaths = [
        "M 0.11938289,0.3021458 C 0.03857029,0.4019678 0.00544645,0.548318 0.02914569,0.6757959 0.05743589,0.8279038 0.24176002,0.878041 0.37354345,0.7625969 0.50532687,0.6471526 0.57201173,0.6552872 0.7165016,0.666674 0.84634646,0.6768952 0.94196889,0.6341499 0.97848646,0.5577303 1.0150059,0.4813129 0.97329754,0.3794063 0.85622344,0.3897237 0.75778926,0.3983929 0.67521588,0.3479974 0.64188062,0.2504406 0.61021133,0.1577849 0.49755286,0.1375037 0.4134094,0.1472723 0.32926585,0.1570223 0.207115,0.1938023 0.11939891,0.3021458 Z",
        "m 0.11038364,0.41027597 c 0,0 0.20374576,-0.35879502 0.62898404,-0.26103613 0.35961842,0.0826742 0.29835282,0.5913282 -0.01296,0.57019092 C 0.5131188,0.70496112 0.43575442,0.77648351 0.33152124,0.86640763 0.1661818,1.0090495 -0.14157164,0.84376543 0.11038391,0.41025992 Z",
        "M 0.8930098,0.69252199 C 0.97508094,0.59112754 1.0087152,0.44247523 0.98464978,0.31301313 0.95592441,0.15852743 0.76874127,0.10759346 0.63490384,0.22485045 0.50106858,0.34210765 0.43334866,0.33384824 0.28661718,0.3222805 0.1547513,0.3118852 0.05764852,0.355318 0.02056514,0.43292102 c -0.0370835,0.0776241 0.005275,0.18113566 0.12416603,0.17065797 0.0999775,-0.008815 0.18381559,0.0423849 0.21767346,0.14148093 0.0321562,0.0941008 0.14656914,0.11470831 0.23202878,0.10478501 0.0854596,-0.0099 0.20950259,-0.0472559 0.29857639,-0.15732294 z",
        "M 0.81270747,0.2817177 C 0.67557545,0.18096144 0.51385578,0.14344595 0.38606145,0.14135309 c -0.12779669,-0.002113 -0.22580991,0.0173011 -0.26151957,0.0843456 -0.03570707,0.0670418 0.0171895,0.10630147 0.0378775,0.12517331 0.0206696,0.0188537 0.0659441,0.0807523 -0.0488587,0.15504925 -0.13673102,0.088479 -0.13033076,0.25844091 0,0.3436056 0.11651524,0.0761297 0.25184672,0.0419109 0.32515215,0 0.073305,-0.0419109 0.16163412,-0.07123 0.26312822,-0.0125757 0.10149392,0.0586726 0.22477667,0.0189065 0.26244409,-0.0758501 C 1.0050285,0.65861777 1.0279385,0.43985508 0.81272585,0.28173641 Z",
        "m 0.89188107,0.63102681 c 0,0 -0.20969819,0.35915631 -0.63710602,0.25547681 -0.36145584,-0.087683 -0.29354579,-0.59960778 0.019966,-0.57453284 0.21479881,0.0171685 0.29363766,-0.0539818 0.39978358,-0.143354 0.16837184,-0.1417634 0.47654977,0.0285519 0.2173562,0.46242618 z",
        "M 0.82016953,0.17406522 C 0.65965517,0.12790293 0.55767265,0.25993921 0.24756645,0.23792141 -0.10838524,0.21265392 -0.02232127,0.57353918 0.28159974,0.73162932 0.60849563,0.90166588 0.92981047,0.90771516 0.98336663,0.53325813 0.98305545,0.53315783 1.033419,0.23537463 0.82016953,0.17406522 Z",
        "M 0.95348859,0.3453432 C 0.87352755,0.22793755 0.73345739,0.20913806 0.64860704,0.22134301 0.56375863,0.233548 0.46963859,0.22797132 0.39704435,0.13303811 0.32446655,0.03812273 0.1935492,0.02921513 0.12177523,0.10564299 c -0.0776315,0.0826778 -0.18330005,0.28356567 -0.04234325,0.51718074 0.08980511,0.14883624 0.22700513,0.24649112 0.34602613,0.29723062 0.11903936,0.0507537 0.21839236,0.069538 0.27768095,0.0188964 0.0592908,-0.0506224 0.0247888,-0.10843215 0.012648,-0.13438483 C 0.70366322,0.77861526 0.68502677,0.70204009 0.82125919,0.67462863 0.98350934,0.64198242 1.0429146,0.47665917 0.9534712,0.34534289 Z",
        "M 0.17893108,0.39329993 C 0.35351813,0.19317574 0.54022229,0.02444365 0.66725784,0.1846209 c 0.0500194,0.0630641 0.10253387,0.0913997 0.2154468,0.13489923 0.0907691,0.0349756 0.12408956,0.11724893 0.10349014,0.18347946 -0.0282315,0.090796 -0.0959661,0.13051194 -0.19193239,0.14942916 -0.0959662,0.0189153 -0.2930804,0.0921568 -0.4986573,0.18537828 C 0.16409818,0.89742733 0.041565,0.86995613 0.01522979,0.76970853 -0.01110538,0.66946066 0.0716778,0.51624723 0.17894659,0.39328437 Z",
        "m 0.19878484,0.54952149 c 0.0948644,0.037658 0.18659047,0.12900157 0.23147543,0.2323824 0.0476057,0.10964837 0.12174965,0.13816746 0.20265469,0.13951665 C 0.68028626,0.9222062 0.79713232,0.89784974 0.83235568,0.79545792 0.87324471,0.67659658 0.79817814,0.57693058 0.90393918,0.44454479 1.0044564,0.3187227 1.0279533,0.22026449 0.9460937,0.15076487 0.87569649,0.09099695 0.76503184,0.09837773 0.62970088,0.16298884 0.55608482,0.19813815 0.46765419,0.1900365 0.38350067,0.1537582 0.26765358,0.10381555 0.14815252,0.09898887 0.07111861,0.18223163 -0.00591534,0.26549029 -0.06212164,0.44592347 0.1988004,0.54952149 Z",
        "M 0.82434878,0.39964878 C 0.64871929,0.20307359 0.46087807,0.03734895 0.33307684,0.19468564 0.28275377,0.25663123 0.22992382,0.28446433 0.11634183,0.32719241 0.02502071,0.36154751 -0.0084975,0.4423629 0.01222098,0.50741664 0.0406161,0.59660532 0.10877064,0.63561659 0.20532094,0.65419583 0.3018702,0.67277757 0.50016865,0.74471917 0.70697592,0.83628818 0.83926324,0.89485042 0.96253315,0.86786725 0.98903876,0.76939605 1.0155444,0.67092734 0.93224802,0.52043077 0.82433439,0.39964878 Z"
    ];

    const NUM_ANGLES = 64;
    const ARC_SAMPLES = 512;
    const MORPH_DURATION = 5000;
    const CENTER = 0.5;

    function samplePathPolar(pathD, svg) {
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', pathD);
        svg.appendChild(path);

        const totalLength = path.getTotalLength();
        const arcPoints = [];
        for (let i = 0; i < ARC_SAMPLES; i++) {
            const pt = path.getPointAtLength((i / ARC_SAMPLES) * totalLength);
            arcPoints.push([pt.x, pt.y]);
        }
        svg.removeChild(path);

        const radii = new Array(NUM_ANGLES).fill(0);
        for (const [x, y] of arcPoints) {
            const dx = x - CENTER;
            const dy = y - CENTER;
            let angle = Math.atan2(dy, dx);
            if (angle < 0) angle += 2 * Math.PI;

            const slot = Math.round((angle / (2 * Math.PI)) * NUM_ANGLES) % NUM_ANGLES;
            const dist = Math.sqrt(dx * dx + dy * dy);
            if (dist > radii[slot]) radii[slot] = dist;
        }

        for (let i = 0; i < NUM_ANGLES; i++) {
            if (radii[i] === 0) {
                const prev = radii[(i - 1 + NUM_ANGLES) % NUM_ANGLES];
                const next = radii[(i + 1) % NUM_ANGLES];
                radii[i] = (prev + next) / 2;
            }
        }

        return radii;
    }

    function radiiToPolygon(radii) {
        const points = [];
        for (let i = 0; i < radii.length; i++) {
            const angle = (i / radii.length) * 2 * Math.PI;
            const x = (CENTER + radii[i] * Math.cos(angle)) * 100;
            const y = (CENTER + radii[i] * Math.sin(angle)) * 100;
            points.push(`${x}% ${y}%`);
        }
        return `polygon(${points.join(', ')})`;
    }

    onMount(() => {
        if (!blobEl) return;

        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.style.cssText = 'position:absolute;width:0;height:0;overflow:hidden';
        document.body.appendChild(svg);

        const allRadii = blobPaths.map(d => samplePathPolar(d, svg));
        document.body.removeChild(svg);

        // Base radii is the specified type (used as floor when type is set)
        const baseIndex = initialType - 1;
        const baseRadii = allRadii[baseIndex];

        let currentIndex = baseIndex;
        let targetIndex;
        const currentRadii = baseRadii.slice();
        let fromRadii = currentRadii.slice();
        let toRadii;

        function pickNewTarget() {
            let newIndex;
            do {
                newIndex = Math.floor(Math.random() * allRadii.length);
            } while (newIndex === currentIndex);

            fromRadii = currentRadii.slice();
            toRadii = allRadii[newIndex];
            targetIndex = newIndex;
        }

        function easeInOut(t) {
            return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
        }

        pickNewTarget();
        let morphStart = performance.now();

        function animate(time) {
            const elapsed = time - morphStart;
            const rawT = Math.min(elapsed / MORPH_DURATION, 1);
            const t = easeInOut(rawT);

            for (let i = 0; i < NUM_ANGLES; i++) {
                const from = fromRadii[i];
                const to = toRadii[i];
                const unionRadius = Math.max(from, to);

                let morphed;
                if (t < 0.5) {
                    const t2 = t * 2;
                    morphed = from + (unionRadius - from) * t2;
                } else {
                    const t2 = (t - 0.5) * 2;
                    morphed = unionRadius + (to - unionRadius) * t2;
                }

                // When type is specified, enforce base shape as floor
                if (type) {
                    currentRadii[i] = Math.max(baseRadii[i], morphed);
                } else {
                    currentRadii[i] = morphed;
                }
            }

            blobEl.style.clipPath = radiiToPolygon(currentRadii);

            if (rawT >= 1) {
                currentIndex = targetIndex;
                pickNewTarget();
                morphStart = time;
            }

            animationId = requestAnimationFrame(animate);
        }

        let animationId = requestAnimationFrame(animate);

        return () => {
            cancelAnimationFrame(animationId);
        };
    });
</script>

<style lang="scss">
    .blob-container {
        position: relative;
        display: inline-block;
    }

    .blob-bg {
        position: absolute;
        inset: 5%;
        opacity: 0.5;
        animation: blob-spin var(--spin-duration) linear infinite var(--spin-direction);
    }

    .blob-bg-1 {
        background: linear-gradient(135deg, #2F742D, #89940E);
    }

    .blob-bg-2 {
        background: linear-gradient(225deg, #24A114, #94770E);
    }

    @keyframes blob-spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    // CSS fallback clip-paths for image blob
    .blob-1 { clip-path: url(#blob-1); }
    .blob-2 { clip-path: url(#blob-2); }
    .blob-3 { clip-path: url(#blob-3); }
    .blob-4 { clip-path: url(#blob-4); }
    .blob-5 { clip-path: url(#blob-5); }
    .blob-6 { clip-path: url(#blob-6); }
    .blob-7 { clip-path: url(#blob-7); }
    .blob-8 { clip-path: url(#blob-8); }
    .blob-9 { clip-path: url(#blob-9); }
    .blob-10 { clip-path: url(#blob-10); }
</style>

<div class="blob-container">
    <div
        class={`blob-bg blob-bg-1 blob-${bgType1}`}
        style:--spin-duration="{bg1Duration}s"
        style:--spin-direction={bg1Direction}
    ></div>
    <div
        class={`blob-bg blob-bg-2 blob-${bgType2}`}
        style:--spin-duration="{bg2Duration}s"
        style:--spin-direction={bg2Direction}
    ></div>
    <div bind:this={blobEl} class={`blob-${initialType}`}>
        {@render children?.()}
    </div>
</div>