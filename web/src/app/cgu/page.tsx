import type { Metadata } from "next";
import Link from "next/link";
import { Article, LegalPage, Valeur } from "@/components/legal/legal-page";
import { OPERATEUR, estLocal } from "@/lib/legal/operateur";
import { VERSION_CGU } from "@/lib/legal/versions";

export const metadata: Metadata = {
  title: "Conditions générales d’utilisation — Mavi’oh",
  description: "Les règles d’utilisation du service Mavi’oh.",
};

export default function CguPage() {
  const { service, editeur, contact, autorite } = OPERATEUR;

  return (
    <LegalPage
      titre="Conditions générales d’utilisation"
      version={VERSION_CGU}
      resume={
        <>
          <strong>L’essentiel.</strong> {service} t’aide à suivre ton alimentation et ton sport.
          Ce n’est pas un service médical : ses estimations ne remplacent pas un professionnel de
          santé. Le service est gratuit, ton compte est personnel, et tu peux partir quand tu
          veux en emportant tes données.
        </>
      }
    >
      <Article numero="1" titre="Objet">
        <p>
          Les présentes conditions régissent l’utilisation de {service}, service de suivi
          nutritionnel et sportif édité par <Valeur>{editeur.nom}</Valeur>. Créer un compte vaut
          acceptation pleine et entière de ces conditions et de la{" "}
          <Link href="/confidentialite" className="text-emerald-800 hover:underline">
            politique de confidentialité
          </Link>
          .
        </p>
      </Article>

      <Article numero="2" titre="Ce que fait le service, et ce qu’il ne fait pas">
        <p>{service} permet de tenir un journal alimentaire, de gérer un stock d’aliments et une
          liste de courses, de planifier ses repas, d’enregistrer ses séances de sport et de
          recevoir des suggestions calculées à partir des informations fournies.
        </p>
        <p className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-950">
          <strong>Avertissement important.</strong> {service} n’est pas un dispositif médical. Les
          besoins caloriques, les répartitions de macronutriments, les dépenses énergétiques et
          les séances proposées sont des <strong>estimations</strong> issues de formules publiques
          et de ce que tu déclares. Elles ne constituent ni un diagnostic, ni un traitement, ni un
          avis médical ou diététique. Consulte un professionnel de santé avant d’entreprendre un
          régime, en cas de pathologie, de grossesse, d’allaitement, de trouble du comportement
          alimentaire, ou avant de reprendre une activité physique intense. Interromps tout
          exercice en cas de douleur ou de malaise.
        </p>
      </Article>

      <Article numero="3" titre="Accès et compte">
        <p>
          Le service est accessible à partir de 12 ans. En dessous de 15 ans, l’accord d’un
          titulaire de l’autorité parentale est requis et doit être déclaré dans le profil.
        </p>
        <p>
          Ton compte est personnel. Tu es responsable de la confidentialité de ton mot de passe et
          des actions effectuées depuis ton compte. Préviens-nous sans délai si tu soupçonnes un
          accès non autorisé.
        </p>
        <p>
          Les informations que tu renseignes doivent être exactes : les calculs du service en
          dépendent directement. Un poids ou un âge erroné produit des objectifs erronés.
        </p>
      </Article>

      <Article numero="4" titre="Usages interdits">
        <ul className="list-disc space-y-1.5 pl-5">
          <li>Créer un compte au nom d’une autre personne sans son accord.</li>
          <li>
            Tenter d’accéder aux données d’un autre utilisateur, contourner les limitations
            techniques, ou perturber le fonctionnement du service.
          </li>
          <li>
            Publier dans les champs libres, les noms d’aliments ou de recettes un contenu
            illicite, injurieux, ou portant atteinte aux droits d’autrui.
          </li>
          <li>
            Extraire massivement le contenu du service par des moyens automatisés, ou le
            réutiliser à des fins commerciales sans autorisation.
          </li>
          <li>
            Utiliser le service pour promouvoir des pratiques dangereuses pour la santé.
          </li>
        </ul>
      </Article>

      <Article numero="5" titre="Contenus que tu publies">
        <p>
          Tu restes propriétaire des contenus que tu déposes — recettes, photos, notes. En rendant
          une recette ou un aliment public, tu accordes à {service} le droit non exclusif et
          gratuit de l’afficher aux autres utilisateurs, pour la durée du service. Tu garantis
          détenir les droits nécessaires sur ce que tu publies, en particulier sur les photos.
        </p>
        <p>
          Un contenu manifestement illicite peut être retiré, et le compte concerné suspendu.
        </p>
      </Article>

      <Article numero="6" titre="Intelligence artificielle">
        <p>
          Certaines fonctions peuvent s’appuyer sur un modèle d’intelligence artificielle :
          proposition de séances, lecture d’une photo d’assiette. Ces fonctions sont facultatives
          et désactivables. Leurs résultats sont des <strong>propositions à vérifier</strong> :
          une reconnaissance de photo doit toujours être relue et corrigée avant enregistrement,
          et une séance proposée reste sous ta responsabilité. Le détail des données transmises
          figure dans la politique de confidentialité.
        </p>
      </Article>

      <Article numero="7" titre="Disponibilité">
        {estLocal() ? (
          <p className="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sky-950">
            Le service tourne aujourd’hui sur une installation privée, accessible depuis le seul
            réseau local. Il n’est donc joignable ni à distance, ni lorsque la machine est
            éteinte, et aucune disponibilité n’est promise.
          </p>
        ) : null}
        <p>
          Le service est fourni gratuitement, en l’état, sans garantie de disponibilité
          ininterrompue. Des interruptions peuvent survenir pour maintenance, mise à jour, panne
          ou cause extérieure. L’éditeur s’efforce de les limiter et d’assurer des sauvegardes
          régulières, sans pouvoir garantir l’absence totale de perte de données : exporte tes
          données si elles te sont précieuses.
        </p>
      </Article>

      <Article numero="8" titre="Responsabilité">
        <p>
          L’éditeur ne peut être tenu responsable des conséquences de décisions alimentaires ou
          sportives prises sur la seule base des estimations du service, ni de l’inexactitude des
          informations que tu renseignes toi-même, ni de celles provenant de bases externes comme
          Open Food Facts, alimentées par des contributeurs indépendants.
        </p>
        <p>
          Rien dans ces conditions n’écarte la responsabilité de l’éditeur en cas de faute lourde,
          de dol, ou d’atteinte à la vie ou à l’intégrité physique. Si tu agis en qualité de
          consommateur, tes droits impératifs restent entiers.
        </p>
      </Article>

      <Article numero="9" titre="Fin de la relation">
        <p>
          Tu peux fermer ton compte à tout moment depuis tes paramètres, après confirmation par
          mot de passe. L’effet est immédiat et les conséquences sont décrites dans la politique
          de confidentialité.
        </p>
        <p>
          L’éditeur peut suspendre ou fermer un compte en cas de manquement grave aux présentes
          conditions, après information de la personne concernée sauf urgence ou obligation
          légale, et peut mettre fin au service moyennant un préavis raisonnable permettant
          d’exporter ses données.
        </p>
      </Article>

      <Article numero="10" titre="Modification des conditions">
        <p>
          Ces conditions portent un numéro de version. Toute modification de fond change cette
          version ; tu en es informé lors de ta prochaine connexion et la poursuite de
          l’utilisation vaut acceptation. La date et la version acceptées sont conservées pour
          chaque compte, et figurent dans ton export de données.
        </p>
      </Article>

      <Article numero="11" titre="Droit applicable et litiges">
        <p>
          Les présentes conditions sont régies par le droit belge. En cas de différend, une
          solution amiable sera recherchée en priorité : écris à <Valeur>{contact.email}</Valeur>.
        </p>
        <p>
          À défaut d’accord, le litige relève des cours et tribunaux compétents. Si tu agis en
          qualité de consommateur, tu peux saisir le Service de médiation pour le consommateur
          (Boulevard du Roi Albert II 8, 1000 Bruxelles,{" "}
          <a
            href="https://mediationconsommateur.be"
            className="text-emerald-800 hover:underline"
            rel="noreferrer"
          >
            mediationconsommateur.be
          </a>
          ) ou la plateforme européenne de règlement en ligne des litiges. Pour une question
          relative à tes données personnelles, l’autorité compétente est {autorite.nom}.
        </p>
      </Article>

      <Article numero="12" titre="Langue">
        <p>
          Ces conditions sont rédigées en français, seule version faisant foi.
        </p>
      </Article>
    </LegalPage>
  );
}
