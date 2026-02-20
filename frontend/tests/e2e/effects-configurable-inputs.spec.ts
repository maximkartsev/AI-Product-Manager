import { expect, test } from "@playwright/test";

test("configurable effect shows prompt input", async ({ page }) => {
  await page.goto("/effects/bunny-character");

  const promptLabel = page.getByText("Style Prompt", { exact: false });
  await expect(promptLabel).toBeVisible();
});

test("configurable effect allows spaces in prompts", async ({ page }) => {
  await page.goto("/effects/bunny-character");

  const prompt = page.locator("textarea").first();
  await expect(prompt).toBeVisible();

  await prompt.fill("hello world");
  await expect(prompt).toHaveValue("hello world");
});
