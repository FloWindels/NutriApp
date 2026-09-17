import type { ComponentPropsWithoutRef, ElementType, ReactNode } from "react";
import { cn } from "@/lib/cn";
import { toneStyles, type DashboardTone } from "@/lib/dashboard-sections";

export type CardProps<T extends ElementType = "section"> = {
  as?: T;
  /** Tinted surface using a section tone; default white. */
  tone?: DashboardTone;
  /** `none` removes the inner padding (lists, tables). */
  padding?: "none" | "sm" | "md" | "lg";
  /** Adds a hover lift for clickable cards. */
  interactive?: boolean;
  children?: ReactNode;
  className?: string;
} & Omit<ComponentPropsWithoutRef<T>, "as" | "children" | "className">;

const paddingClasses = {
  none: "",
  sm: "p-4",
  md: "p-5 sm:p-6",
  lg: "p-6 sm:p-8",
} as const;

export function Card<T extends ElementType = "section">({
  as,
  tone,
  padding = "md",
  interactive = false,
  className,
  children,
  ...props
}: CardProps<T>) {
  const Component = (as ?? "section") as ElementType;
  return (
    <Component
      className={cn(
        "rounded-[1.75rem] border shadow-[0_14px_40px_rgba(15,23,42,0.06)]",
        tone ? toneStyles[tone].card : "border-slate-200 bg-white",
        paddingClasses[padding],
        interactive && "transition hover:-translate-y-0.5 hover:shadow-[0_18px_44px_rgba(15,23,42,0.1)]",
        className,
      )}
      {...props}
    >
      {children}
    </Component>
  );
}

export function CardHeader({
  title,
  subtitle,
  actions,
  className,
}: {
  title: ReactNode;
  subtitle?: ReactNode;
  actions?: ReactNode;
  className?: string;
}) {
  return (
    <div className={cn("flex items-start justify-between gap-3", className)}>
      <div className="min-w-0">
        <h3 className="text-base font-semibold text-slate-900">{title}</h3>
        {subtitle ? <p className="mt-0.5 text-sm text-slate-500">{subtitle}</p> : null}
      </div>
      {actions ? <div className="flex shrink-0 items-center gap-2">{actions}</div> : null}
    </div>
  );
}

export default Card;
