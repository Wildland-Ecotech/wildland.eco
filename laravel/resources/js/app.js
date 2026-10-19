// Progressive enhancements. Every page works without this script.
// Vite loads it as a module, which runs after the page has been parsed.

import { initMenu } from './menu';
import { initDisappearingContent } from './disappearing-content';
import { initDonateForm } from './donate';

initMenu();
initDisappearingContent();
initDonateForm();
