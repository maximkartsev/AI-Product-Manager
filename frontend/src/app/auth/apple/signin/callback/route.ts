import { NextRequest, NextResponse } from "next/server";

export async function POST(request: NextRequest) {
  const formData = await request.formData();
  const params = new URLSearchParams();
  for (const [key, value] of formData.entries()) {
    params.append(key, value.toString());
  }

  const backendUrl =
    process.env.NEXT_PUBLIC_API_BASE_URL ?? process.env.NEXT_PUBLIC_API_URL;

  // Resolve public origin from forwarded headers (ngrok sets these)
  const proto = request.headers.get("x-forwarded-proto") ?? "https";
  const host =
    request.headers.get("x-forwarded-host") ??
    request.headers.get("host") ??
    request.nextUrl.host;
  const origin = `${proto}://${host}`;
  const backendBase = backendUrl
    ? backendUrl.startsWith("/") ? `${origin}${backendUrl}` : backendUrl
    : null;

  let res: Response;
  try {
    if (!backendBase) throw new Error("Missing backend base URL");
    res = await fetch(`${backendBase}/auth/apple/signin/callback`, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: params.toString(),
      redirect: "manual",
    });
  } catch (e) {
    const msg = e instanceof Error ? e.message : "Backend unreachable";
    return NextResponse.redirect(
      `${origin}/auth/apple/signin/done?error=${encodeURIComponent(msg)}`,
    );
  }

  const location = res.headers.get("Location");
  if (location) {
    return NextResponse.redirect(location);
  }

  // Forward backend error if no redirect
  let errorMsg = `Backend returned ${res.status}`;
  try {
    const body = await res.text();
    if (body) errorMsg += `: ${body.substring(0, 200)}`;
  } catch {}

  return NextResponse.redirect(
    `${origin}/auth/apple/signin/done?error=${encodeURIComponent(errorMsg)}`,
  );
}
