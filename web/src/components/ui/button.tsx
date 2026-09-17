import type { ComponentPropsWithRef, ReactNode } from "react";
import { cn } from "@/lib/cn";

export type ButtonVariant = "primary" | "secondary" | "danger" | "ghost";
export type ButtonSize = "sm" | "md" | "lg";

export type ButtonProps = ComponentPropsWithRef<"button"> & {
  variant?: ButtonVariant;
  size?: ButtonSize;
  loading?: boolean;
  /** Icon or element rendered before the label. */
  leading?: ReactNode;
  /** Stretch to the container width. */
  block?: boolean;
};

const variantClasses: Record<ButtonVariant, string> = {
  primary:
    "border-emerald-700 bg-emerald-700 text-white hover:bg-emerald-800 hover:border-emerald-800 focus-visible:ring-emerald-600/30",
  secondary:
    "border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50 focus-visible:ring-slate-400/30",
  danger:
    "border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100 focus-visible:ring-rose-500/30",
  ghost:
    "border-transparent bg-transparent text-slate-600 hover:bg-slate-100 focus-visible:ring-slate-400/30",
};

const sizeClasses: Record<ButtonSize, string> = {
  sm: "h-9 px-3 text-sm rounded-xl gap-1.5",
  md: "h-11 px-4 text-sm rounded-2xl gap-2",
  lg: "h-12 px-5 text-base rounded-2xl gap-2",
};

export function Button({
  variant = "primary",
  size = "md",
  loading = false,
  leading,
  block = false,
  className,
  children,
  disabled,
  type = "button",
  ...props
}: ButtonProps) {
  return (
    <button
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={cn(
        "inline-flex items-center justify-center border font-medium transition focus-visible:outline-none focus-visible:ring-4 disabled:cursor-not-allowed disabled:opacity-60",
        variantClasses[variant],
        sizeClasses[size],
        block && "w-full",
        className,
      )}
      {...props}
    >
      {loading ? (
        <span
          aria-hidden="true"
          className="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"
        />
      ) : (
        leading
      )}
      {children}
    </button>
  );
}

export default Button;
