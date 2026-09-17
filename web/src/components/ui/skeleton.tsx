import { cn } from "@/lib/cn";

export function Skeleton({ className }: { className?: string }) {
  return <div aria-hidden="true" className={cn("animate-pulse rounded-xl bg-slate-200/70", className)} />;
}

/** Several text-like lines, the last one shorter. */
export function SkeletonText({ lines = 3, className }: { lines?: number; className?: string }) {
  return (
    <div className={cn("space-y-2", className)} aria-hidden="true">
      {Array.from({ length: lines }).map((_, index) => (
        <Skeleton key={index} className={cn("h-3.5", index === lines - 1 ? "w-2/3" : "w-full")} />
      ))}
    </div>
  );
}

/** Card-shaped placeholder matching `Card` dimensions. */
export function SkeletonCard({ className, lines = 3 }: { className?: string; lines?: number }) {
  return (
    <div className={cn("rounded-[1.75rem] border border-slate-200 bg-white p-5", className)} aria-hidden="true">
      <Skeleton className="mb-4 h-5 w-1/3" />
      <SkeletonText lines={lines} />
    </div>
  );
}

/** Row list placeholder. */
export function SkeletonList({ rows = 4, className }: { rows?: number; className?: string }) {
  return (
    <div className={cn("space-y-2", className)} aria-hidden="true">
      {Array.from({ length: rows }).map((_, index) => (
        <Skeleton key={index} className="h-14 w-full rounded-2xl" />
      ))}
    </div>
  );
}

export default Skeleton;
