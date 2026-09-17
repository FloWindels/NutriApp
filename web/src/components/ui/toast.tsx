"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { cn } from "@/lib/cn";

export type ToastTone = "neutral" | "success" | "error" | "warning";

export type ToastAction = {
  label: string;
  /** May be async; the toast closes once it settles. */
  onClick: () => void | Promise<void>;
};

export type ToastInput = {
  title: ReactNode;
  description?: ReactNode;
  tone?: ToastTone;
  /** ms before auto-dismiss; 0 keeps it until closed. Default 5 000 (8 000 with an action). */
  duration?: number;
  action?: ToastAction;
};

type ToastItem = ToastInput & { id: number };

type ToastContextValue = {
  toast: (input: ToastInput) => number;
  dismiss: (id: number) => void;
  success: (title: ReactNode, description?: ReactNode) => number;
  error: (title: ReactNode, description?: ReactNode) => number;
};

const ToastContext = createContext<ToastContextValue | null>(null);

const toneClasses: Record<ToastTone, string> = {
  neutral: "border-slate-800 bg-slate-900 text-white",
  success: "border-emerald-700 bg-emerald-700 text-white",
  error: "border-rose-700 bg-rose-700 text-white",
  warning: "border-amber-500 bg-amber-500 text-slate-950",
};

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  const counter = useRef(0);
  const timers = useRef(new Map<number, ReturnType<typeof setTimeout>>());

  const dismiss = useCallback((id: number) => {
    const timer = timers.current.get(id);
    if (timer) clearTimeout(timer);
    timers.current.delete(id);
    setItems((current) => current.filter((item) => item.id !== id));
  }, []);

  const toast = useCallback(
    (input: ToastInput) => {
      const id = ++counter.current;
      const duration = input.duration ?? (input.action ? 8000 : 5000);
      setItems((current) => [...current.slice(-3), { ...input, id }]);
      if (duration > 0) {
        timers.current.set(
          id,
          setTimeout(() => dismiss(id), duration),
        );
      }
      return id;
    },
    [dismiss],
  );

  useEffect(() => {
    const active = timers.current;
    return () => {
      active.forEach((timer) => clearTimeout(timer));
      active.clear();
    };
  }, []);

  const value = useMemo<ToastContextValue>(
    () => ({
      toast,
      dismiss,
      success: (title, description) => toast({ title, description, tone: "success" }),
      error: (title, description) => toast({ title, description, tone: "error", duration: 7000 }),
    }),
    [toast, dismiss],
  );

  return (
    <ToastContext.Provider value={value}>
      {children}
      <div
        aria-live="polite"
        aria-atomic="false"
        className="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4"
      >
        {items.map((item) => (
          <ToastCard key={item.id} item={item} onDismiss={() => dismiss(item.id)} />
        ))}
      </div>
    </ToastContext.Provider>
  );
}

function ToastCard({ item, onDismiss }: { item: ToastItem; onDismiss: () => void }) {
  const [busy, setBusy] = useState(false);
  const tone = item.tone ?? "neutral";

  async function runAction() {
    if (!item.action) return;
    setBusy(true);
    try {
      await item.action.onClick();
    } finally {
      setBusy(false);
      onDismiss();
    }
  }

  return (
    <div
      role="status"
      className={cn(
        "pointer-events-auto flex w-full max-w-md items-center gap-3 rounded-2xl border px-4 py-3 shadow-[0_18px_44px_rgba(15,23,42,0.25)]",
        toneClasses[tone],
      )}
    >
      <div className="min-w-0 flex-1">
        <p className="text-sm font-semibold leading-5">{item.title}</p>
        {item.description ? <p className="mt-0.5 text-xs leading-5 opacity-85">{item.description}</p> : null}
      </div>
      {item.action ? (
        <button
          type="button"
          onClick={runAction}
          disabled={busy}
          className="shrink-0 rounded-xl border border-white/30 bg-white/10 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide transition hover:bg-white/20 disabled:opacity-60"
        >
          {busy ? "…" : item.action.label}
        </button>
      ) : null}
      <button
        type="button"
        onClick={onDismiss}
        aria-label="Fermer la notification"
        className="grid h-8 w-8 shrink-0 place-items-center rounded-lg opacity-70 transition hover:bg-white/10 hover:opacity-100"
      >
        <svg viewBox="0 0 20 20" className="h-4 w-4" fill="none" aria-hidden="true">
          <path d="M5 5L15 15M15 5L5 15" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
        </svg>
      </button>
    </div>
  );
}

export function useToast(): ToastContextValue {
  const context = useContext(ToastContext);
  if (!context) {
    throw new Error("useToast doit être utilisé à l’intérieur de <ToastProvider>.");
  }
  return context;
}

export default ToastProvider;
