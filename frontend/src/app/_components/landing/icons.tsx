import type { SVGProps } from "react";

type IconProps = SVGProps<SVGSVGElement> & {
  title?: string;
};

function IconBase({ title, children, ...props }: IconProps) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden={title ? undefined : true}
      {...props}
    >
      {title ? <title>{title}</title> : null}
      {children}
    </svg>
  );
}

export function IconSparkles(props: IconProps) {
  return (
    <IconBase title={props.title ?? "Sparkles"} {...props}>
      <path d="M12 2l1.2 4.1L17 7.3l-3.8 1.2L12 12l-1.2-3.5L7 7.3l3.8-1.2L12 2z" />
      <path d="M5 11l.8 2.7L8.5 15l-2.7.8L5 18l-.8-2.2L1.5 15l2.7-1.3L5 11z" />
      <path d="M19 13l.7 2.2L22 16l-2.3.8L19 19l-.7-2.2L16 16l2.3-.8L19 13z" />
    </IconBase>
  );
}

export function IconPlay(props: IconProps) {
  return (
    <IconBase title={props.title ?? "Play"} {...props}>
      <path d="M10 8l6 4-6 4V8z" />
    </IconBase>
  );
}

export function IconArrowRight(props: IconProps) {
  return (
    <IconBase title={props.title ?? "Arrow right"} {...props}>
      <path d="M5 12h12" />
      <path d="M13 6l6 6-6 6" />
    </IconBase>
  );
}

export function IconX(props: IconProps) {
  return (
    <IconBase title={props.title ?? "Close"} {...props}>
      <path d="M18 6L6 18" />
      <path d="M6 6l12 12" />
    </IconBase>
  );
}

export function IconBolt(props: IconProps) {
  return (
    <IconBase title={props.title ?? "Bolt"} {...props}>
      <path d="M13 2L3 14h7l-1 8 12-16h-7l1-4z" />
    </IconBase>
  );
}

export function IconWand(props: IconProps) {
  return (
    <IconBase title={props.title ?? "Magic wand"} {...props}>
      <path d="M3 21l9-9" />
      <path d="M11 3l10 10" />
      <path d="M14 6l4 4" />
      <path d="M11 7l-1-1" />
      <path d="M7 11l-1-1" />
      <path d="M15 3l2 2" />
      <path d="M3 15l2 2" />
    </IconBase>
  );
}

export function IconGallery(props: IconProps) {
  return (
    <IconBase title={props.title ?? "Gallery"} {...props}>
      <path d="M4 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7z" />
      <path d="M8 11l2.5 2.5L14 10l4 5" />
      <path d="M9 9h.01" />
    </IconBase>
  );
}

export function IconUser(props: IconProps) {
  return (
    <IconBase title={props.title ?? "User"} {...props}>
      <path d="M20 21a8 8 0 0 0-16 0" />
      <circle cx="12" cy="8" r="4" />
    </IconBase>
  );
}

export function IconMail(props: IconProps) {
  return (
    <IconBase title={props.title ?? "Email"} {...props}>
      <path d="M4 6h16v12H4z" />
      <path d="M4 7l8 6 8-6" />
    </IconBase>
  );
}

export function IconLock(props: IconProps) {
  return (
    <IconBase title={props.title ?? "Lock"} {...props}>
      <rect x="5" y="11" width="14" height="10" rx="2" />
      <path d="M8 11V8a4 4 0 0 1 8 0v3" />
    </IconBase>
  );
}

export function IconEye(props: IconProps) {
  return (
    <IconBase title={props.title ?? "Show password"} {...props}>
      <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z" />
      <circle cx="12" cy="12" r="3" />
    </IconBase>
  );
}

export function IconEyeOff(props: IconProps) {
  return (
    <IconBase title={props.title ?? "Hide password"} {...props}>
      <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z" />
      <circle cx="12" cy="12" r="3" />
      <path d="M4 4l16 16" />
    </IconBase>
  );
}

export function IconApple(props: IconProps) {
  const { title, ...rest } = props;

  // Apple logo (CC0-1.0) from Simple Icons: https://simpleicons.org/?q=apple
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      stroke="none"
      aria-hidden={title ? undefined : true}
      {...rest}
    >
      {title ? <title>{title}</title> : null}
      <path d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09zM15.53 3.83c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.701" />
    </svg>
  );
}

export function IconMusic(props: IconProps) {
  return (
    <IconBase title={props.title ?? "Music"} {...props}>
      <path d="M9 18a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z" />
      <path d="M12 6v10" />
      <path d="M12 6c2 2 4 3 7 3" />
    </IconBase>
  );
}

