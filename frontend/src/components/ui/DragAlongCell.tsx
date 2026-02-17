"use client";

import React from "react";
import { flexRender } from "@tanstack/react-table";
import type { Cell } from "@tanstack/react-table";
import { useSortable } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { TableCell } from "@/components/ui/table";

export function DragAlongCell({ cell }: {
  cell: Cell<any, unknown>;
}) {
  const { isDragging, setNodeRef, transform } = useSortable({
    id: cell.column.id,
  });
  const isActions = cell.column.id === "_actions";

  const style: React.CSSProperties = {
    opacity: isDragging ? 0.8 : 1,
    position: isActions ? "sticky" : "relative",
    right: isActions ? 0 : undefined,
    transform: isActions ? undefined : CSS.Translate.toString(transform),
    transition: "width transform 0.2s ease-in-out",
    width: cell.column.getSize(),
    minWidth: cell.column.columnDef.minSize,
    zIndex: isActions ? 20 : (isDragging ? 1 : 0),
  };

  return (
    <TableCell
      ref={setNodeRef}
      style={style}
      className={`text-sm ${isActions ? "" : "overflow-hidden"} ${
        ["created_at", "updated_at", "published_at"].includes(cell.column.id)
          ? "whitespace-nowrap"
          : ""
      } ${isActions ? "text-right whitespace-nowrap bg-background shadow-[-4px_0_8px_-4px_rgba(0,0,0,0.1)]" : ""}`}
    >
      {flexRender(cell.column.columnDef.cell, cell.getContext())}
    </TableCell>
  );
}
