import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 180_000,
    expect: { timeout: 12_000 },
    outputDir: process.env.BROWSER_OUTPUT_DIR || 'output/playwright/r41',
    reporter: [['list']],
    use: {
        baseURL: process.env.APP_URL || 'http://127.0.0.1:8046',
        viewport: { width: 1280, height: 900 },
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
});
