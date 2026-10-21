// What only a browser shows: click and double-click navigation, stacked slideouts, saving with the button and with
// Cmd/Ctrl+S, per-site status, delete. Against the multi-site Pro fixture, as the admin from login.setup.mjs.
// Each test works in a section of its own, so they don't depend on each other's presets.
import { test, expect } from './fixtures.mjs';

// Open slideouts, the top one last; Craft keeps closed ones hidden in the DOM.
const openSlideouts = (page) => page.locator('.slideout-container:not(.hidden) .slideout');
const topSlideout = (page) => openSlideouts(page).last();
// The slideout's last crumb names its screen: the entry type on a preset, the section on a section screen.
const currentCrumb = (page) => topSlideout(page).locator('.primer-slideout-crumbs .current');
const sectionRow = (page, name) => page.locator('.primer-index tbody tr').filter({ has: page.getByRole('link', { name, exact: true }) });

// A preset with no fields yet for an entry type of the section in a site, through the section page. The entry
// type's link starts it, with "+ Add preset" or "Only in … so far" next to it.
const createPreset = async (page, sectionHandle, site = 'corporateEn', type = 'Page') => {
    await page.goto(`primer/sections/${sectionHandle}?site=${site}`);
    await page.locator('.primer-section-type', { hasText: type }).click();
    await expect(page.locator('h1')).toHaveText(`New preset: ${type}`);
    await page.locator('#main-form').getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.locator('h1')).toHaveText('Primer');
};

// "+ Add field" on a preset screen (`screen`: the page or a slideout). Waits until the menu has closed: while it
// fades out it is still Craft's top UI layer, which swallows shortcuts such as Cmd+S.
const addField = async (page, screen, name) => {
    await screen.getByRole('button', { name: 'Add field' }).click();
    await page.locator('.menu--disclosure.visible').getByRole('button', { name: new RegExp(`^${name.replace(/[()]/g, '\\$&')}`) }).click();
    await expect(page.locator('.menu--disclosure.visible')).toHaveCount(0);
};

test.describe('navigation', () => {
    test('on the index, a click opens the page and a double-click the slideout, for sections and entry types', async ({ page, context }) => {
        await createPreset(page, 'pagesNone');
        await page.goto('primer?site=corporateEn');
        const row = sectionRow(page, 'Pages (none)');

        await row.getByRole('link', { name: 'Pages (none)' }).dblclick();
        await expect(topSlideout(page).locator('.primer-section')).toBeVisible();
        await expect(page).toHaveURL(/\/admin\/primer\?site=corporateEn$/);
        await page.keyboard.press('Escape');
        await expect(openSlideouts(page)).toHaveCount(0);

        await row.locator('.primer-index-chip').dblclick();
        await expect(currentCrumb(page)).toHaveText('Page');
        await page.keyboard.press('Escape');
        await expect(openSlideouts(page)).toHaveCount(0);

        const [tab] = await Promise.all([
            context.waitForEvent('page'),
            row.locator('.primer-index-chip').click({ modifiers: ['ControlOrMeta'] }),
        ]);
        await expect(tab).toHaveURL(/\/admin\/primer\/presets\/\d+/);
        await tab.close();
        await expect(page).toHaveURL(/\/admin\/primer\?site=corporateEn$/);

        await row.locator('.primer-index-chip').click();
        await expect(page).toHaveURL(/\/admin\/primer\/presets\/\d+/);
        await expect(page.locator('h1')).toHaveText('Page');

        await page.goto('primer?site=corporateEn');
        await sectionRow(page, 'Pages (none)').getByRole('link', { name: 'Pages (none)' }).click();
        await expect(page).toHaveURL(/\/admin\/primer\/sections\/pagesNone/);
        await expect(page.locator('h1')).toHaveText('Pages (none)');
    });

    test('on the section page, an entry type opens its page on click and its slideout on double-click; slideouts stack', async ({ page }) => {
        await createPreset(page, 'pagesCustom');
        await page.goto('primer/sections/pagesCustom?site=corporateEn');
        const type = page.locator('.primer-section-type');

        await type.dblclick();
        await expect(currentCrumb(page)).toHaveText('Page');
        await page.keyboard.press('Escape');
        await expect(openSlideouts(page)).toHaveCount(0);

        await type.click();
        await expect(page).toHaveURL(/\/admin\/primer\/presets\/\d+/);
        await expect(page.locator('h1')).toHaveText('Page');

        // From the index: the section's slideout, then the entry type's on top of it.
        await page.goto('primer?site=corporateEn');
        await sectionRow(page, 'Pages (custom)').getByRole('link', { name: 'Pages (custom)' }).dblclick();
        await expect(currentCrumb(page)).toHaveText('Pages (custom)');
        await topSlideout(page).locator('.primer-section-type').dblclick();
        await expect(openSlideouts(page)).toHaveCount(2);
        await expect(currentCrumb(page)).toHaveText('Page');

        await page.keyboard.press('Escape');
        await expect(openSlideouts(page)).toHaveCount(1);
        await expect(currentCrumb(page)).toHaveText('Pages (custom)');
    });
});

test.describe('layout', () => {
    test('an entry type, its lightswitch and its × sit on one line, on the section page and the index', async ({ page }) => {
        // Vertical centres within a pixel.
        const seeOnOneLine = async (line, first) => {
            const middle = async (locator) => {
                const box = await locator.boundingBox();
                return box.y + box.height / 2;
            };
            const expected = await middle(line.locator(first));
            for (const other of ['.lightswitch', '[data-primer-delete]']) {
                expect(Math.abs(await middle(line.locator(other)) - expected), other).toBeLessThanOrEqual(1);
            }
        };
        await createPreset(page, 'pagesCustom', 'shopEn');

        await page.goto('primer/sections/pagesCustom?site=shopEn');
        await seeOnOneLine(page.locator('[data-primer-type="page"]'), '.primer-section-type');

        await page.goto('primer?site=shopEn');
        await seeOnOneLine(sectionRow(page, 'Pages (custom)').locator('li[data-preset]'), '.chip');
    });
});

test.describe('saving', () => {
    test('a preset created in a slideout, with a field and a picked element, saves with the button', async ({ page }) => {
        await page.goto('primer?site=corporateEn');
        await sectionRow(page, 'Pages (all)').getByRole('link', { name: 'Pages (all)' }).dblclick();
        await expect(currentCrumb(page)).toHaveText('Pages (all)');
        await topSlideout(page).getByRole('link', { name: 'Add preset' }).dblclick();
        await expect(openSlideouts(page)).toHaveCount(2);

        const preset = topSlideout(page);
        await addField(page, preset, 'Users (none)');
        const field = preset.locator('[data-primer-field]:not(.hidden)');
        await expect(field).toHaveCount(1);
        await field.getByRole('button', { name: /Add a user/ }).click();
        const modal = page.locator('.modal.elementselectormodal:visible');
        await modal.locator('.element', { hasText: 'admin' }).first().click();
        await modal.getByRole('button', { name: 'Select', exact: true }).click();
        await expect(field.locator('.element')).toContainText('admin');

        await preset.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.locator('#notifications')).toContainText('Preset saved.');
        await expect(openSlideouts(page)).toHaveCount(1);
        await expect(topSlideout(page).locator('[data-primer-fields]')).toContainText('Users (none)');

        await page.goto('primer?site=corporateEn');
        await expect(sectionRow(page, 'Pages (all)').locator('li[data-preset]')).toContainText('1 field');
    });

    test('Cmd/Ctrl+S saves in a slideout and on the page, and leaving afterwards asks nothing', async ({ page }) => {
        await createPreset(page, 'pagesLanguage');
        await page.goto('primer?site=corporateEn');
        const line = sectionRow(page, 'Pages (language)').locator('li[data-preset]');
        await expect(line).toContainText('0 fields');

        // In a slideout over the index: the slideout closes, the index reloads underneath and still says so.
        await line.locator('.primer-index-chip').dblclick();
        await addField(page, topSlideout(page), 'Entries (none)');
        const reloaded = page.waitForEvent('load');
        await page.keyboard.press('ControlOrMeta+s');
        await reloaded;
        await expect(openSlideouts(page)).toHaveCount(0);
        await expect(line).toContainText('1 field');
        await expect(page.locator('#notifications')).toContainText('Preset saved.');

        // On the page: "Save and continue editing" keeps it open.
        await line.locator('.primer-index-chip').click();
        await expect(page.locator('h1')).toHaveText('Page');
        const url = page.url();
        await addField(page, page.locator('#main-form'), 'Assets (none)');
        await page.keyboard.press('ControlOrMeta+s');
        await expect(page.locator('#notifications')).toContainText('Preset saved.');
        expect(page.url()).toBe(url);
        await expect(page.locator('[data-primer-field]:not(.hidden)')).toHaveCount(2);

        await page.getByRole('navigation', { name: 'Primary' }).getByRole('link', { name: 'Primer' }).click();
        await expect(page.locator('h1')).toHaveText('Primer');
        await expect(line).toContainText('2 fields');
    });
});

test.describe('Content Block', () => {
    // Features holds the Feature entry type with the Teaser block (Entries, Assets and Users fields, and a Plain Text one).
    test('a block added in a slideout shows only its relation fields and keeps a picked element', async ({ page }) => {
        await createPreset(page, 'features', 'corporateEn', 'Feature');
        await page.goto('primer?site=corporateEn');
        const line = sectionRow(page, 'Features').locator('li[data-preset]');
        await line.locator('.primer-index-chip').dblclick();
        await expect(currentCrumb(page)).toHaveText('Feature');

        const preset = topSlideout(page);
        await addField(page, preset, 'Teaser');
        const block = preset.locator('[data-primer-field]:not(.hidden)');
        await expect(block).toHaveCount(1);
        await expect(block.getByText('Teaser users (language)', { exact: true })).toBeVisible();
        await expect(block.getByText('Teaser text', { exact: true })).toHaveCount(0);
        await block.getByRole('button', { name: /Add a user/ }).click();
        const modal = page.locator('.modal.elementselectormodal:visible');
        await modal.locator('.element', { hasText: 'admin' }).first().click();
        await modal.getByRole('button', { name: 'Select', exact: true }).click();
        await expect(block.locator('.element')).toContainText('admin');
        await page.keyboard.press('ControlOrMeta+s');
        await expect(openSlideouts(page)).toHaveCount(0);
        await expect(line).toContainText('1 field');

        await line.locator('.primer-index-chip').click();
        await expect(page.locator('h1')).toHaveText('Feature');
        await expect(page.locator('[data-primer-field]:not(.hidden) .element')).toContainText('admin');
    });
});

test.describe('status per site', () => {
    // Pages (site group) created in Corporate EN spans the Corporate group: Corporate EN and Corporate DE.
    test('the sidebar switches sites, and the index lightswitch switches the preset for its site', async ({ page }) => {
        await createPreset(page, 'pagesSiteGroup');
        const sidebar = page.locator('#details');
        const allSites = sidebar.locator('[data-primer-status-all] .lightswitch');
        const siteSwitch = (name) => sidebar.locator('[data-primer-status-site]', { hasText: name }).locator('.lightswitch');
        const line = sectionRow(page, 'Pages (site group)').locator('li[data-preset]');

        // Sidebar: one site off makes "Enabled for all sites" indeterminate.
        await line.locator('.primer-index-chip').click();
        await expect(page.locator('h1')).toHaveText('Page');
        await expect(allSites).toHaveAttribute('aria-checked', 'true');
        await siteSwitch('Corporate DE').click();
        await expect(siteSwitch('Corporate DE')).toHaveAttribute('aria-checked', 'false');
        await expect(allSites).toHaveAttribute('aria-checked', 'mixed');
        await page.locator('#main-form').getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.locator('#notifications')).toContainText('Preset saved.');

        // Index in Corporate DE: off and dimmed; the lightswitch turns it on.
        await page.goto('primer?site=corporateDe');
        await expect(line).toHaveClass(/primer-off/);
        await expect(line.locator('.lightswitch')).toHaveAttribute('aria-checked', 'false');
        await line.locator('.lightswitch').click();
        await expect(page.locator('#notifications')).toContainText('Preset switched on for Corporate DE.');
        await expect(line).not.toHaveClass(/primer-off/);
        await page.reload();
        await expect(line.locator('.lightswitch')).toHaveAttribute('aria-checked', 'true');

        // Index in Corporate EN: off, which the sidebar then shows; "Enabled for all sites" turns every site on.
        await page.goto('primer?site=corporateEn');
        await line.locator('.lightswitch').click();
        await expect(page.locator('#notifications')).toContainText('Preset switched off for Corporate EN.');
        await expect(line).toHaveClass(/primer-off/);
        await line.locator('.primer-index-chip').click();
        await expect(page.locator('h1')).toHaveText('Page');
        await expect(siteSwitch('Corporate EN')).toHaveAttribute('aria-checked', 'false');
        await expect(siteSwitch('Corporate DE')).toHaveAttribute('aria-checked', 'true');
        await expect(allSites).toHaveAttribute('aria-checked', 'mixed');
        await allSites.click();
        await expect(siteSwitch('Corporate EN')).toHaveAttribute('aria-checked', 'true');
        await expect(siteSwitch('Corporate DE')).toHaveAttribute('aria-checked', 'true');
        await page.locator('#main-form').getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.locator('#notifications')).toContainText('Preset saved.');
        await expect(line).not.toHaveClass(/primer-off/);
    });
});

test.describe('delete', () => {
    // Pages (none) has a scope per site, so Shop EN and Shop FR each get a preset of their own here.
    test('the × on the index deletes after confirming', async ({ page, confirmations }) => {
        await createPreset(page, 'pagesNone', 'shopEn');
        await page.goto('primer?site=shopEn');
        const line = sectionRow(page, 'Pages (none)').locator('li[data-preset]');
        await line.getByRole('button', { name: 'Delete preset' }).click();

        await expect(page.locator('#notifications')).toContainText('Preset deleted.');
        expect(confirmations).toEqual(['Are you sure you want to delete “Page”?']);
        await expect(page).toHaveURL(/\/admin\/primer\?site=shopEn$/);
        await expect(line).toHaveCount(0);
    });

    test('the × in a section slideout deletes and reloads the slideout', async ({ page, confirmations }) => {
        await createPreset(page, 'pagesNone', 'shopFr');
        await page.goto('primer?site=shopFr');
        await sectionRow(page, 'Pages (none)').getByRole('link', { name: 'Pages (none)' }).dblclick();
        await expect(currentCrumb(page)).toHaveText('Pages (none)');
        await topSlideout(page).getByRole('button', { name: 'Delete preset' }).click();

        await expect(page.locator('#notifications')).toContainText('Preset deleted.');
        expect(confirmations).toEqual(['Are you sure you want to delete “Page”?']);
        await expect(topSlideout(page).getByRole('link', { name: 'Add preset' })).toBeVisible();
    });

    test('"Delete preset" in the ⋯ menu of the preset page deletes and goes to the section page', async ({ page, confirmations }) => {
        await createPreset(page, 'pagesSiteGroup', 'shopEn');
        await page.goto('primer?site=shopEn');
        await sectionRow(page, 'Pages (site group)').locator('li[data-preset] .primer-index-chip').click();
        await expect(page.locator('h1')).toHaveText('Page');

        await page.getByRole('button', { name: 'Actions', exact: true }).click();
        await page.locator('#action-menu').getByRole('button', { name: 'Delete preset' }).click();

        await expect(page.locator('#notifications')).toContainText('Preset deleted.');
        expect(confirmations).toEqual(['Are you sure you want to delete “Page”?']);
        await expect(page).toHaveURL(/\/admin\/primer\/sections\/pagesSiteGroup\?site=shopEn$/);
        await expect(page.getByRole('link', { name: 'Add preset' })).toBeVisible();
    });
});
