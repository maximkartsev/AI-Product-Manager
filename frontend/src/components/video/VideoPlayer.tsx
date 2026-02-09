import { cn } from "@/lib/utils";
import { type ReactNode } from "react";

type VideoPlayerProps = {
  src?: string | null;
  className?: string;
  autoPlay?: boolean;
  loop?: boolean;
  muted?: boolean;
  playsInline?: boolean;
  controls?: boolean;
  preload?: "auto" | "metadata" | "none";
  onError?: () => void;
  onLoadedData?: () => void;
  onPlaying?: () => void;
  onTimeUpdate?: () => void;
  videoRef?: React.Ref<HTMLVideoElement>;
  children?: ReactNode;
};

export default function VideoPlayer({
  src,
  className,
  autoPlay = false,
  loop = false,
  muted = false,
  playsInline = true,
  controls = false,
  preload = "metadata",
  onError,
  onLoadedData,
  onPlaying,
  onTimeUpdate,
  videoRef,
  children,
}: VideoPlayerProps) {
  if (!src) {
    return <>{children ?? null}</>;
  }

  return (
    <video
      ref={videoRef}
      className={cn("h-full w-full", className)}
      src={src}
      autoPlay={autoPlay}
      loop={loop}
      muted={muted}
      playsInline={playsInline}
      controls={controls}
      preload={preload}
      onError={onError}
      onLoadedData={onLoadedData}
      onPlaying={onPlaying}
      onTimeUpdate={onTimeUpdate}
    />
  );
}
