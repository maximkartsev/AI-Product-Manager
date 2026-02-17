"use client";

import React, { useRef } from "react";
import { flexRender } from "@tanstack/react-table";
import type { Header } from "@tanstack/react-table";
import { useSortable } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { TableHead } from "@/components/ui/table";
import { ArrowUp, ArrowDown, ArrowUpDown, GripVertical } from "lucide-react";

export function DraggableTableHeader({ header }: {
  header: Header<any, unknown>;
}) {
  const { attributes, isDragging, listeners, setNodeRef, transform } = useSortable({
    id: header.column.id,
  });
  const wasResizingRef = useRef(false);
  const isActions = header.id === "_actions";

  const style: React.CSSProperties = {
    opacity: isDragging ? 0.8 : 1,
    position: isActions ? "sticky" : "relative",
    right: isActions ? 0 : undefined,
    transform: isActions ? undefined : CSS.Translate.toString(transform),
    transition: "width transform 0.2s ease-in-out",
    whiteSpace: "nowrap",
    width: header.getSize(),
    minWidth: header.column.columnDef.minSize,
    zIndex: isActions ? 20 : (isDragging ? 1 : 0),
    overflow: "hidden",
  };

  return (
    <TableHead
      ref={setNodeRef}
      style={style}
      className={`relative select-none ${
        header.column.getCanSort() ? "cursor-pointer hover:bg-accent/50 transition-colors" : ""
      } ${isActions ? "text-right bg-background shadow-[-4px_0_8px_-4px_rgba(0,0,0,0.1)]" : ""}`}
      onClick={(e) => {
        if (wasResizingRef.current) return;
        header.column.getToggleSortingHandler()?.(e);
      }}
    >
      <div className="flex items-center gap-1 overflow-hidden min-w-0">
        {header.id !== "_actions" && (
          <button
            type="button"
            className="cursor-grab touch-none p-0.5 -ml-1 text-muted-foreground hover:text-foreground"
            {...attributes}
            {...listeners}
            onClick={(e) => e.stopPropagation()}
          >
            <GripVertical className="w-3.5 h-3.5" />
          </button>
        )}
        <span className="text-sm truncate">
          {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
        </span>
        {header.column.getCanSort() && (
          header.column.getIsSorted() === "asc"
            ? <ArrowUp className="w-4 h-4 shrink-0 text-primary" />
            : header.column.getIsSorted() === "desc"
              ? <ArrowDown className="w-4 h-4 shrink-0 text-primary" />
              : <ArrowUpDown className="w-4 h-4 shrink-0 text-muted-foreground" />
        )}
      </div>
      {header.column.getCanResize() && (
        <div
          onMouseDown={(e) => {
            e.stopPropagation();
            wasResizingRef.current = true;
            document.addEventListener("mouseup", () => {
              setTimeout(() => { wasResizingRef.current = false; }, 0);
            }, { once: true });
            header.getResizeHandler()(e);
          }}
          onTouchStart={(e) => {
            e.stopPropagation();
            wasResizingRef.current = true;
            document.addEventListener("touchend", () => {
              setTimeout(() => { wasResizingRef.current = false; }, 0);
            }, { once: true });
            header.getResizeHandler()(e);
          }}
          className={`absolute right-0 top-0 bottom-0 w-1.5 cursor-col-resize hover:bg-primary/30 z-10 ${
            header.column.getIsResizing() ? "bg-primary/30" : ""
          }`}
          onClick={e => e.stopPropagation()}
        />
      )}
    </TableHead>
  );
}
