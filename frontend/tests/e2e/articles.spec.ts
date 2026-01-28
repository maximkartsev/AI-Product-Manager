import { expect, test, type APIRequestContext } from "@playwright/test";

type RegisterResponse = {
  token: string;
  name: string;
  tenant: {
    domain: string;
  };
};

type MeResponse = {
  id: number;
  email: string;
  name: string;
};

function requireApiBaseUrl(): string {
  const base = process.env.NEXT_PUBLIC_API_BASE_URL;
  if (!base) {
    throw new Error(
      "NEXT_PUBLIC_API_BASE_URL is required for e2e. It should look like http://localhost:<port>/api",
    );
  }
  return base.replace(/\/$/, "");
}

function apiUrl(path: string): string {
  return apiUrlOnBase(requireApiBaseUrl(), path);
}

function apiUrlOnBase(baseUrl: string, path: string): string {
  const normalized = path.startsWith("/") ? path : `/${path}`;
  return `${baseUrl.replace(/\/$/, "")}${normalized}`;
}

async function registerUser(
  request: APIRequestContext,
): Promise<{ token: string; tenantDomain: string; me: MeResponse }> {
  const email = `e2e+${Date.now()}-${Math.random().toString(16).slice(2)}@example.com`;
  const password = "12345678";

  const registerRes = await request.post(apiUrl("/register"), {
    data: {
      name: "E2E User",
      email,
      password,
      c_password: password,
    },
    headers: { Accept: "application/json" },
  });

  if (!registerRes.ok()) {
    const body = await registerRes.text();
    throw new Error(`register failed: ${registerRes.url()} HTTP ${registerRes.status()} ${body}`);
  }

  const registerJson = (await registerRes.json()) as {
    success: boolean;
    data?: RegisterResponse;
    message?: string;
  };

  expect(registerJson.success).toBeTruthy();
  expect(registerJson.data?.token).toBeTruthy();

  const token = registerJson.data!.token;
  const tenantDomain = registerJson.data!.tenant.domain;

  const meRes = await request.get(apiUrl("/me"), {
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${token}`,
      Host: tenantDomain,
    },
  });

  expect(meRes.ok()).toBeTruthy();
  const meJson = (await meRes.json()) as { success: boolean; data?: MeResponse };
  expect(meJson.success).toBeTruthy();
  expect(meJson.data?.id).toBeTruthy();

  return { token, tenantDomain, me: meJson.data! };
}

async function createArticle(
  request: APIRequestContext,
  tenantDomain: string,
  token: string,
  userId: number,
  title: string,
): Promise<void> {
  const res = await request.post(apiUrl("/articles"), {
    data: {
      title,
      state: "draft",
      user_id: userId,
    },
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${token}`,
      Host: tenantDomain,
    },
  });

  expect(res.ok()).toBeTruthy();
}

async function delay(ms: number) {
  await new Promise((r) => setTimeout(r, ms));
}

test.describe("UI integration gate: /articles", () => {
  test("shows controlled error state when unauthenticated (401)", async ({ page, request }) => {
    const { tenantDomain } = await registerUser(request);

    await page.addInitScript(() => {
      window.localStorage.removeItem("auth_token");
    });
    await page.addInitScript((domain: string) => {
      window.localStorage.setItem("tenant_domain", domain);
    }, tenantDomain);

    // Ensure the Loading state is observable (delay the real request).
    await page.route("**/api/articles*", async (route) => {
      await delay(300);
      await route.continue();
    });

    await page.goto("/articles");

    await expect(page.getByText("Loading…")).toBeVisible();
    await expect(page.getByText("Error", { exact: true })).toBeVisible();
    await expect(page.getByText(/HTTP 401/)).toBeVisible();
  });

  test("shows empty state when search yields no results", async ({ page, request }) => {
    const { token, tenantDomain } = await registerUser(request);

    await page.addInitScript(
      ({ t, domain }: { t: string; domain: string }) => {
      window.localStorage.setItem("auth_token", t);
      window.localStorage.setItem("tenant_domain", domain);
      },
      { t: token, domain: tenantDomain },
    );

    await page.route("**/api/articles*", async (route) => {
      await delay(300);
      await route.continue();
    });

    const neverMatch = `__no_match_${Date.now()}__`;
    await page.goto(`/articles?q=${encodeURIComponent(neverMatch)}`);

    await expect(page.getByText("Loading…")).toBeVisible();
    await expect(page.getByText("No articles yet.")).toBeVisible();
  });

  test("shows success state and renders created article", async ({ page, request }) => {
    const { token, tenantDomain, me } = await registerUser(request);
    const title = `E2E Article ${Date.now()}`;

    await createArticle(request, tenantDomain, token, me.id, title);

    await page.addInitScript(
      ({ t, domain }: { t: string; domain: string }) => {
      window.localStorage.setItem("auth_token", t);
      window.localStorage.setItem("tenant_domain", domain);
      },
      { t: token, domain: tenantDomain },
    );

    await page.route("**/api/articles*", async (route) => {
      await delay(300);
      await route.continue();
    });

    await page.goto(`/articles?q=${encodeURIComponent(title)}`);

    await expect(page.getByText("Loading…")).toBeVisible();
    await expect(page.getByText(title)).toBeVisible();
  });
});

