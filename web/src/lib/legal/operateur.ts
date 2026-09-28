/**
 * Identité de l'éditeur du service et données factuelles des mentions légales.
 *
 * CE FICHIER EST À REMPLIR PAR L'ÉDITEUR. Rien ici ne doit être deviné : une mention légale
 * inexacte est pire que pas de mention du tout. Tant qu'une valeur vaut `À COMPLÉTER`, les
 * pages légales affichent un avertissement visible, pour qu'un oubli ne passe pas en ligne.
 *
 * Obligations couvertes :
 *  - Belgique, livre XII du Code de droit économique (art. XII.6) : identité, adresse
 *    géographique, contact électronique, numéro d'entreprise et numéro de TVA le cas échéant.
 *  - RGPD art. 13 : identité et coordonnées du responsable du traitement, coordonnées du
 *    délégué à la protection des données s'il en existe un.
 */

export const A_COMPLETER = "À COMPLÉTER";

export type Operateur = {
  /** Nom du service tel qu'il est présenté au public. */
  service: string;
  /** Personne physique ou morale responsable de la publication et du traitement. */
  editeur: {
    /** Dénomination sociale, ou nom et prénom pour une personne physique. */
    nom: string;
    /** « personne physique » ou forme juridique (SRL, ASBL…). */
    forme: string;
    /** Adresse géographique complète — une boîte postale ne suffit pas. */
    adresse: string;
    /** Numéro d'entreprise (BCE). Laisser `null` pour une personne physique non assujettie. */
    numeroEntreprise: string | null;
    /** Numéro de TVA, si assujetti. */
    tva: string | null;
    /** Directeur de la publication. */
    directeurPublication: string;
  };
  contact: {
    /** Adresse de contact général. */
    email: string;
    /** Adresse dédiée aux demandes RGPD. Peut être identique à la précédente. */
    emailDonnees: string;
    telephone: string | null;
  };
  /** Délégué à la protection des données, s'il en a été désigné un (art. 37 RGPD). */
  dpo: { nom: string; email: string } | null;
  hebergeur: {
    nom: string;
    adresse: string;
    /** Pays d'hébergement des données, à indiquer à la personne concernée. */
    pays: string;
  };
  /** Autorité de contrôle compétente, auprès de laquelle une réclamation peut être déposée. */
  autorite: {
    nom: string;
    adresse: string;
    site: string;
  };
  /** Âge en dessous duquel l'accord d'un titulaire de l'autorité parentale est exigé. */
  ageConsentementNumerique: number;
  /**
   * État réel du déploiement, qui change ce que les documents peuvent affirmer.
   *
   * `local` : le service tourne sur un réseau privé, sans nom de domaine ni certificat, et
   * n'est pas offert au public. Les documents existent, sont exacts, et annoncent cette
   * situation au lieu de promettre un chiffrement qui n'existe pas.
   *
   * `public` : le service est accessible depuis Internet en HTTPS. C'est l'état qui rend
   * obligatoires toutes les mentions d'identité.
   */
  deploiement: "local" | "public";
};

export const OPERATEUR: Operateur = {
  service: "Mavi’oh",
  editeur: {
    nom: A_COMPLETER,
    forme: A_COMPLETER,
    adresse: A_COMPLETER,
    numeroEntreprise: null,
    tva: null,
    directeurPublication: A_COMPLETER,
  },
  contact: {
    email: A_COMPLETER,
    emailDonnees: A_COMPLETER,
    telephone: null,
  },
  dpo: null,
  hebergeur: {
    nom: A_COMPLETER,
    adresse: A_COMPLETER,
    pays: "Belgique",
  },
  // Belgique : l'Autorité de protection des données (APD / GBA).
  autorite: {
    nom: "Autorité de protection des données",
    adresse: "Rue de la Presse 35, 1000 Bruxelles, Belgique",
    site: "https://www.autoriteprotectiondonnees.be",
  },
  // Belgique : 13 ans (loi du 30 juillet 2018, art. 7). Mavi’oh exige l'accord parental
  // jusqu'à 15 ans, volontairement plus prudent que le minimum légal.
  ageConsentementNumerique: 13,
  // À passer à "public" le jour de l'ouverture, en même temps que le domaine et le certificat.
  deploiement: "local",
};

/** Vrai tant que le service n'est pas offert au public. */
export function estLocal(operateur: Operateur = OPERATEUR): boolean {
  return operateur.deploiement === "local";
}

/** Une valeur non renseignée doit sauter aux yeux, en ligne comme en relecture. */
export function estIncomplet(operateur: Operateur = OPERATEUR): string[] {
  const manquants: string[] = [];

  const verifier = (chemin: string, valeur: string | null) => {
    if (valeur === A_COMPLETER) manquants.push(chemin);
  };

  verifier("éditeur : nom", operateur.editeur.nom);
  verifier("éditeur : forme juridique", operateur.editeur.forme);
  verifier("éditeur : adresse", operateur.editeur.adresse);
  verifier("éditeur : directeur de la publication", operateur.editeur.directeurPublication);
  verifier("contact : e-mail", operateur.contact.email);
  verifier("contact : e-mail données personnelles", operateur.contact.emailDonnees);
  verifier("hébergeur : nom", operateur.hebergeur.nom);
  verifier("hébergeur : adresse", operateur.hebergeur.adresse);

  return manquants;
}
