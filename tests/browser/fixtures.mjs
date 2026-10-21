// Playwright's `test`, watching every page of a test: a script error or console error fails it, and so does a
// "Leave site?" prompt. Confirmations (delete) are accepted and their messages kept in `confirmations`.
import { test as base, expect } from '@playwright/test';

export const test = base.extend({
    confirmations: async ({}, use) => {
        await use([]);
    },
    problems: [async ({ context, confirmations }, use) => {
        const problems = [];
        const watch = (page) => {
            page.on('pageerror', (error) => problems.push(`page error: ${error.message}`));
            page.on('console', (message) => {
                if (message.type() === 'error') {
                    problems.push(`console error: ${message.text()}`);
                }
            });
            page.on('dialog', async (dialog) => {
                if (dialog.type() === 'beforeunload') {
                    problems.push('"Leave site?" prompt');
                } else {
                    confirmations.push(dialog.message());
                }
                await dialog.accept();
            });
        };
        context.pages().forEach(watch);
        context.on('page', watch);
        await use(problems);
        expect(problems, 'problems on the page').toEqual([]);
    }, { auto: true }],
});

export { expect };
