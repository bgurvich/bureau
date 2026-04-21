import { test, expect } from '@playwright/test';

// Assert the desktop layout degrades gracefully on a phone-sized viewport:
// the sidebar hides behind a hamburger, toggling the hamburger opens and
// closes the drawer, ESC closes, and the dashboard grid collapses to a
// single column. The target widget names mirror those the authenticated
// smoke test already relies on, so both stay in sync.
test.use({ viewport: { width: 390, height: 844 } });

const signIn = async (page) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('boris@gurvich.me');
    await page.getByLabel('Password').fill('change-me');
    await page.getByRole('button', { name: 'Sign in with password' }).click();
    await expect(page).toHaveURL('/');
};

test('sidebar collapses into a hamburger drawer on mobile', async ({ page }) => {
    await signIn(page);

    const nav = page.getByRole('navigation', { name: 'Main navigation' });
    const hamburger = page.getByRole('button', { name: 'Toggle navigation' });

    await expect(hamburger).toBeVisible();
    await expect(nav).toBeHidden();

    await hamburger.click();
    await expect(nav).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(nav).toBeHidden();

    await hamburger.click();
    await expect(nav).toBeVisible();
    await page.locator('[aria-hidden="true"].fixed.inset-0').click();
    await expect(nav).toBeHidden();
});

test('dashboard grid collapses to one column on mobile', async ({ page }) => {
    await signIn(page);

    // Net worth and Time tracker sit in the responsive grid; at md- they must
    // stack vertically, so the time-tracker card should render below the
    // net-worth tile rather than beside it.
    const netWorth = page.getByText('Net worth').first();
    const timeTracker = page.getByText('Time tracker').first();

    await expect(netWorth).toBeVisible();
    await expect(timeTracker).toBeVisible();

    const netBox = await netWorth.boundingBox();
    const timeBox = await timeTracker.boundingBox();
    expect(netBox).not.toBeNull();
    expect(timeBox).not.toBeNull();
    // Single-column: the time-tracker tile begins after the net-worth tile.
    expect(timeBox!.y).toBeGreaterThan(netBox!.y + 50);
});
