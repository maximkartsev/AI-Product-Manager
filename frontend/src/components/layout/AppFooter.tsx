"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { IconSparkles } from "@/app/_components/landing/icons";
import { brand } from "@/app/_components/landing/landingData";

export default function AppFooter() {
  const pathname = usePathname();

  if (pathname?.startsWith("/admin")) return null;

  return (
    <footer className="border-t border-white/10 bg-[#05050a] text-white/70">
      <div className="mx-auto w-full max-w-md px-4 py-8 sm:max-w-xl lg:max-w-4xl">
        <div className="flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between">
          <div className="flex flex-col gap-3">
            <Link
              href="/"
              className="inline-flex items-center gap-2 text-sm font-semibold tracking-tight text-white"
              aria-label={`${brand.name} home`}
            >
              <span className="grid h-8 w-8 place-items-center rounded-xl bg-white/10">
                <IconSparkles className="h-4 w-4 text-fuchsia-200" />
              </span>
              <span className="uppercase">{brand.name}</span>
            </Link>
            <p className="text-xs text-white/50">{brand.tagline}</p>
          </div>

          <div className="flex flex-col gap-2 text-xs sm:items-end">
            <div className="flex flex-wrap gap-4">
              <Link
                href="/privacy"
                className="transition hover:text-white"
              >
                Privacy Policy
              </Link>
              <Link
                href="/terms"
                className="transition hover:text-white"
              >
                Terms of Service
              </Link>
              <Link
                href="/gdpr"
                className="transition hover:text-white"
              >
                GDPR
              </Link>
              <Link
                href="/content-policy"
                className="transition hover:text-white"
              >
                Content Policy
              </Link>
              <Link
                href="/2257"
                className="transition hover:text-white"
              >
                2257 Compliance
              </Link>
            </div>
          </div>
        </div>

        <div className="mt-6 border-t border-white/10 pt-4 text-center text-xs text-white/40">
          &copy; {new Date().getFullYear()} Dzzzs.com. All rights reserved.
        </div>
      </div>
    </footer>
  );
}
