import { test, expect } from '@playwright/test';

test('login page renders', async ({ page }) => {
    await page.goto('/login');
    await expect(page.getByRole('heading', { name: 'Sign in', level: 1 })).toBeVisible();
    await expect(page.getByLabel('Email', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Password')).toBeVisible();
});

test('dashboard is behind auth (unauthenticated redirects to login)', async ({ page }) => {
    const response = await page.goto('/');
    expect(page.url()).toContain('/login');
    expect(response?.status()).toBeLessThan(400);
});

test('authenticated user sees the dashboard radars', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('boris@gurvich.me');
    await page.getByLabel('Password').fill('change-me');
    await page.getByRole('button', { name: 'Sign in with password' }).click();

    await expect(page).toHaveURL('/');
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    await expect(page.getByText('Net worth')).toBeVisible();
    await expect(page.getByText('Time tracker')).toBeVisible();
});

test('user-menu dropdown opens and signs out', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('boris@gurvich.me');
    await page.getByLabel('Password').fill('change-me');
    await page.getByRole('button', { name: 'Sign in with password' }).click();
    await expect(page).toHaveURL('/');

    const trigger = page.getByRole('button', { name: 'Open user menu' });
    await expect(trigger).toBeVisible();
    await trigger.click();

    const menu = page.getByRole('menu', { name: 'User menu' });
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('menuitem', { name: 'Profile' })).toBeVisible();
    await expect(menu.getByRole('menuitem', { name: 'Theme' })).toBeVisible();
    await expect(menu.getByRole('menuitem', { name: 'Language' })).toBeVisible();

    await menu.getByRole('menuitem', { name: 'Sign out' }).click();
    await expect(page).toHaveURL('/login');
});

test('theme toggle flips the rendered palette', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('boris@gurvich.me');
    await page.getByLabel('Password').fill('change-me');
    await page.getByRole('button', { name: 'Sign in with password' }).click();
    await expect(page).toHaveURL('/');

    const bodyBg = () => page.evaluate(() => getComputedStyle(document.body).backgroundColor);
    const resolvedTheme = () => page.evaluate(() => document.documentElement.dataset.resolvedTheme);
    const persistedTheme = () => page.evaluate(() => document.documentElement.dataset.theme);

    // Capture the user's real stored preference so we can restore it after the
    // test — otherwise we'd clobber their UI setting every CI run.
    const originalTheme = (await persistedTheme()) ?? 'system';
    const capitalize = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

    const menu = page.getByRole('menu', { name: 'User menu' });
    const pickTheme = async (name: string) => {
        // Reset to a known closed state — Alpine state may carry over across
        // Livewire re-renders from the previous pick.
        await page.keyboard.press('Escape');
        await expect(menu).toBeHidden();
        await page.getByRole('button', { name: 'Open user menu' }).click();
        await expect(menu).toBeVisible();
        const theme = page.getByRole('menuitem', { name: 'Theme' });
        await expect(theme).toBeVisible();
        await theme.click();
        const radio = page.getByRole('menuitemradio', { name });
        await expect(radio).toBeVisible();
        await radio.click();
    };

    try {
        await pickTheme('Dark');
        await expect.poll(resolvedTheme).toBe('dark');
        const darkBg = await bodyBg();

        await pickTheme('Light');
        await expect.poll(resolvedTheme).toBe('light');
        const lightBg = await bodyBg();
        expect(lightBg).not.toBe(darkBg);

        await pickTheme('Dark');
        await expect.poll(resolvedTheme).toBe('dark');
        expect(await bodyBg()).toBe(darkBg);
    } finally {
        // Restore the user's stored preference no matter how the test exits.
        await pickTheme(capitalize(originalTheme));
    }
});
