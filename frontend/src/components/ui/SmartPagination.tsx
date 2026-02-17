import React from "react";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { ChevronLeft, ChevronRight } from "lucide-react";

export interface PaginationData {
  page: number;
  perPage: number;
  totalPages: number;
  totalItems: number;
}

interface SmartPaginationProps {
  pagination: PaginationData;
  onPageChange: (page: number) => void;
  onPerPageChange: (perPage: number) => void;
  itemName?: string;
  className?: string;
  perPageOptions?: number[];
}

export default function SmartPagination({
  pagination,
  onPageChange,
  onPerPageChange,
  itemName = "items",
  className = "",
  perPageOptions = [10, 20, 50, 100],
}: SmartPaginationProps) {
  const { page, perPage, totalPages, totalItems } = pagination;

  const startItem = (page - 1) * perPage + 1;
  const endItem = Math.min(page * perPage, totalItems);

  if (totalItems === 0) return null;

  return (
    <div className={`flex flex-col sm:flex-row items-center justify-between gap-4 ${className}`}>
      <div className="flex items-center space-x-2">
        <span className="text-sm text-muted-foreground">Show</span>
        <Select
          value={perPage.toString()}
          onValueChange={(value) => onPerPageChange(Number(value))}
        >
          <SelectTrigger className="w-20 h-9">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {perPageOptions.map((option) => (
              <SelectItem key={option} value={option.toString()}>
                {option}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <span className="text-sm text-muted-foreground">per page</span>
      </div>

      <div className="text-sm text-muted-foreground">
        Showing {startItem}-{endItem} of {totalItems} {itemName}
      </div>

      <div className="flex items-center space-x-2">
        <Button
          variant="outline"
          size="sm"
          onClick={() => onPageChange(page - 1)}
          disabled={page === 1}
        >
          <ChevronLeft className="w-4 h-4" />
          Previous
        </Button>

        <div className="flex items-center space-x-1">
          {Array.from({ length: Math.min(totalPages, 5) }, (_, i) => {
            let pageNum: number;
            if (totalPages <= 5) {
              pageNum = i + 1;
            } else if (page <= 3) {
              pageNum = i + 1;
            } else if (page >= totalPages - 2) {
              pageNum = totalPages - 4 + i;
            } else {
              pageNum = page - 2 + i;
            }

            return (
              <Button
                key={pageNum}
                variant={pageNum === page ? "default" : "outline"}
                size="sm"
                onClick={() => onPageChange(pageNum)}
              >
                {pageNum}
              </Button>
            );
          })}
        </div>

        <Button
          variant="outline"
          size="sm"
          onClick={() => onPageChange(page + 1)}
          disabled={page === totalPages}
        >
          Next
          <ChevronRight className="w-4 h-4" />
        </Button>
      </div>
    </div>
  );
}
