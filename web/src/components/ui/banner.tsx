import type { ReactNode } from "react";
import { cn } from "@/lib/cn";

export type BannerTone = "error" | "success" | "warning" | "info";

export type BannerProps = {
  tone?: BannerTone;
  title?: ReactNode;
  children?: ReactNode;
  /** Right-aligned action (button / link). */
  action?: ReactNode;
  onClose?: () => void;
  className?: string;
};

const toneClasses: Record<BannerTone, string> = {
  error: "border-rose-200 bg-rose-50 text-rose-800",
  success: "border-emerald-200 bg-emerald-50 text-emerald-800",
  warning: "border-amber-200 bg-amber-50 text-amber-900",
  info: "border-sky-200 bg-sky-50 text-sky-900",
};

const roleByTone: Record<BannerTone, "alert" | "status"> = {
  error: "alert",
  warning: "alert",
  success: "status",
  info: "status",
};

export function Banner({ tone = "info", title, children, action, onClose, className }: BannerProps) {
  return (
    <div
      role={roleByTone[tone]}
      className={cn(
        "flex items-start gap-3 rounded-2xl border px-4 py-3 text-sm leading-6",
        toneClasses[tone],
        className,
      )}
    >
      <div className="min-w-0 flex-1">
        {title ? <p className="font-semibold">{title}</p> : null}
        {children ? <div className={title ? "mt-0.5" : undefined}>{children}</div> : null}
      </div>
      {action ? <div className="shrink-0">{action}</div> : null}
      {onClose ? (
        <button
          type="button"
          onClick={onClose}
          aria-label="Fermer"
          className="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-current/70 transition hover:bg-black/5"
        >
          <svg viewBox="0 0 20 20" className="h-4 w-4" fill="none" aria-hidden="true">
            <path d="M5 5L15 15M15 5L5 15" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
          </svg>
        </button>
      ) : null}
    </div>
  );
}

export default Banner;
