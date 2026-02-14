"use client";

import React from "react";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from "@/components/ui/sheet";

export function AdminDetailSheet({
  open,
  onOpenChange,
  title,
  description,
  children,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description?: string;
  children: React.ReactNode;
}) {
  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="overflow-y-auto">
        <div className="p-4 md:p-6 space-y-4 md:space-y-6">
          <SheetHeader className="border-b border-border pb-3 md:pb-4">
            <SheetTitle className="text-xl md:text-2xl">{title}</SheetTitle>
            {description && (
              <SheetDescription className="font-mono">{description}</SheetDescription>
            )}
          </SheetHeader>
          {children}
        </div>
      </SheetContent>
    </Sheet>
  );
}

export function AdminDetailSection({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-3">
      <h3 className="text-sm font-semibold text-foreground border-b border-border pb-2">
        {title}
      </h3>
      {children}
    </div>
  );
}
