"use client";

import React from "react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";

export function VideoPreviewDialog({
  url,
  onClose,
}: {
  url: string | null;
  onClose: () => void;
}) {
  return (
    <Dialog open={!!url} onOpenChange={(open) => { if (!open) onClose(); }}>
      <DialogContent className="max-w-3xl p-0 overflow-hidden">
        <DialogHeader className="sr-only">
          <DialogTitle>Video Preview</DialogTitle>
          <DialogDescription>Preview of the video</DialogDescription>
        </DialogHeader>
        {url && (
          <video
            src={url}
            controls
            autoPlay
            className="w-full max-h-[80vh]"
          />
        )}
      </DialogContent>
    </Dialog>
  );
}
