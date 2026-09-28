import type { Metadata } from "next";
import Link from "next/link";
import { Article, LegalPage, Valeur } from "@/components/legal/legal-page";
import { OPERATEUR, estLocal } from "@/lib/legal/operateur";
import { VERSION_MENTIONS } from "@/lib/legal/versions";

export const metadata: Metadata = {
  title: "Mentions légales — Mavi’oh",
  description: "Identité de l’éditeur, hébergement et contact du service Mavi’oh.",
};

export default function MentionsLegalesPage() {
  const { editeur, contact, hebergeur, autorite, service } = OPERATEUR;

  return (
    <LegalPage
      titre="Mentions légales"
      version={VERSION_MENTIONS}
      resume={
        <>
          Ces mentions identifient qui édite {service} et qui héberge le service, comme l’exige
          le livre XII du Code de droit économique belge pour tout service de la société de
          l’information.
        </>
      }
    >
      <Article numero="1" titre="Éditeur du service">
        <ul className="space-y-1">
          <li>
            <strong>Dénomination :</strong> <Valeur>{editeur.nom}</Valeur>
          </li>
          <li>
            <strong>Forme :</strong> <Valeur>{editeur.forme}</Valeur>
          </li>
          <li>
            <strong>Adresse :</strong> <Valeur>{editeur.adresse}</Valeur>
          </li>
          <li>
            <strong>Numéro d’entreprise (BCE) :</strong> <Valeur>{editeur.numeroEntreprise}</Valeur>
          </li>
          <li>
            <strong>Numéro de TVA :</strong> <Valeur>{editeur.tva}</Valeur>
          </li>
          <li>
            <strong>Directeur de la publication :</strong>{" "}
            <Valeur>{editeur.directeurPublication}</Valeur>
          </li>
        </ul>
      </Article>

      <Article numero="2" titre="Nous contacter">
        <ul className="space-y-1">
          <li>
            <strong>Courriel :</strong> <Valeur>{contact.email}</Valeur>
          </li>
          <li>
            <strong>Questions relatives aux données personnelles :</strong>{" "}
            <Valeur>{contact.emailDonnees}</Valeur>
          </li>
          <li>
            <strong>Téléphone :</strong> <Valeur>{contact.telephone}</Valeur>
          </li>
        </ul>
        <p>
          Toute demande reçoit une réponse dans un délai raisonnable. Les demandes relatives aux
          données personnelles sont traitées dans le délai d’un mois prévu par le RGPD.
        </p>
      </Article>

      <Article numero="3" titre="Hébergement">
        <ul className="space-y-1">
          <li>
            <strong>Hébergeur :</strong> <Valeur>{hebergeur.nom}</Valeur>
          </li>
          <li>
            <strong>Adresse :</strong> <Valeur>{hebergeur.adresse}</Valeur>
          </li>
          <li>
            <strong>Pays d’hébergement des données :</strong> {hebergeur.pays}
          </li>
        </ul>
      </Article>

      <Article numero="4" titre="Propriété intellectuelle">
        <p>
          L’interface, les textes, les illustrations et le code de {service} sont protégés par le
          droit d’auteur. Toute reproduction ou réutilisation, totale ou partielle, sans
          autorisation écrite préalable est interdite, à l’exception des usages autorisés par la
          loi.
        </p>
        <p>
          Les données nutritionnelles issues d’Open Food Facts sont mises à disposition par leurs
          contributeurs sous licence Open Database License (ODbL). Leur réutilisation reste
          soumise aux conditions de cette licence.
        </p>
      </Article>

      {estLocal() ? (
        <Article numero="4 bis" titre="Service non offert au public">
          <p>
            {service} fonctionne actuellement sur une installation privée, accessible uniquement
            depuis un réseau local. Il n’est proposé à personne d’autre que son éditeur et les
            personnes qu’il invite directement. Les obligations d’identification propres aux
            services de la société de l’information prendront leur pleine portée le jour où le
            service sera rendu accessible depuis Internet.
          </p>
        </Article>
      ) : null}

      <Article numero="5" titre="Nature du service">
        <p>
          {service} est un outil de suivi nutritionnel et sportif. Il fournit des estimations
          calculées à partir de formules publiques et des informations que la personne renseigne
          elle-même. <strong>Il ne constitue ni un dispositif médical, ni un diagnostic, ni un
          avis médical</strong>, et ne remplace pas la consultation d’un professionnel de santé.
        </p>
      </Article>

      <Article numero="6" titre="Réclamation">
        <p>
          Pour toute question relative au traitement des données personnelles, consulte d’abord
          la <Link href="/confidentialite" className="text-emerald-800 hover:underline">politique
          de confidentialité</Link>. Une réclamation peut être adressée à l’autorité de contrôle
          compétente :
        </p>
        <p>
          <strong>{autorite.nom}</strong>
          <br />
          {autorite.adresse}
          <br />
          <a href={autorite.site} className="text-emerald-800 hover:underline" rel="noreferrer">
            {autorite.site}
          </a>
        </p>
      </Article>
    </LegalPage>
  );
}
