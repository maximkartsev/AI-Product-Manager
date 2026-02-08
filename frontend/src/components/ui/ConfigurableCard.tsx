import { cn } from "@/lib/utils";
import type { ComponentPropsWithoutRef, ElementType, ReactNode } from "react";

type ConfigurableCardProps<T extends ElementType = "div"> = {
  as?: T;
  className?: string;
  frameClassName?: string;
  mediaClassName?: string;
  bodyClassName?: string;
  media: ReactNode;
  body?: ReactNode;
  bodyInsideFrame?: boolean;
} & Omit<ComponentPropsWithoutRef<T>, "className" | "children">;

export default function ConfigurableCard<T extends ElementType = "div">({
  as,
  className,
  frameClassName,
  mediaClassName,
  bodyClassName,
  media,
  body,
  bodyInsideFrame = false,
  ...rest
}: ConfigurableCardProps<T>) {
  const Component = (as ?? "div") as ElementType;
  const mediaNode = mediaClassName ? <div className={cn(mediaClassName)}>{media}</div> : <>{media}</>;
  const bodyNode = body ? <div className={cn(bodyClassName)}>{body}</div> : null;
  const renderBodyInFrame = Boolean(frameClassName && bodyInsideFrame);

  return (
    <Component className={cn(className)} {...rest}>
      {frameClassName ? (
        <div className={cn(frameClassName)}>
          {mediaNode}
          {renderBodyInFrame ? bodyNode : null}
        </div>
      ) : (
        mediaNode
      )}
      {frameClassName ? (!renderBodyInFrame ? bodyNode : null) : bodyNode}
    </Component>
  );
}
