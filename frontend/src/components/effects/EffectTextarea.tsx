"use client";

import { useCallback, useEffect, useRef } from "react";
import { cn } from "@/lib/utils";

export interface EffectTextareaProps extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {}

export default function EffectTextarea({ className, onInput, ...props }: EffectTextareaProps) {
  const scrollRef = useRef<HTMLTextAreaElement | null>(null);
  const trackRef = useRef<HTMLDivElement | null>(null);
  const thumbRef = useRef<HTMLDivElement | null>(null);
  const dragRef = useRef<{
    startY: number;
    startScrollTop: number;
    scrollPerPx: number;
  } | null>(null);

  const updateThumb = useCallback(() => {
    const el = scrollRef.current;
    const track = trackRef.current;
    const thumb = thumbRef.current;
    if (!el || !track || !thumb) return;

    const scrollHeight = el.scrollHeight;
    const clientHeight = el.clientHeight;
    const maxScroll = scrollHeight - clientHeight;

    if (maxScroll <= 0) {
      track.style.opacity = "0";
      track.style.pointerEvents = "none";
      thumb.style.opacity = "0";
      thumb.style.height = "0px";
      thumb.style.transform = "translateY(0)";
      return;
    }

    track.style.opacity = "1";
    track.style.pointerEvents = "auto";

    const minThumb = 36;
    const trackHeight = track.clientHeight;
    const thumbHeight = Math.max(minThumb, (clientHeight / scrollHeight) * trackHeight);
    const maxThumbTop = Math.max(0, trackHeight - thumbHeight);
    const thumbTop = maxScroll > 0 ? (el.scrollTop / maxScroll) * maxThumbTop : 0;

    thumb.style.opacity = "1";
    thumb.style.height = `${thumbHeight}px`;
    thumb.style.transform = `translateY(${thumbTop}px)`;
  }, []);

  useEffect(() => {
    const el = scrollRef.current;
    if (!el) return;
    let frame = 0;
    const onScroll = () => {
      if (frame) return;
      frame = window.requestAnimationFrame(() => {
        frame = 0;
        updateThumb();
      });
    };

    updateThumb();
    el.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", updateThumb);

    const resizeObserver = new ResizeObserver(() => updateThumb());
    resizeObserver.observe(el);

    return () => {
      if (frame) window.cancelAnimationFrame(frame);
      el.removeEventListener("scroll", onScroll);
      window.removeEventListener("resize", updateThumb);
      resizeObserver.disconnect();
    };
  }, [updateThumb]);

  const handleInput = useCallback(
    (event: React.FormEvent<HTMLTextAreaElement>) => {
      onInput?.(event);
      updateThumb();
    },
    [onInput, updateThumb],
  );

  const handleThumbPointerDown = useCallback((event: React.PointerEvent<HTMLDivElement>) => {
    const el = scrollRef.current;
    const track = trackRef.current;
    const thumb = thumbRef.current;
    if (!el || !track || !thumb) return;

    const scrollHeight = el.scrollHeight;
    const clientHeight = el.clientHeight;
    const maxScroll = Math.max(0, scrollHeight - clientHeight);
    const trackHeight = track.clientHeight;
    const thumbHeight = thumb.getBoundingClientRect().height;
    const maxThumbTop = Math.max(0, trackHeight - thumbHeight);
    const scrollPerPx = maxThumbTop > 0 ? maxScroll / maxThumbTop : 0;

    dragRef.current = {
      startY: event.clientY,
      startScrollTop: el.scrollTop,
      scrollPerPx,
    };

    thumb.setPointerCapture(event.pointerId);
    event.preventDefault();
  }, []);

  const handleThumbPointerMove = useCallback((event: React.PointerEvent<HTMLDivElement>) => {
    const drag = dragRef.current;
    const el = scrollRef.current;
    if (!drag || !el) return;
    el.scrollTop = drag.startScrollTop + (event.clientY - drag.startY) * drag.scrollPerPx;
  }, []);

  const handleThumbPointerUp = useCallback((event: React.PointerEvent<HTMLDivElement>) => {
    const thumb = thumbRef.current;
    dragRef.current = null;
    if (thumb && thumb.hasPointerCapture(event.pointerId)) {
      thumb.releasePointerCapture(event.pointerId);
    }
  }, []);

  const handleTrackPointerDown = useCallback((event: React.PointerEvent<HTMLDivElement>) => {
    if (event.target === thumbRef.current) return;
    const el = scrollRef.current;
    const track = trackRef.current;
    const thumb = thumbRef.current;
    if (!el || !track || !thumb) return;

    const trackRect = track.getBoundingClientRect();
    const thumbHeight = thumb.getBoundingClientRect().height;
    const maxScroll = Math.max(0, el.scrollHeight - el.clientHeight);
    const maxThumbTop = Math.max(0, trackRect.height - thumbHeight);
    if (maxThumbTop <= 0) return;

    const offset = event.clientY - trackRect.top - thumbHeight / 2;
    const clamped = Math.max(0, Math.min(maxThumbTop, offset));
    el.scrollTop = (clamped / maxThumbTop) * maxScroll;
  }, []);

  return (
    <div className="relative">
      <textarea
        ref={scrollRef}
        onInput={handleInput}
        className={cn(
          "effect-textarea-scroll-body flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 pr-6 text-sm text-foreground",
          "placeholder:text-muted-foreground",
          "focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring",
          "disabled:cursor-not-allowed disabled:opacity-50",
          "resize-none",
          className,
        )}
        {...props}
      />
      <div className="effect-textarea-scrollbar pointer-events-auto absolute right-1 top-2 bottom-2 w-2">
        <div
          ref={trackRef}
          data-testid="effect-textarea-scroll-track"
          onPointerDown={handleTrackPointerDown}
          className="relative h-full w-full rounded-full bg-white/5"
        >
          <div
            ref={thumbRef}
            data-testid="effect-textarea-scroll-thumb"
            onPointerDown={handleThumbPointerDown}
            onPointerMove={handleThumbPointerMove}
            onPointerUp={handleThumbPointerUp}
            className="absolute left-0 top-0 w-full rounded-full bg-gradient-to-b from-fuchsia-300/22 to-violet-300/22"
            style={{ touchAction: "none" }}
          />
        </div>
      </div>
    </div>
  );
}
