import { test, expect } from '@playwright/test';

// Assert the desktop layout degrades gracefully on a phone-sized viewport:
// the sidebar hides behind a hamburger, toggling the hamburger opens and
// closes the drawer, ESC closes, and the dashboard grid collapses to a
// single column. The target widget names mirror those the authenticated
// smoke test already relies on, so both stay in sync.
test.use({ viewport: { width: 390, height: 844 } });

const signIn = async (page) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('owner@secretaire.local');
    await page.getByLabel('Password').fill('change-me');
    await page.getByRole('button', { name: 'Sign in with password' }).click();
    await expect(page).toHaveURL('/');
};

test('sidebar collapses into a hamburger drawer on mobile', async ({ page }) => {
    await signIn(page);

    const aside = page.getByRole('complementary', { name: 'Primary' });
    const hamburger = page.getByRole('button', { name: 'Toggle navigation' });

    await expect(hamburger).toBeVisible();

    // Drawer closed: the aside is translated off-screen to the left.
    // Playwright's toBeHidden() doesn't treat transform as hidden, so check
    // the bounding box — the right edge must be at or left of x=0.
    const offScreen = async () =>
        expect
            .poll(async () => {
                const box = await aside.boundingBox();
                return box ? box.x + box.width : null;
            })
            .toBeLessThanOrEqual(0);

    const onScreen = async () =>
        expect
            .poll(async () => {
                const box = await aside.boundingBox();
                return box?.x ?? null;
            })
            .toBeGreaterThanOrEqual(0);

    await offScreen();

    await hamburger.click();
    await onScreen();

    await page.keyboard.press('Escape');
    await offScreen();

    await hamburger.click();
    await onScreen();
    // Click the overlay on the right side of the viewport (away from the
    // 240px-wide aside on the left) so the click isn't intercepted by nav
    // links. The overlay is z-30 + md:hidden — under the aside (z-40) but
    // on top of page content.
    await page.locator('div.fixed.inset-0.z-30.md\\:hidden').click({
        position: { x: 340, y: 400 },
    });
    await offScreen();
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
