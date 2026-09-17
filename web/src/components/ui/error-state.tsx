import type { ReactNode } from "react";
import { cn } from "@/lib/cn";
import { messages } from "@/lib/messages";
import { Button } from "./button";

export type ErrorStateProps = {
  title?: ReactNode;
  message?: ReactNode;
  onRetry?: () => void;
  retryLabel?: string;
  retrying?: boolean;
  compact?: boolean;
  className?: string;
};

export function ErrorState({
  title = "Oups, quelque chose s’est mal passé.",
  message = messages.server,
  onRetry,
  retryLabel = messages.retry,
  retrying = false,
  compact = false,
  className,
}: ErrorStateProps) {
  return (
    <div
      role="alert"
      className={cn(
        "flex flex-col items-center justify-center rounded-2xl border border-rose-200 bg-rose-50/70 text-center",
        compact ? "px-4 py-6" : "px-6 py-12",
        className,
      )}
    >
      <div className="mb-3 grid h-12 w-12 place-items-center rounded-2xl bg-white text-rose-600 shadow-sm">
        <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" aria-hidden="true">
          <path d="M12 8V13M12 16.5V16.6" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
          <path d="M10.3 3.9L2.6 17.5C1.9 18.8 2.8 20.5 4.3 20.5H19.7C21.2 20.5 22.1 18.8 21.4 17.5L13.7 3.9C12.9 2.6 11.1 2.6 10.3 3.9Z" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round" />
        </svg>
      </div>
      <p className="text-base font-semibold text-rose-900">{title}</p>
      {message ? <p className="mt-1 max-w-md text-sm leading-6 text-rose-800/80">{message}</p> : null}
      {onRetry ? (
        <Button variant="secondary" className="mt-4" onClick={onRetry} loading={retrying}>
          {retryLabel}
        </Button>
      ) : null}
    </div>
  );
}

export default ErrorState;
