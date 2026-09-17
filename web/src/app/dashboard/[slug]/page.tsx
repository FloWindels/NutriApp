import Link from "next/link";
import { notFound } from "next/navigation";
import MenuIcon from "@/components/menu-icons";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { SectionHeader } from "@/components/ui/section-header";
import { getSectionBySlug, toneStyles } from "@/lib/dashboard-sections";
import { messages } from "@/lib/messages";

type PageProps = {
  params: Promise<{ slug: string }>;
};

export async function generateMetadata({ params }: PageProps) {
  const { slug } = await params;
  const section = getSectionBySlug(slug);
  return { title: section?.title ?? "Introuvable" };
}

/**
 * Placeholder for sections whose real page is not shipped yet.
 * Real sections live as static routes (`app/dashboard/<slug>/page.tsx`) and take precedence.
 * Unknown slugs → 404.
 */
export default async function DashboardSectionPlaceholder({ params }: PageProps) {
  const { slug } = await params;
  const section = getSectionBySlug(slug);

  if (!section) {
    notFound();
  }

  const style = toneStyles[section.tone];

  return (
    <div className="space-y-6">
      <SectionHeader level="page" eyebrow={section.category} tone={section.tone} title={section.title} />

      <Card tone={section.tone} className="grid place-items-center py-14 text-center">
        <div className={`h-2 w-20 rounded-full ${style.bar}`} />
        <div className="mt-6 grid h-20 w-20 place-items-center rounded-[1.5rem] bg-white text-slate-900 shadow-sm">
          <MenuIcon name={section.icon} className="h-9 w-9" strokeWidth={1.7} />
        </div>
        <h2 className={`mt-6 text-2xl font-semibold tracking-tight ${style.subtle}`}>{messages.comingSoon}</h2>
        <p className={`mt-2 max-w-md text-sm leading-6 ${style.subtle} opacity-80`}>{messages.comingSoonBody}</p>
        <Link href="/dashboard" className="mt-6">
          <Button variant="secondary">Retour à l’accueil</Button>
        </Link>
      </Card>
    </div>
  );
}
