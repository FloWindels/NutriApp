import type { Metadata } from "next";
import Link from "next/link";
import { Article, LegalPage, Tableau, Valeur } from "@/components/legal/legal-page";
import { OPERATEUR } from "@/lib/legal/operateur";
import { VERSION_CONFIDENTIALITE } from "@/lib/legal/versions";

export const metadata: Metadata = {
  title: "Politique de confidentialité — Mavi’oh",
  description:
    "Quelles données Mavi’oh traite, pourquoi, sur quelle base légale, combien de temps, et comment exercer ses droits.",
};

export default function ConfidentialitePage() {
  const { service, editeur, contact, dpo, hebergeur, autorite, ageConsentementNumerique } = OPERATEUR;

  return (
    <LegalPage
      titre="Politique de confidentialité"
      version={VERSION_CONFIDENTIALITE}
      resume={
        <>
          <strong>L’essentiel.</strong> {service} traite des données de santé : ton âge, ton
          sexe, ta taille, ton poids, ce que tu manges et ce que tu fais comme sport. Ces données
          ne servent qu’à te fournir le service. Elles ne sont ni vendues, ni louées, ni utilisées
          à des fins publicitaires. Tu peux les exporter ou supprimer ton compte à tout moment
          depuis tes paramètres, et l’intelligence artificielle est facultative.
        </>
      }
    >
      <Article numero="1" titre="Qui est responsable du traitement">
        <p>
          Le responsable du traitement est <Valeur>{editeur.nom}</Valeur>,{" "}
          <Valeur>{editeur.adresse}</Valeur>. C’est lui qui détermine pourquoi et comment tes
          données sont traitées.
        </p>
        <p>
          Contact pour toute question relative à tes données :{" "}
          <Valeur>{contact.emailDonnees}</Valeur>.
        </p>
        {dpo ? (
          <p>
            Un délégué à la protection des données a été désigné : {dpo.nom}, {dpo.email}.
          </p>
        ) : (
          <p>
            Aucun délégué à la protection des données n’a été désigné : le service n’entre pas
            dans les cas de désignation obligatoire prévus à l’article 37 du RGPD. Les demandes
            sont traitées directement par le responsable du traitement.
          </p>
        )}
      </Article>

      <Article numero="2" titre="Quelles données sont traitées">
        <Tableau
          entetes={["Catégorie", "Données concernées"]}
          lignes={[
            [
              "Compte",
              "Nom d’usage, adresse e-mail, mot de passe (jamais stocké en clair : seule une empreinte cryptographique est conservée), date de création.",
            ],
            [
              <>
                <strong>Données de santé</strong> (catégorie particulière, art. 9 RGPD)
              </>,
              "Âge, sexe, taille, poids et historique de pesées, objectif de poids, niveau d’activité, régime alimentaire, allergènes et aliments exclus, situation particulière déclarée (grossesse, allaitement, suivi médical), zones du corps douloureuses ou à éviter, niveau et objectif sportif.",
            ],
            [
              "Usage du service",
              "Repas enregistrés et leurs aliments, recettes, contenu du stock alimentaire, liste de courses, planification de la semaine, séances de sport prévues et réalisées, durée, effort ressenti, calories estimées.",
            ],
            [
              "Contenus que tu fournis",
              "Photos de recettes et photos d’assiette, notes libres, noms d’aliments créés.",
            ],
            [
              "Foyer",
              "Si tu rejoins un foyer : appartenance au foyer et partage du stock, de la liste de courses et des repas communs avec ses membres.",
            ],
            [
              "Technique",
              "Jeton d’authentification, journaux du serveur (adresse IP, date, page appelée, code de réponse), conservés à des fins de sécurité et de diagnostic.",
            ],
          ]}
        />
        <p>
          Aucune donnée n’est collectée à ton insu : tout ce qui figure ci-dessus provient de ce
          que tu saisis, ou du fonctionnement technique nécessaire au service.
        </p>
      </Article>

      <Article numero="3" titre="Pourquoi, et sur quelle base légale">
        <Tableau
          entetes={["Finalité", "Base légale"]}
          lignes={[
            [
              "Créer et gérer ton compte, t’authentifier, te permettre d’utiliser le service.",
              "Exécution du contrat (art. 6.1.b).",
            ],
            [
              "Calculer tes objectifs nutritionnels et sportifs à partir de tes données de santé, et te proposer des conseils.",
              <>
                <strong>Consentement explicite</strong> (art. 9.2.a). Cet accord t’est demandé
                séparément, à la première sauvegarde de ton profil. Sans lui, aucun objectif
                n’est calculé.
              </>,
            ],
            [
              "Générer des séances de sport personnalisées à l’aide d’un modèle d’intelligence artificielle.",
              "Consentement, révocable à tout moment dans tes paramètres. Refusé ou indisponible, le service produit les séances par ses propres règles.",
            ],
            [
              "Analyser une photo d’assiette pour pré-remplir un repas.",
              "Consentement : la fonction ne s’active que lorsque tu prends une photo, et rien n’est enregistré avant que tu aies vérifié le résultat.",
            ],
            [
              "Assurer la sécurité du service, prévenir les abus, diagnostiquer les pannes.",
              "Intérêt légitime (art. 6.1.f) : faire fonctionner un service fiable, sans porter atteinte à tes droits.",
            ],
            [
              "Répondre à une obligation légale, notamment comptable si le service devient payant.",
              "Obligation légale (art. 6.1.c).",
            ],
          ]}
        />
        <p>
          <strong>Retirer ton consentement</strong> est possible à tout moment et n’affecte pas
          la licéité de ce qui a été fait avant. Le retrait s’effectue en désactivant la fonction
          concernée dans tes paramètres, ou en supprimant ton compte.
        </p>
      </Article>

      <Article numero="4" titre="Qui reçoit tes données">
        <p>
          Tes données ne sont <strong>ni vendues, ni louées, ni transmises à des annonceurs</strong>.
          Elles sont accessibles aux seuls destinataires suivants :
        </p>
        <Tableau
          entetes={["Destinataire", "Rôle et données concernées"]}
          lignes={[
            [
              <>
                Hébergeur — <Valeur>{hebergeur.nom}</Valeur>
              </>,
              `Sous-traitant. Héberge le serveur et la base de données en ${hebergeur.pays}. Il n’accède pas au contenu dans le cadre de sa mission.`,
            ],
            [
              "Membres de ton foyer",
              "Si tu rejoins un foyer, ses membres voient le stock partagé, la liste de courses et les repas communs. Ton journal alimentaire personnel et tes données de santé restent privés.",
            ],
            [
              "Administrateur du service",
              "Accède aux comptes (nom d’usage, adresse e-mail, dates, état d’acceptation des conditions), aux contenus publiés et à des statistiques agrégées, afin de gérer le service et de modérer les contenus signalés. Il n’a accès ni à ton journal alimentaire, ni à ton profil de santé, ni à tes pesées, ni à tes séances : ce cloisonnement est imposé par le code et vérifié par des tests. Chacune de ses actions est consignée avec son motif.",
            ],
            [
              "Open Food Facts",
              "Base de données publique interrogée pour enrichir les fiches d’aliments. Seul le terme recherché ou le code-barres scanné lui est transmis, jamais ton identité ni ton journal.",
            ],
            [
              "Fournisseur d’intelligence artificielle",
              <>
                Uniquement si l’IA est activée. Deux configurations existent, et celle qui
                s’applique t’est indiquée à l’article 5.
              </>,
            ],
          ]}
        />
      </Article>

      <Article numero="5" titre="Intelligence artificielle et transferts hors de l’Union européenne">
        <p>
          {service} peut faire appel à un modèle d’intelligence artificielle pour proposer des
          séances de sport et pour lire une photo d’assiette. Deux configurations sont possibles,
          et elles n’ont pas les mêmes conséquences pour tes données :
        </p>
        <ul className="list-disc space-y-2 pl-5">
          <li>
            <strong>Modèle exécuté localement</strong> sur le serveur de {service} (Ollama).
            Aucune donnée ne quitte le serveur, aucun transfert hors de l’Union européenne n’a
            lieu. C’est la configuration à privilégier.
          </li>
          <li>
            <strong>API d’un fournisseur tiers</strong> (Anthropic, États-Unis), si elle est
            activée par l’éditeur. Dans ce cas, un contexte <em>anonymisé</em> lui est transmis :
            âge, sexe, poids, taille, objectif, historique sportif récent, matériel disponible et
            zones douloureuses — <strong>jamais ton nom ni ton adresse e-mail</strong>. Pour la
            photo d’assiette, l’image elle-même est transmise. Ce transfert vers les États-Unis
            est encadré par les clauses contractuelles types de la Commission européenne et les
            garanties du fournisseur. Ces données ne sont pas utilisées pour entraîner ses
            modèles.
          </li>
        </ul>
        <p>
          L’IA est <strong>toujours facultative</strong> : désactivée, indisponible ou en panne,
          le service continue de fonctionner à partir de ses propres règles. Tu peux la refuser
          dans tes paramètres sans perdre l’accès au reste.
        </p>
      </Article>

      <Article numero="6" titre="Combien de temps tes données sont conservées">
        <Tableau
          entetes={["Donnée", "Durée"]}
          lignes={[
            ["Compte, profil, journal alimentaire, séances de sport", "Tant que le compte existe."],
            [
              "Après suppression du compte",
              <>
                Effacement immédiat de ton compte, de ton profil, de tes pesées, de tes repas, de
                tes recettes, de ton stock, de tes courses, de ta planification et de tes séances.
                Deux exceptions, décrites à l’article 6 bis : les aliments et recettes que tu as
                rendus publics sont <strong>conservés sans lien avec toi</strong>, et les lignes
                que tu avais écrites dans un foyer partagé reviennent à son propriétaire.
              </>,
            ],
            [
              "Sauvegardes de la base de données",
              "Quatorze jours au maximum, par rotation. Une donnée supprimée disparaît donc des sauvegardes au plus tard au bout de quatorze jours.",
            ],
            ["Jetons d’authentification", "Trente jours, puis purge automatique quotidienne."],
            ["Journaux du serveur", "Quatorze jours, par rotation automatique."],
          ]}
        />
      </Article>

      <Article numero="6 bis" titre="Ce qui subsiste après une suppression de compte">
        <p>
          Supprimer ton compte efface tout ce qui te concerne, à deux exceptions près, qu’il
          serait malhonnête de passer sous silence :
        </p>
        <ul className="list-disc space-y-1.5 pl-5">
          <li>
            <strong>Les aliments et recettes que tu as rendus publics</strong> restent dans le
            catalogue commun, mais leur lien avec toi est rompu : le champ « créé par » est vidé.
            Ils ne permettent plus de t’identifier. Cette conservation évite de casser les repas
            des autres utilisateurs qui s’y réfèrent.
          </li>
          <li>
            <strong>Les lignes que tu as écrites dans un foyer partagé</strong> — repas communs,
            articles de la liste de courses, planification — sont réattribuées au propriétaire du
            foyer, pour que celui-ci ne perde pas l’organisation commune.
          </li>
        </ul>
        <p>
          Si tu souhaites que ces éléments soient supprimés plutôt qu’anonymisés, écris à{" "}
          <Valeur>{contact.emailDonnees}</Valeur> : ta demande sera traitée.
        </p>
      </Article>

      <Article numero="7" titre="Tes droits">
        <p>Le RGPD te reconnaît les droits suivants, que tu peux exercer à tout moment :</p>
        <ul className="list-disc space-y-1.5 pl-5">
          <li>
            <strong>Accès</strong> (art. 15) : savoir quelles données sont traitées et en obtenir
            une copie.
          </li>
          <li>
            <strong>Rectification</strong> (art. 16) : corriger une donnée inexacte. La plupart
            sont modifiables directement dans l’application.
          </li>
          <li>
            <strong>Effacement</strong> (art. 17) : supprimer ton compte et tes données.
          </li>
          <li>
            <strong>Limitation</strong> (art. 18) : demander le gel d’un traitement contesté.
          </li>
          <li>
            <strong>Portabilité</strong> (art. 20) : récupérer tes données dans un format
            structuré et lisible par une machine.
          </li>
          <li>
            <strong>Opposition</strong> (art. 21) : t’opposer à un traitement fondé sur l’intérêt
            légitime.
          </li>
          <li>
            <strong>Retrait du consentement</strong> (art. 7.3), à tout moment et sans
            justification.
          </li>
          <li>
            <strong>Directives post-mortem</strong> : définir le sort de tes données après ton
            décès, en nous écrivant.
          </li>
        </ul>
        <p>
          <strong>Comment les exercer.</strong> L’accès, la portabilité et l’effacement sont
          immédiats et autonomes : dans{" "}
          <Link href="/dashboard/settings" className="text-emerald-800 hover:underline">
            tes paramètres
          </Link>
          , « Exporter mes données » produit un fichier JSON complet, et « Supprimer mon compte »
          efface tout après confirmation par mot de passe. Pour les autres droits, écris à{" "}
          <Valeur>{contact.emailDonnees}</Valeur>. Une réponse te parviendra dans un délai d’un
          mois, prolongeable de deux mois pour une demande complexe, auquel cas tu en seras
          informé.
        </p>
        <p>
          <strong>Réclamation.</strong> Si tu estimes que tes droits ne sont pas respectés, tu
          peux saisir l’autorité de contrôle :{" "}
          <a href={autorite.site} className="text-emerald-800 hover:underline" rel="noreferrer">
            {autorite.nom}
          </a>
          , {autorite.adresse}.
        </p>
      </Article>

      <Article numero="8" titre="Décisions automatisées">
        <p>
          {service} calcule automatiquement tes objectifs caloriques et te propose des séances.
          Ces traitements <strong>ne produisent aucun effet juridique</strong> et ne t’affectent
          pas de manière significative au sens de l’article 22 du RGPD : ce sont des suggestions,
          que tu restes libre de suivre, d’ignorer ou de remplacer. Tu peux saisir tes propres
          cibles caloriques à la place de celles calculées, et refuser les séances proposées.
        </p>
        <p>
          Des bornes de sécurité sont appliquées pour éviter les objectifs dangereux, notamment un
          plancher calorique qui ne descend jamais sous 1 500 kcal par jour pour un homme et
          1 200 kcal pour une femme, ni sous le métabolisme de base en cas de perte de poids.
        </p>
      </Article>

      <Article numero="9" titre="Sécurité">
        <p>
          Les mesures suivantes protègent tes données (art. 32 RGPD) : chiffrement des échanges
          par HTTPS, mots de passe stockés sous forme d’empreinte cryptographique non réversible,
          authentification par jeton à durée limitée, cloisonnement strict des données entre
          comptes vérifié par des tests automatisés, limitation du nombre de requêtes pour
          prévenir les abus, sauvegardes régulières, et journalisation qui ne contient ni image
          ni contenu de repas.
        </p>
        <p>
          En cas de violation de données susceptible d’engendrer un risque pour tes droits,
          l’autorité de contrôle est notifiée dans les 72 heures et, lorsque le risque est élevé,
          tu en es informé directement (art. 33 et 34).
        </p>
      </Article>

      <Article numero="10" titre="Mineurs">
        <p>
          Le service est accessible à partir de 12 ans. En Belgique, l’accord d’un titulaire de
          l’autorité parentale est requis en dessous de {ageConsentementNumerique} ans ;{" "}
          {service} va au-delà et l’exige jusqu’à 15 ans. Pour les profils mineurs, aucun objectif
          de perte de poids n’est proposé, les régimes restrictifs sont refusés, et les calculs
          utilisent des formules adaptées à l’âge.
        </p>
      </Article>

      <Article numero="11" titre="Stockage sur ton appareil">
        <p>
          {service} n’utilise aucun cookie publicitaire et aucun outil de mesure d’audience. Le
          détail de ce qui est stocké sur ton appareil figure dans la page{" "}
          <Link href="/cookies" className="text-emerald-800 hover:underline">
            Stockage local et cookies
          </Link>
          .
        </p>
      </Article>

      <Article numero="12" titre="Modification de cette politique">
        <p>
          Cette politique porte un numéro de version. Toute modification de fond change cette
          version, et la date d’acceptation de chaque compte est conservée : tu peux ainsi savoir
          à quelle version tu as consenti. En cas de changement important, tu en es informé lors
          de ta prochaine connexion.
        </p>
      </Article>
    </LegalPage>
  );
}
