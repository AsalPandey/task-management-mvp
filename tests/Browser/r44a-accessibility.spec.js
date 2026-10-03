import { test, expect } from '@playwright/test';

test('R44A compiled styling preserves reduced motion and visible keyboard focus', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/login');
    const email = page.locator('#email');
    await email.focus();
    const result = await email.evaluate(element => {
        const style = getComputedStyle(element);
        const seconds = value => Math.max(...value.split(',').map(part => parseFloat(part) * (part.trim().endsWith('ms') ? 0.001 : 1)));
        return {
            reduced: matchMedia('(prefers-reduced-motion: reduce)').matches,
            transition: seconds(style.transitionDuration),
            animation: seconds(style.animationDuration),
            outline: style.outlineStyle,
            outlineWidth: parseFloat(style.outlineWidth),
        };
    });
    expect(result.reduced).toBe(true);
    expect(result.transition).toBeLessThanOrEqual(0.00001);
    expect(result.animation).toBeLessThanOrEqual(0.00001);
    expect(result.outline).toBe('solid');
    expect(result.outlineWidth).toBeGreaterThanOrEqual(2);
    await page.keyboard.press('Tab');
    await expect(page.locator('#password')).toBeFocused();
    await page.emulateMedia({ forcedColors: 'active' });
    await email.focus();
    expect(await email.evaluate(element => getComputedStyle(element).outlineStyle)).not.toBe('none');
});
