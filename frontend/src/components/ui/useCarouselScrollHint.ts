import { useEffect, useState } from "react";

type UseCarouselScrollHintOptions = {
  scrollRef: React.RefObject<HTMLDivElement | null>;
  isLoading: boolean;
  deps?: unknown[];
};

export default function useCarouselScrollHint({
  scrollRef,
  isLoading,
  deps = [],
}: UseCarouselScrollHintOptions) {
  const [showHint, setShowHint] = useState(true);

  useEffect(() => {
    if (isLoading) {
      setShowHint(true);
      return;
    }

    const el = scrollRef.current;
    if (!el) {
      setShowHint(false);
      return;
    }

    const update = () => {
      const scrollable = el.scrollWidth > el.clientWidth + 1;
      setShowHint(scrollable);
    };

    update();

    let observer: ResizeObserver | null = null;
    if (typeof ResizeObserver !== "undefined") {
      observer = new ResizeObserver(update);
      observer.observe(el);
    }

    window.addEventListener("resize", update);
    return () => {
      window.removeEventListener("resize", update);
      observer?.disconnect();
    };
  }, [isLoading, scrollRef, ...deps]);

  return showHint;
}
