import { initLifecycleActions } from './tasks/lifecycle-actions.js';
import { initReopenActions } from './tasks/reopen-actions.js';
import { initTimelineActions } from './tasks/timeline-actions.js';

window.TaskFeatures = Object.freeze({ initLifecycleActions, initReopenActions, initTimelineActions });
