// The browser smoke suite (tests/browser): installs the test Craft from the multi-site Pro fixture into its own
// database, serves it with `php craft serve` and drives the control panel in Chromium.
import { defineConfig } from '@playwright/test';

const port = process.env.PRIMER_BROWSER_PORT ?? '8484';
const origin = `http://127.0.0.1:${port}`;

export default defineConfig({
    testDir: 'tests/browser',
    outputDir: 'tests/browser/test-results',
    // One Craft, one database: the tests run one after another.
    workers: 1,
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    reporter: process.env.CI
        ? [['list'], ['html', { outputFolder: 'tests/browser/report', open: 'never' }]]
        : 'list',
    globalSetup: './tests/browser/global-setup.mjs',
    use: {
        baseURL: `${origin}/admin/`,
        viewport: { width: 1440, height: 900 },
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'login', testMatch: /login\.setup\.mjs/ },
        {
            name: 'smoke',
            dependencies: ['login'],
            use: { storageState: 'tests/browser/storage/admin.json' },
        },
    ],
    webServer: {
        command: `php tests/browser/craft serve 127.0.0.1 --port=${port} --docroot=tests/browser/web`,
        url: `${origin}/admin/login`,
        reuseExistingServer: !process.env.CI,
        // The control panel loads many resources at once; PHP's server handles one request per worker.
        env: { PHP_CLI_SERVER_WORKERS: '4' },
        stdout: 'ignore',
        stderr: 'pipe',
    },
});
