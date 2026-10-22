// Progressive enhancements. Every page works without this script, except
// that the leaf application form requires its proof-of-work spam protection.
// Vite loads it as a module, which runs after the page has been parsed.

import { initMenu } from './menu';
import { initDisappearingContent } from './disappearing-content';
import { initDonateForm } from './donate';
import { initAccordions } from './accordion';
import { initModals } from './modal';
import { initBlobs } from './blob';
import { initRainbowBorders } from './rainbow-border';
import { initProofOfWork } from './proof-of-work';

initMenu();
initDisappearingContent();
initDonateForm();
initAccordions();
initModals();
initBlobs();
initRainbowBorders();
initProofOfWork();
