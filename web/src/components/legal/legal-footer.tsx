import Link from "next/link";
import { DOCUMENTS } from "@/components/legal/legal-page";

/**
 * Pied de page légal.
 *
 * Les mentions légales et la politique de confidentialité doivent être accessibles en
 * permanence, y compris avant toute création de compte : ce pied de page figure donc sur les
 * écrans publics comme dans l'application.
 */
export function LegalFooter({ className }: { className?: string }) {
  return (
    <footer className={className}>
      <ul className="flex flex-wrap items-center justify-center gap-x-4 gap-y-1.5 text-xs text-slate-500">
        {DOCUMENTS.map((doc) => (
          <li key={doc.href}>
            <Link href={doc.href} className="hover:text-slate-700 hover:underline">
              {doc.titre}
            </Link>
          </li>
        ))}
      </ul>
    </footer>
  );
}
