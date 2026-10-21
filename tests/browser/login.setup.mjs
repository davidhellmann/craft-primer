// Signs in once as the admin `install.sh` created; the smoke tests reuse the session.
import { test, expect } from './fixtures.mjs';

test('an admin of the multi-site Craft signs in and finds Primer', async ({ page }) => {
    await page.goto('login');
    // Craft's login page also holds a hidden copy of the form (for its modal): use the visible one.
    await page.getByRole('textbox', { name: 'Username or Email' }).filter({ visible: true }).fill('admin');
    await page.getByRole('textbox', { name: 'Password' }).filter({ visible: true }).fill('primer-browser');
    await page.getByRole('button', { name: 'Sign in' }).filter({ visible: true }).click();
    await page.waitForURL(/\/admin\/dashboard/);

    await page.getByRole('navigation', { name: 'Primary' }).getByRole('link', { name: 'Primer' }).click();
    await expect(page.locator('h1')).toHaveText('Primer');
    await expect(page.locator('#site-crumb')).toContainText('Corporate EN');

    await page.context().storageState({ path: 'tests/browser/storage/admin.json' });
});
