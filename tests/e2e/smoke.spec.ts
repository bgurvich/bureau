import { test, expect } from '@playwright/test';

test('login page renders', async ({ page }) => {
    await page.goto('/login');
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
    await expect(page.getByLabel('Email')).toBeVisible();
    await expect(page.getByLabel('Password')).toBeVisible();
});

test('dashboard is behind auth (unauthenticated redirects to login)', async ({ page }) => {
    const response = await page.goto('/');
    expect(page.url()).toContain('/login');
    expect(response?.status()).toBeLessThan(400);
});

test('authenticated user sees the dashboard radars', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('boris@gurvich.me');
    await page.getByLabel('Password').fill('change-me');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page).toHaveURL('/');
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    await expect(page.getByText('Net worth')).toBeVisible();
    await expect(page.getByText('Time tracker')).toBeVisible();
});
