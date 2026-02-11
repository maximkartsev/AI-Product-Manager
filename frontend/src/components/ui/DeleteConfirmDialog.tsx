"use client";

import React, { useState } from "react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { AlertTriangle, Loader2, Trash2 } from "lucide-react";

export function DeleteConfirmDialog({
  entityName,
  open,
  onOpenChange,
  itemTitle,
  itemId,
  onConfirm,
}: {
  entityName: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  itemTitle?: string;
  itemId?: number;
  onConfirm: () => Promise<void>;
}) {
  const [isDeleting, setIsDeleting] = useState(false);

  const handleConfirm = async () => {
    setIsDeleting(true);
    try {
      await onConfirm();
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!isDeleting) onOpenChange(o); }}>
      <DialogContent className="border-red-500/20">
        <DialogHeader>
          <div className="flex items-center justify-center w-16 h-16 mx-auto mb-4 rounded-full bg-red-500/10 border border-red-500/20">
            <AlertTriangle className="w-8 h-8 text-red-400" />
          </div>
          <DialogTitle className="text-center">Delete {entityName}?</DialogTitle>
          <DialogDescription className="text-center">
            This action cannot be undone. This will permanently delete the {entityName.toLowerCase()}
          </DialogDescription>
        </DialogHeader>

        {(itemTitle || itemId) && (
          <div className="px-2 py-4 bg-muted/50 border-y border-border rounded-md">
            <div className="flex items-start gap-3">
              <div className="flex-1 min-w-0">
                {itemTitle && (
                  <>
                    <p className="text-xs text-muted-foreground mb-1">{entityName} Title</p>
                    <p className="text-sm font-medium text-foreground truncate">{itemTitle}</p>
                  </>
                )}
                {itemId && (
                  <>
                    <p className="text-xs text-muted-foreground mt-2 mb-1">{entityName} ID</p>
                    <p className="text-sm text-muted-foreground">#{itemId}</p>
                  </>
                )}
              </div>
            </div>
          </div>
        )}

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isDeleting}
          >
            Cancel
          </Button>
          <Button
            type="button"
            className="bg-red-600 hover:bg-red-700 text-white border-red-600"
            onClick={handleConfirm}
            disabled={isDeleting}
          >
            {isDeleting ? (
              <>
                <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                Deleting...
              </>
            ) : (
              <>
                <Trash2 className="w-4 h-4 mr-2" />
                Delete {entityName}
              </>
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
