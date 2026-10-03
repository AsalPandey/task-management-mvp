import config from './playwright.config.js';

export default {
    ...config,
    testDir: './tests/Visual',
    snapshotPathTemplate: '{testDir}/../../output/playwright/r44a/reference/{arg}{ext}',
    outputDir: './output/playwright/r44a/comparison',
};
