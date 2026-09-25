import type { Metadata } from "next";
import Link from "next/link";
import { Article, LegalPage, Tableau } from "@/components/legal/legal-page";
import { OPERATEUR } from "@/lib/legal/operateur";
import { VERSION_CONFIDENTIALITE } from "@/lib/legal/versions";

export const metadata: Metadata = {
  title: "Stockage local et cookies — Mavi’oh",
  description:
    "Ce que Mavi’oh conserve sur ton appareil : uniquement le strict nécessaire, aucun traceur.",
};

export default function CookiesPage() {
  const { service } = OPERATEUR;

  return (
    <LegalPage
      titre="Stockage local et cookies"
      version={VERSION_CONFIDENTIALITE}
      resume={
        <>
          <strong>L’essentiel.</strong> {service} ne dépose <strong>aucun cookie publicitaire</strong>,
          n’utilise <strong>aucun outil de mesure d’audience</strong> et ne partage rien avec des
          tiers à des fins de suivi. Seules deux valeurs sont conservées sur ton appareil, et
          elles sont indispensables pour te garder connecté. C’est pourquoi aucune bannière de
          consentement ne t’est imposée.
        </>
      }
    >
      <Article numero="1" titre="Ce qui est conservé sur ton appareil">
        <Tableau
          entetes={["Nom", "Rôle", "Durée"]}
          lignes={[
            [
              <code key="t">token</code>,
              "Jeton d’authentification. Sans lui, il faudrait saisir son mot de passe à chaque page.",
              "Jusqu’à la déconnexion. Le jeton expire côté serveur au bout de trente jours.",
            ],
            [
              <code key="u">user</code>,
              "Ton nom et ton adresse e-mail, afin d’afficher ton identité sans attendre une réponse du serveur.",
              "Jusqu’à la déconnexion.",
            ],
          ]}
        />
        <p>
          Ces deux valeurs sont stockées dans l’espace <em>localStorage</em> de ton navigateur, et
          non dans des cookies : elles ne sont donc jamais transmises automatiquement à un serveur
          tiers. Elles sont effacées dès que tu te déconnectes.
        </p>
      </Article>

      <Article numero="2" titre="Pourquoi aucun consentement n’est demandé">
        <p>
          La loi impose de recueillir le consentement avant de lire ou d’écrire sur l’appareil
          d’un utilisateur, sauf lorsque cet accès est <strong>strictement nécessaire</strong> à
          la fourniture d’un service expressément demandé. Garder une session ouverte relève
          précisément de cette exception. Aucune autre information n’étant déposée, une bannière
          de consentement serait sans objet — et une bannière qui ne sert à rien est une gêne
          inutile.
        </p>
      </Article>

      <Article numero="3" titre={`Ce que ${service} ne fait pas`}>
        <ul className="list-disc space-y-1.5 pl-5">
          <li>Aucun cookie publicitaire, aucun reciblage, aucun profilage marketing.</li>
          <li>
            Aucun outil de mesure d’audience, même « anonyme » : ni Google Analytics, ni
            équivalent.
          </li>
          <li>Aucun bouton de réseau social susceptible de te pister.</li>
          <li>
            Aucune requête vers un serveur tiers au moment de l’affichage : les polices de
            caractères sont téléchargées à la construction du site et servies depuis notre propre
            serveur, si bien que ton navigateur ne contacte jamais Google.
          </li>
          <li>Aucune revente, aucun partage de données à des fins commerciales.</li>
        </ul>
      </Article>

      <Article numero="4" titre="Effacer ces données">
        <p>
          Te déconnecter suffit à les supprimer. Tu peux aussi vider le stockage du site depuis
          les réglages de ton navigateur : tu seras alors simplement invité à te reconnecter.
        </p>
      </Article>

      <Article numero="5" titre="Et sur l’application mobile">
        <p>
          L’application mobile conserve le jeton d’authentification dans le coffre sécurisé du
          système (Keychain sur iOS, Keystore sur Android). Elle ne contient ni traceur, ni régie
          publicitaire.
        </p>
      </Article>

      <Article numero="6" titre="Pour aller plus loin">
        <p>
          Le traitement de tes données personnelles est décrit dans la{" "}
          <Link href="/confidentialite" className="text-emerald-800 hover:underline">
            politique de confidentialité
          </Link>
          .
        </p>
      </Article>
    </LegalPage>
  );
}
