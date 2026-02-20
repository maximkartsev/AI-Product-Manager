import { expect, test } from "@playwright/test";

test("configurable effect shows prompt input", async ({ page }) => {
  await page.goto("/effects/bunny-character");

  const promptLabel = page.getByText("Style Prompt", { exact: false });
  await expect(promptLabel).toBeVisible();
});
