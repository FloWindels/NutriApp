"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useMe } from "@/hooks/use-me";
import { apiPost, isApiError } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { sectionsByCategory, toneStyles } from "@/lib/dashboard-sections";
import { BRAND, messages } from "@/lib/messages";
import { clearSession, useSession } from "@/lib/session";
import MenuIcon from "./menu-icons";
import { Banner } from "./ui/banner";

type DashboardShellProps = {
  children: ReactNode;
};

const groups = sectionsByCategory();
const compactSections = groups.flatMap((group) => group.sections);

/**
 * Sidebar + content frame, mounted once by `app/dashboard/layout.tsx`.
 * Children render immediately from the cached session; `/api/auth/me` resolves in the
 * background and only shows a non-blocking banner when it fails (401 → handled by apiFetch).
 */
export default function DashboardShell({ children }: DashboardShellProps) {
  const router = useRouter();
  const pathname = usePathname();
  const queryClient = useQueryClient();
  const { token, user, ready } = useSession();
  const [menuOpen, setMenuOpen] = useState(false);
  const [bannerDismissed, setBannerDismissed] = useState(false);

  const me = useMe();

  useEffect(() => {
    if (ready && !token) router.replace("/login");
  }, [ready, token, router]);

  const logout = useMutation({
    mutationFn: async () => {
      try {
        await apiPost("/auth/logout");
      } catch {
        // Token may already be invalid: we still clear locally.
      }
    },
    onSettled: () => {
      clearSession();
      queryClient.clear();
      router.replace("/login");
    },
  });

  const sessionWarning =
    me.isError && !(isApiError(me.error) && me.error.status === 401) && !bannerDismissed;

  const displayUser = me.data ?? user;

  return (
    <main className="min-h-screen bg-[#f3faec] p-3 text-[#0b3f2f] sm:p-4 lg:p-5">
      <div
        className={cn(
          "mx-auto grid min-h-[calc(100vh-1.5rem)] max-w-[1600px] gap-3 transition-all duration-300",
          menuOpen ? "lg:grid-cols-[280px_minmax(0,1fr)]" : "lg:grid-cols-[96px_minmax(0,1fr)]",
        )}
      >
        <aside
          aria-label={`Navigation ${BRAND}`}
          className="flex items-center justify-between rounded-[1.75rem] border border-emerald-100 bg-white px-3 py-3 shadow-[0_20px_60px_rgba(15,23,42,0.08)] lg:min-h-0 lg:flex-col lg:items-stretch lg:justify-start lg:px-2 lg:py-4"
        >
          <div className="flex w-full flex-col items-center gap-3 px-1">
            <Link
              href="/dashboard"
              aria-label={`Accueil ${BRAND}`}
              className="grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-2xl border border-slate-200 bg-white"
            >
              <Image
                src="/logoMoh.png"
                alt={`Logo ${BRAND}`}
                width={36}
                height={36}
                className="h-9 w-9 object-contain"
                priority
              />
            </Link>
            <button
              type="button"
              onClick={() => setMenuOpen((prev) => !prev)}
              aria-label={menuOpen ? "Replier le menu" : "Déplier le menu"}
              aria-expanded={menuOpen}
              className={cn(
                "grid h-10 w-10 shrink-0 place-items-center rounded-xl border transition",
                menuOpen
                  ? "border-emerald-700 bg-emerald-700 text-white"
                  : "border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:bg-slate-50",
              )}
            >
              <span className="space-y-1">
                <span className="block h-0.5 w-4 bg-current" />
                <span className="block h-0.5 w-4 bg-current" />
                <span className="block h-0.5 w-4 bg-current" />
              </span>
            </button>
          </div>

          {menuOpen ? (
            <div className="mt-4 rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-3">
              <p className="truncate text-sm font-medium text-slate-900">{displayUser?.name ?? "Utilisateur"}</p>
              <p className="truncate text-xs text-slate-500">{displayUser?.email ?? ""}</p>
            </div>
          ) : null}

          <nav aria-label="Sections" className="overflow-x-auto lg:mt-4 lg:flex-1 lg:overflow-visible lg:px-1">
            {menuOpen ? (
              <div className="space-y-3">
                <Link
                  href="/dashboard"
                  aria-current={pathname === "/dashboard" ? "page" : undefined}
                  className={cn(
                    "flex h-12 w-full items-center gap-3 rounded-2xl border px-4 text-sm font-medium transition",
                    pathname === "/dashboard"
                      ? "border-emerald-700 bg-emerald-700 text-white"
                      : "border-emerald-100 bg-emerald-50 text-emerald-900 hover:border-emerald-200 hover:bg-emerald-100/70",
                  )}
                >
                  <span
                    className={cn(
                      "grid h-7 w-7 place-items-center rounded-lg",
                      pathname === "/dashboard" ? "bg-white/15 text-white" : "bg-emerald-700 text-white",
                    )}
                  >
                    <HomeIcon />
                  </span>
                  Accueil
                </Link>

                {groups.map((group) => (
                  <div key={group.category} className="space-y-2">
                    <p className="px-2 text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">
                      {group.category}
                    </p>
                    <div className="space-y-2">
                      {group.sections.map((section) => {
                        const href = `/dashboard/${section.slug}`;
                        const active = pathname === href || pathname.startsWith(`${href}/`);
                        return (
                          <Link
                            key={section.slug}
                            href={href}
                            aria-current={active ? "page" : undefined}
                            className={cn(
                              "group flex h-12 w-full items-center justify-start gap-3 rounded-2xl border px-4 transition",
                              active
                                ? "border-emerald-700 bg-emerald-700 text-white"
                                : "border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:bg-slate-50",
                            )}
                          >
                            <span
                              className={cn(
                                "grid h-7 w-7 place-items-center rounded-lg",
                                active ? "bg-white/15 text-white" : `${toneStyles[section.tone].bar} text-white`,
                              )}
                            >
                              <MenuIcon name={section.icon} className="h-4 w-4" />
                            </span>
                            <span className="truncate text-sm font-medium">{section.title}</span>
                          </Link>
                        );
                      })}
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="flex items-center gap-2 lg:flex-col lg:justify-center">
                {compactSections.map((section) => {
                  const href = `/dashboard/${section.slug}`;
                  const active = pathname === href || pathname.startsWith(`${href}/`);
                  return (
                    <Link
                      key={section.slug}
                      href={href}
                      aria-label={section.title}
                      aria-current={active ? "page" : undefined}
                      className={cn(
                        "group relative flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border transition",
                        active
                          ? "border-emerald-700 bg-emerald-700 text-white"
                          : "border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:bg-slate-50",
                      )}
                    >
                      <span
                        className={cn(
                          "grid h-7 w-7 place-items-center rounded-lg",
                          active ? "bg-white/15 text-white" : `${toneStyles[section.tone].bar} text-white`,
                        )}
                      >
                        <MenuIcon name={section.icon} className="h-4 w-4" />
                      </span>
                      <span className="pointer-events-none absolute left-full top-1/2 z-20 ml-3 -translate-x-1 -translate-y-1/2 whitespace-nowrap rounded-xl border border-slate-200 bg-slate-900 px-3 py-2 text-xs font-semibold text-white opacity-0 shadow-xl transition duration-150 group-hover:translate-x-0 group-hover:opacity-100 group-focus-visible:translate-x-0 group-focus-visible:opacity-100">
                        {section.title}
                      </span>
                    </Link>
                  );
                })}
              </div>
            )}
          </nav>

          <button
            type="button"
            onClick={() => logout.mutate()}
            disabled={logout.isPending}
            aria-label="Se déconnecter"
            className={cn(
              "mt-3 border transition disabled:opacity-60",
              menuOpen
                ? "flex h-12 w-full items-center justify-center gap-2 rounded-2xl border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100"
                : "grid h-10 w-10 place-items-center self-center rounded-xl border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:bg-slate-50",
            )}
          >
            <svg viewBox="0 0 24 24" fill="none" className="h-4 w-4" aria-hidden="true">
              <path
                d="M14 7L19 12L14 17M19 12H9M9 4H7C5.89543 4 5 4.89543 5 6V18C5 19.1046 5.89543 20 7 20H9"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
              />
            </svg>
            {menuOpen ? <span className="text-sm font-medium">{messages.logout}</span> : null}
          </button>
        </aside>

        <section className="overflow-hidden rounded-[1.75rem] border border-slate-200 bg-white shadow-[0_20px_60px_rgba(15,23,42,0.08)]">
          <div className="p-4 sm:p-6 lg:p-8">
            {sessionWarning ? (
              <Banner
                tone="warning"
                className="mb-4"
                onClose={() => setBannerDismissed(true)}
                action={
                  <button
                    type="button"
                    onClick={() => me.refetch()}
                    className="rounded-xl border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 transition hover:bg-amber-100"
                  >
                    {messages.retry}
                  </button>
                }
              >
                {messages.sessionCheckFailed}
              </Banner>
            ) : null}
            {children}
          </div>
        </section>
      </div>
    </main>
  );
}

function HomeIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" className="h-4 w-4" aria-hidden="true">
      <path
        d="M4 11L12 4L20 11V20H14V14H10V20H4V11Z"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}
