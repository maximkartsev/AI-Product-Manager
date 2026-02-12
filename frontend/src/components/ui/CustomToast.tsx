import { toast } from "sonner";
import { X } from "lucide-react";

interface CustomToastProps {
  id: string | number;
  icon: React.ReactNode;
  title: string;
  description?: string;
}

export function CustomToast({ id, icon, title, description }: CustomToastProps) {
  return (
    <div
      style={{
        position: "relative",
        overflow: "hidden",
        background: "rgba(9,9,11,0.9)",
        border: "1px solid rgba(255,255,255,0.1)",
        borderRadius: "1.5rem",
        padding: "20px 16px",
        display: "flex",
        alignItems: "flex-start",
        gap: "12px",
        fontFamily: "var(--font-geist-sans), sans-serif",
        boxShadow: "0 25px 50px -12px rgba(0,0,0,0.25)",
        maxWidth: "28rem",
        width: "100%",
      }}
    >
      {/* Fuchsia/indigo radial glow overlay */}
      <div
        aria-hidden
        style={{
          position: "absolute",
          inset: 0,
          background:
            "radial-gradient(ellipse at 30% 0%, rgba(217,70,239,0.08) 0%, transparent 60%), radial-gradient(ellipse at 70% 100%, rgba(99,102,241,0.06) 0%, transparent 60%)",
          pointerEvents: "none",
        }}
      />
      <div
        style={{
          position: "relative",
          background: "linear-gradient(to bottom right, rgba(217,70,239,0.25), rgba(139,92,246,0.2))",
          borderRadius: "12px",
          padding: "8px",
          flexShrink: 0,
          marginTop: "1px",
        }}
      >
        {icon}
      </div>
      <div style={{ position: "relative", flex: 1, minWidth: 0 }}>
        <p
          style={{
            margin: 0,
            fontSize: "14px",
            fontWeight: 600,
            color: "#ffffff",
            lineHeight: "1.4",
          }}
        >
          {title}
        </p>
        {description && (
          <p
            style={{
              margin: "4px 0 0",
              fontSize: "12px",
              color: "rgba(255,255,255,0.55)",
              lineHeight: "1.4",
            }}
          >
            {description}
          </p>
        )}
      </div>
      <button
        onClick={() => toast.dismiss(id)}
        style={{
          position: "relative",
          background: "none",
          border: "none",
          padding: "4px",
          cursor: "pointer",
          color: "rgba(255,255,255,0.4)",
          flexShrink: 0,
          borderRadius: "9999px",
          transition: "background 0.15s, color 0.15s",
        }}
        onMouseEnter={(e) => {
          e.currentTarget.style.background = "rgba(255,255,255,0.1)";
          e.currentTarget.style.color = "#ffffff";
        }}
        onMouseLeave={(e) => {
          e.currentTarget.style.background = "none";
          e.currentTarget.style.color = "rgba(255,255,255,0.4)";
        }}
        aria-label="Dismiss"
      >
        <X size={14} strokeWidth={2} />
      </button>
    </div>
  );
}

export function showCustomToast(opts: {
  icon: React.ReactNode;
  title: string;
  description?: string;
  duration?: number;
}): void {
  const { icon, title, description, duration = 6000 } = opts;
  toast.custom((id) => <CustomToast id={id} icon={icon} title={title} description={description} />, { duration });
}
