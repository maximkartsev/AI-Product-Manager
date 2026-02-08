"use client";

import { Children, isValidElement, useEffect, useRef } from "react";
import { cn } from "@/lib/utils";

type HorizontalCarouselProps = {
  children: React.ReactNode;
  className?: string;
  innerClassName?: string;
  showRightFade?: boolean;
  fadeWidthClassName?: string;
  onReachEnd?: () => void;
};

export default function HorizontalCarousel({
  children,
  className,
  innerClassName,
  showRightFade = false,
  fadeWidthClassName,
  onReachEnd,
}: HorizontalCarouselProps) {
  const scrollerRef = useRef<HTMLDivElement | null>(null);
  const endGuardRef = useRef(false);

  useEffect(() => {
    if (!onReachEnd) return;
    const el = scrollerRef.current;
    if (!el) return;

    const handleScroll = () => {
      if (endGuardRef.current) return;
      const threshold = 32;
      if (el.scrollLeft + el.clientWidth >= el.scrollWidth - threshold) {
        endGuardRef.current = true;
        onReachEnd();
        window.setTimeout(() => {
          endGuardRef.current = false;
        }, 200);
      }
    };

    el.addEventListener("scroll", handleScroll, { passive: true });
    return () => el.removeEventListener("scroll", handleScroll);
  }, [onReachEnd]);

  const wrappedChildren = Children.map(children, (child, idx) => {
    const key = isValidElement(child) ? (child.key ?? idx) : idx;
    return (
      <div key={key} className="snap-start shrink-0">
        {child}
      </div>
    );
  });

  return (
    <div className={cn("relative", className)}>
      <div
        ref={scrollerRef}
        className={cn(
          "overflow-x-auto px-4 pb-2 no-scrollbar snap-x snap-mandatory scroll-px-4",
          innerClassName,
        )}
      >
        <div className="flex w-max gap-3">{wrappedChildren}</div>
      </div>
      {showRightFade ? (
        <div
          className={cn(
            "pointer-events-none absolute right-0 top-0 h-full bg-gradient-to-l from-[#05050a]/30 via-[#05050a]/10 to-transparent",
            fadeWidthClassName ?? "w-12",
          )}
        />
      ) : null}
    </div>
  );
}

