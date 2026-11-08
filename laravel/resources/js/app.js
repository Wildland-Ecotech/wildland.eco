// Progressive enhancements. Every page works without this script, except
// that the leaf application and donate forms require their proof-of-work
// spam protection, and the admin Map page's map needs it.
// Vite loads it as a module, which runs after the page has been parsed.

import { initMenu } from './menu';
import { initDisappearingContent } from './disappearing-content';
import { initDonateForm } from './donate';
import { initAccordions } from './accordion';
import { initModals } from './modal';
import { initBlobs } from './blob';
import { initRainbowBorders } from './rainbow-border';
import { initProofOfWork } from './proof-of-work';
import { initAutoSubmit } from './auto-submit';

initMenu();
initDisappearingContent();
initDonateForm();
initAccordions();
initModals();
initBlobs();
initRainbowBorders();
initProofOfWork();
initAutoSubmit();

// The admin Map page's map, loaded only there, since Leaflet is large
const mapElement = document.querySelector('[data-map]');

if (mapElement) {
    import('./admin-map').then(({ initMap }) => initMap(mapElement));
}
