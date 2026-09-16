"use client";

import Image from "next/image";
import Link from "next/link";
import { useEffect, useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { setAuthToken } from "../lib/api";
import { dashboardSections } from "../lib/dashboard-sections";
import MenuIcon from "./menu-icons";

type User = {
  id: number;
  name: string;
  email: string;
};

type DashboardShellProps = {
  children: React.ReactNode;
};

export default function DashboardShell({ children }: DashboardShellProps) {
  const router = useRouter();
  const pathname = usePathname();
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);
  const [menuOpen, setMenuOpen] = useState(false);

  const categoryOrder = ["Nutrition", "Planification", "Compte"] as const;
  const sectionsByCategory = categoryOrder.map((category) => ({
    category,
    sections: dashboardSections.filter((section) => section.category === category),
  }));
  const orderedCompactSections = sectionsByCategory.flatMap((group) => group.sections);

  function getToneColor(tone: (typeof dashboardSections)[number]["tone"]) {
    if (tone === "emerald") return "bg-emerald-500";
    if (tone === "sky") return "bg-sky-500";
    if (tone === "amber") return "bg-amber-500";
    if (tone === "rose") return "bg-rose-500";
    if (tone === "violet") return "bg-violet-500";
    if (tone === "cyan") return "bg-cyan-500";
    if (tone === "lime") return "bg-lime-500";
    if (tone === "indigo") return "bg-indigo-500";
    if (tone === "teal") return "bg-teal-500";
    if (tone === "orange") return "bg-orange-500";
    if (tone === "slate") return "bg-slate-500";
    return "bg-fuchsia-500";
  }

  function handleLogout() {
    localStorage.removeItem("token");
    localStorage.removeItem("user");
    setAuthToken(null);
    router.push("/login");
  }

  useEffect(() => {
    const token = localStorage.getItem("token");
    const cachedUserRaw = localStorage.getItem("user");
    const lastSessionCheckRaw = localStorage.getItem("session_last_check_ts");
    let hasCachedUser = false;

    if (cachedUserRaw) {
      try {
        const parsedUser = JSON.parse(cachedUserRaw) as User;

        if (parsedUser?.id && parsedUser?.email) {
          setUser(parsedUser);
          hasCachedUser = true;
          setLoading(false);
        }
      } catch {
        localStorage.removeItem("user");
      }
    }

    if (!token) {
      router.replace("/login");
      return;
    }

    // If the user is already cached and session was recently checked,
    // skip a new network check to keep navigation instant.
    if (hasCachedUser && lastSessionCheckRaw) {
      const lastCheck = Number(lastSessionCheckRaw);
      if (!Number.isNaN(lastCheck) && Date.now() - lastCheck < 120000) {
        return;
      }
    }

    setAuthToken(token);

    let isMounted = true;

    async function loadSession() {
      try {
        const response = await fetch("/api/auth/me", {
          method: "GET",
          headers: {
            Accept: "application/json",
            Authorization: `Bearer ${token}`,
          },
        });

        if (!response.ok) {
          throw new Error("Session invalide");
        }

        const data = (await response.json()) as User;

        if (isMounted) {
          setUser(data);
          localStorage.setItem("user", JSON.stringify(data));
          localStorage.setItem("session_last_check_ts", String(Date.now()));
        }
      } catch {
        // Keep cached session UI if available and only force logout
        // when there is no usable local session at all.
        if (!hasCachedUser) {
          localStorage.removeItem("token");
          localStorage.removeItem("user");
          localStorage.removeItem("session_last_check_ts");
          setAuthToken(null);
          router.replace("/login");
        }
      } finally {
        if (isMounted && !hasCachedUser) {
          setLoading(false);
        }
      }
    }

    loadSession();

    return () => {
      isMounted = false;
    };
  }, [router]);

  if (loading) {
    return (
      <main className="min-h-screen bg-[#f3faec] px-4 py-4 text-[#0b3f2f] sm:px-6 lg:px-8">
        <div className="mx-auto flex min-h-[calc(100vh-2rem)] max-w-[1600px] items-center justify-center rounded-[2rem] border border-slate-200 bg-white shadow-[0_25px_80px_rgba(15,23,42,0.08)]">
          <div className="h-4 w-4 animate-pulse rounded-full bg-slate-900/30" />
        </div>
      </main>
    );
  }

  return (
    <main className="min-h-screen bg-[#f3faec] p-3 text-[#0b3f2f] sm:p-4 lg:p-5">
      <div
        className={`mx-auto grid min-h-[calc(100vh-1.5rem)] max-w-[1600px] gap-3 transition-all duration-300 ${
          menuOpen ? "lg:grid-cols-[280px_minmax(0,1fr)]" : "lg:grid-cols-[96px_minmax(0,1fr)]"
        }`}
      >
        <aside className="flex items-center justify-between rounded-[1.75rem] border border-emerald-100 bg-white px-3 py-3 shadow-[0_20px_60px_rgba(15,23,42,0.08)] lg:min-h-0 lg:flex-col lg:items-stretch lg:justify-start lg:px-2 lg:py-4">
          <div className="flex w-full flex-col items-center gap-3 px-1">
            <Link
              href="/dashboard"
              aria-label="Accueil suit mavioh"
              className="grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-2xl border border-slate-200 bg-white"
            >
              <Image
                src="/logoMoh.png"
                alt="Logo suit mavioh"
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
              className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl border transition ${
                menuOpen
                  ? "border-emerald-700 bg-emerald-700 text-white"
                  : "border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:bg-slate-50"
              }`}
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
              <p className="truncate text-sm font-medium text-slate-900">{user?.name ?? "Utilisateur"}</p>
              <p className="truncate text-xs text-slate-500">{user?.email ?? ""}</p>
            </div>
          ) : null}

          <nav className="overflow-x-auto lg:mt-4 lg:flex-1 lg:overflow-visible lg:px-1">
            {menuOpen ? (
              <div className="space-y-3">
                <Link
                  href="/dashboard"
                  className="flex h-12 w-full items-center gap-3 rounded-2xl border border-emerald-100 bg-emerald-50 px-4 text-sm font-medium text-emerald-900 transition hover:border-emerald-200 hover:bg-emerald-100/70"
                >
                  <span className="grid h-7 w-7 place-items-center rounded-lg bg-emerald-700 text-white">
                    <svg viewBox="0 0 24 24" fill="none" className="h-4 w-4" aria-hidden="true">
                      <path d="M4 11L12 4L20 11V20H14V14H10V20H4V11Z" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                  </span>
                  Accueil
                </Link>

                {sectionsByCategory.map((group) => (
                  <div key={group.category} className="space-y-2">
                    <p className="px-2 text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{group.category}</p>
                    <div className="space-y-2">
                      {group.sections.map((section) => {
                        const active = pathname === `/dashboard/${section.slug}`;
                        const toneDot = getToneColor(section.tone);

                        return (
                          <Link
                            key={section.slug}
                            href={`/dashboard/${section.slug}`}
                            aria-label={section.title}
                            className={`group flex h-12 w-full items-center justify-start gap-3 rounded-2xl border px-4 transition ${
                              active
                                ? "border-emerald-700 bg-emerald-700 text-white"
                                : "border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:bg-slate-50"
                            }`}
                          >
                            <span
                              className={`grid h-7 w-7 place-items-center rounded-lg ${
                                active ? "bg-white/15 text-white" : `${toneDot} text-white`
                              }`}
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
                {orderedCompactSections.map((section) => {
                  const active = pathname === `/dashboard/${section.slug}`;
                  const toneDot = getToneColor(section.tone);

                  return (
                    <Link
                      key={section.slug}
                      href={`/dashboard/${section.slug}`}
                      aria-label={section.title}
                      className={`group relative flex h-12 w-12 items-center justify-center rounded-2xl border transition ${
                        active
                          ? "border-emerald-700 bg-emerald-700 text-white"
                          : "border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:bg-slate-50"
                      }`}
                    >
                      <span
                        className={`grid h-7 w-7 place-items-center rounded-lg ${
                          active ? "bg-white/15 text-white" : `${toneDot} text-white`
                        }`}
                      >
                        <MenuIcon name={section.icon} className="h-4 w-4" />
                      </span>
                      <span className="pointer-events-none absolute left-full top-1/2 z-20 ml-3 -translate-x-1 -translate-y-1/2 whitespace-nowrap rounded-xl border border-slate-200 bg-slate-900 px-3 py-2 text-xs font-semibold text-white opacity-0 shadow-xl transition duration-150 group-hover:translate-x-0 group-hover:opacity-100">
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
            onClick={handleLogout}
            aria-label="Se déconnecter"
            className={`mt-3 border transition ${
              menuOpen
                ? "flex h-12 w-full items-center justify-center gap-2 rounded-2xl border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100"
                : "grid h-10 w-10 place-items-center self-center rounded-xl border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:bg-slate-50"
            }`}
          >
            <svg
              viewBox="0 0 24 24"
              fill="none"
              xmlns="http://www.w3.org/2000/svg"
              className="h-4 w-4"
              aria-hidden="true"
            >
              <path
                d="M14 7L19 12L14 17M19 12H9M9 4H7C5.89543 4 5 4.89543 5 6V18C5 19.1046 5.89543 20 7 20H9"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
              />
            </svg>
            {menuOpen ? <span className="text-sm font-medium">Deconnexion</span> : null}
          </button>
        </aside>

        <section className="overflow-hidden rounded-[1.75rem] border border-slate-200 bg-white shadow-[0_20px_60px_rgba(15,23,42,0.08)]">
          <div className="p-4 sm:p-6 lg:p-8">{children}</div>
        </section>
      </div>
    </main>
  );
}
