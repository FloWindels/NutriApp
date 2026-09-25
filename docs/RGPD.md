# Conformité RGPD de Mavi'oh

Ce document est le dossier interne de conformité : registre des traitements, analyse des risques,
procédures. Il n'est pas destiné au public — les documents publics sont les pages
`/mentions-legales`, `/cgu`, `/confidentialite` et `/cookies` du site.

> **Avertissement.** Ce dossier et les pages légales ont été rédigés à partir du fonctionnement
> réel du logiciel. Ils ne constituent pas un avis juridique. Avant une ouverture au public,
> fais-les relire par un juriste : la responsabilité du responsable du traitement reste entière.

Hypothèses retenues, à corriger si elles changent :

- service ouvert à des utilisateurs extérieurs, donc RGPD pleinement applicable ;
- responsable du traitement établi en **Belgique** ;
- autorité de contrôle compétente : **Autorité de protection des données** (APD/GBA) ;
- âge du consentement numérique en Belgique : **13 ans** — Mavi'oh exige l'accord parental
  jusqu'à 15 ans, volontairement plus strict.

---

## 1. Ce qu'il reste à faire avant l'ouverture au public

| # | Action | Qui | Bloquant |
|---|---|---|---|
| 1 | Remplir `web/src/lib/legal/operateur.ts` : identité, adresse, contact, hébergeur. Tant que des valeurs valent `À COMPLÉTER`, les pages affichent un bandeau d'avertissement | éditeur | **oui** |
| 2 | Mettre le service en HTTPS avec un certificat valide (`certbot`) — la politique affirme que les échanges sont chiffrés | éditeur | **oui** |
| 3 | Installer la sauvegarde quotidienne (`scripts/deploy/backup.sh`) : la politique annonce une rotation de quatorze jours | éditeur | **oui** |
| 4 | Signer un contrat de sous-traitance (art. 28) avec l'hébergeur | éditeur | **oui** |
| 5 | Si l'IA Anthropic est activée : accepter ses conditions professionnelles et conserver la preuve des clauses contractuelles types encadrant le transfert hors UE | éditeur | oui si activée |
| 6 | Faire relire les documents par un juriste | éditeur | recommandé |
| 7 | Supprimer le compte de démonstration `demo@mavioh.app` | éditeur | **oui** |

Le point 5 disparaît si la reconnaissance d'image et le coach tournent en local avec Ollama :
aucune donnée ne quitte alors le serveur, et il n'y a plus de transfert hors Union européenne.

---

## 2. Registre des activités de traitement (art. 30)

**Responsable du traitement** : voir `web/src/lib/legal/operateur.ts`.
**Délégué à la protection des données** : aucun. Le service n'entre pas dans les cas de
désignation obligatoire de l'article 37 : ce n'est pas une autorité publique, son activité de
base n'est pas un suivi systématique à grande échelle, et le traitement de données de santé n'y
est pas réalisé à grande échelle au sens des lignes directrices du CEPD. **À réévaluer** si le
nombre d'utilisateurs devient significatif.

### T1 — Gestion des comptes

| | |
|---|---|
| Finalité | Créer un compte, authentifier, permettre l'usage du service |
| Base légale | Exécution du contrat (art. 6.1.b) |
| Personnes | Utilisateurs inscrits |
| Données | Nom d'usage, e-mail, empreinte du mot de passe, jetons, dates |
| Destinataires | Hébergeur (sous-traitant) |
| Transfert hors UE | Aucun |
| Conservation | Durée de vie du compte ; jetons 30 jours |
| Sécurité | HTTPS, `bcrypt`, jetons Sanctum expirants, limitation de débit |

### T2 — Calcul des objectifs nutritionnels et sportifs

| | |
|---|---|
| Finalité | Calculer besoins caloriques, macronutriments, dépense énergétique, conseils |
| Base légale | **Consentement explicite** (art. 9.2.a) — données de santé |
| Preuve | `users.consentement_sante_at`, recueilli à la première sauvegarde du profil |
| Personnes | Utilisateurs ayant rempli un profil |
| Données | Âge, sexe, taille, poids, pesées, objectif, activité, régime, allergènes, situation particulière, zones douloureuses |
| Destinataires | Hébergeur |
| Transfert hors UE | Aucun |
| Conservation | Durée de vie du compte |
| Particularité | Bornes de sécurité codées en dur : jamais sous 1 500 kcal (homme) / 1 200 kcal (femme), ni sous le métabolisme de base en perte |

### T3 — Journal alimentaire, stock, courses, planification

| | |
|---|---|
| Finalité | Suivre les repas, le stock, les courses, la semaine |
| Base légale | Exécution du contrat (art. 6.1.b) ; **consentement** pour la partie santé dérivée |
| Personnes | Utilisateurs, et membres d'un même foyer pour les données partagées |
| Données | Repas et aliments, recettes, photos, stock, liste de courses, planification |
| Destinataires | Hébergeur ; membres du foyer pour les seules données explicitement partagées |
| Transfert hors UE | Aucun |
| Conservation | Durée de vie du compte |

### T4 — Suivi sportif

| | |
|---|---|
| Finalité | Planifier et enregistrer les séances, convertir l'effort en calories |
| Base légale | Exécution du contrat ; consentement pour les données de santé associées |
| Données | Sports pratiqués, durées, intensité, effort ressenti, calories, zones douloureuses |
| Conservation | Durée de vie du compte |

### T5 — Coach sportif par intelligence artificielle

| | |
|---|---|
| Finalité | Proposer une séance personnalisée |
| Base légale | **Consentement** — réglage `user_settings.ia_seances`, révocable |
| Données transmises | Contexte **anonymisé** : âge, sexe, poids, taille, IMC, objectif, historique de 14 jours, matériel, lieu, zones à éviter. **Ni nom, ni e-mail, ni identifiant** |
| Sous-traitant | Anthropic (États-Unis) **si activé**, sinon modèle local Ollama |
| Transfert hors UE | États-Unis, encadré par les clauses contractuelles types — **uniquement en configuration Anthropic** |
| Conservation | Aucune conservation chez le fournisseur au-delà du traitement de la requête |
| Repli | Indisponible ou refusé, les séances sont produites par les règles internes |

### T6 — Reconnaissance de photo d'assiette

| | |
|---|---|
| Finalité | Pré-remplir un repas à partir d'une photo |
| Base légale | **Consentement** — la fonction ne s'active qu'à l'initiative de la personne |
| Données transmises | L'image, plus un contexte anonymisé (régime, allergènes). Ni nom, ni e-mail — vérifié par un test automatisé |
| Sous-traitant | Anthropic (États-Unis) ou modèle multimodal local |
| Transfert hors UE | Idem T5 |
| Conservation | L'image n'est **jamais stockée** côté serveur ni journalisée ; elle transite en mémoire |
| Garantie | Aucune écriture en base avant vérification humaine — vérifié par un test automatisé |

### T7 — Enrichissement du catalogue d'aliments

| | |
|---|---|
| Finalité | Compléter les fiches d'aliments |
| Base légale | Exécution du contrat |
| Données transmises | Terme de recherche ou code-barres uniquement |
| Destinataire | Open Food Facts (association française, données sous licence ODbL) |
| Transfert hors UE | Aucun |

### T8 — Sécurité et journalisation

| | |
|---|---|
| Finalité | Détecter les abus, diagnostiquer les pannes |
| Base légale | Intérêt légitime (art. 6.1.f) |
| Données | Adresse IP, horodatage, route appelée, code de réponse. Ni image, ni contenu de repas |
| Conservation | 14 jours (`config/logging.php`, canal `daily`) |
| Mise en balance | Nécessaire au fonctionnement d'un service fiable ; données minimales ; pas de profilage |

---

## 3. Sous-traitants et transferts

| Sous-traitant | Rôle | Localisation | Encadrement |
|---|---|---|---|
| Hébergeur | Serveur et base de données | Belgique | Contrat art. 28 **à signer** |
| Anthropic | Coach IA et vision, **si activés** | États-Unis | Clauses contractuelles types + conditions professionnelles |
| Ollama | Modèle exécuté sur le serveur | Sur place | Aucun transfert, aucun tiers |
| Open Food Facts | Source de données publique | France | Destinataire, non sous-traitant : aucune donnée personnelle transmise |

**Recommandation.** Faire tourner le coach et la vision avec Ollama en local supprime le seul
transfert hors Union européenne du produit, et avec lui l'essentiel de la complexité juridique.

---

## 4. Analyse d'impact (art. 35)

Une analyse d'impact relative à la protection des données est obligatoire lorsqu'un traitement
est susceptible d'engendrer un risque élevé. La liste de l'APD belge vise notamment le traitement
de données de santé à grande échelle.

**Évaluation préalable.** Mavi'oh traite des données de santé, ce qui pèse dans le sens d'une
analyse d'impact. Mais il ne remplit pas les autres critères habituels : pas d'évaluation ou de
notation produisant des effets juridiques, pas de surveillance systématique, pas de croisement de
sources, pas de décision automatisée au sens de l'article 22, pas de données de personnes
vulnérables au-delà des mineurs déjà encadrés, et un volume qui n'est pas à grande échelle.

**Conclusion.** Tant que le service reste d'une taille modeste, une analyse d'impact complète
n'est pas obligatoire. **Elle le devient** si l'un de ces seuils est franchi :

- plusieurs milliers d'utilisateurs actifs ;
- exploitation des données à d'autres fins que le service rendu à la personne ;
- partage des données avec un tiers autre que les sous-traitants listés ;
- ajout d'un traitement produisant un effet juridique ou significatif.

Ce paragraphe tient lieu de trace de l'évaluation préalable, elle-même exigée. **Date de
l'évaluation : 25 septembre 2026.** À réexaminer une fois par an.

### Risques identifiés et mesures

| Risque | Gravité | Mesure en place |
|---|---|---|
| Accès aux données de santé d'un autre utilisateur | Élevée | Cloisonnement par `user_id` sur chaque requête, vérifié par des tests dédiés |
| Fuite de la base | Élevée | Mots de passe en empreinte non réversible, sauvegardes à droits restreints (600), base non exposée au réseau |
| Envoi involontaire de données à un tiers | Moyenne | Contexte anonymisé et vérifié par test ; IA désactivable ; option 100 % locale |
| Objectif nutritionnel dangereux | Élevée | Planchers caloriques, refus des objectifs incohérents, avertissements, règles spécifiques aux mineurs et situations particulières |
| Photo d'assiette mal interprétée | Faible | Vérification humaine obligatoire avant tout enregistrement |
| Perte de données | Moyenne | Sauvegardes quotidiennes, export autonome à tout moment |

---

## 5. Exercice des droits

| Droit | Comment | Délai |
|---|---|---|
| Accès et portabilité (art. 15, 20) | Bouton « Exporter mes données » → JSON complet | Immédiat |
| Rectification (art. 16) | Directement dans l'application | Immédiat |
| Effacement (art. 17) | Bouton « Supprimer mon compte », confirmation par mot de passe | Immédiat |
| Limitation, opposition (art. 18, 21) | Courriel au responsable du traitement | 1 mois |
| Retrait du consentement (art. 7.3) | Réglages, ou suppression du compte | Immédiat |

**Procédure pour une demande reçue par courriel** : vérifier l'identité du demandeur sans
collecter plus que nécessaire ; répondre dans le mois, prolongeable de deux mois en informant la
personne ; consigner la demande et la réponse ; en cas de refus, motiver et rappeler le droit de
réclamation auprès de l'APD.

### Ce qui subsiste après une suppression

Deux exceptions, assumées et écrites dans la politique publique :

- les aliments et recettes rendus publics sont conservés, **créateur mis à `null`** : ils ne
  permettent plus d'identifier qui que ce soit, et leur suppression casserait les repas d'autres
  utilisateurs ;
- les lignes écrites dans un foyer partagé reviennent à son propriétaire.

Une demande d'effacement de ces éléments doit être traitée manuellement.

---

## 6. Violation de données (art. 33 et 34)

1. **Contenir** : couper l'accès compromis, révoquer les jetons
   (`php artisan sanctum:prune-expired`, ou vider la table `personal_access_tokens`), changer les
   secrets (`APP_KEY`, mot de passe de base, clés d'API).
2. **Qualifier** en moins de 24 heures : quelles données, combien de personnes, quel risque.
3. **Notifier l'APD dans les 72 heures** si un risque pour les droits existe — formulaire en ligne
   de l'Autorité de protection des données. Une notification tardive doit être motivée.
4. **Informer les personnes** sans délai si le risque est élevé, en termes clairs : ce qui s'est
   passé, quelles données, quelles conséquences possibles, quoi faire.
5. **Consigner** toute violation, y compris non notifiée, avec les faits, les effets et les
   mesures prises. Ce registre doit pouvoir être présenté à l'autorité.

Une violation touchant des données de santé est presque toujours à risque élevé : dans le doute,
notifier.

---

## 7. Preuve du consentement

| Consentement | Où il est enregistré | Recueilli quand |
|---|---|---|
| Conditions et politique de confidentialité | `users.cgu_accepted_at`, `users.cgu_version`, `users.confidentialite_version` | À l'inscription, case non pré-cochée |
| Données de santé (art. 9) | `users.consentement_sante_at` | À la première sauvegarde du profil, case séparée |
| Accord parental (moins de 15 ans) | `profiles.consentement_parental` | Au profil, obligatoire sous 15 ans |
| Séances proposées par l'IA | `user_settings.ia_seances` | Réglages, activé par défaut, désactivable |

Ces preuves figurent dans l'export de données de la personne, et la version acceptée est visible
dans la réponse de `GET /me`.

**Changement d'un texte** : modifier la version dans `web/src/lib/legal/versions.ts`. Les comptes
existants conservent la trace de la version qu'ils ont acceptée, ce qui permet de savoir qui doit
être réinformé.

---

## 8. Ce qui n'est pas fait

Dit clairement, pour qu'aucun de ces points ne soit cru acquis :

- **Aucun contrat de sous-traitance signé** : à faire avec l'hébergeur avant l'ouverture.
- **Aucune suppression automatique des comptes inactifs.** La politique publique n'en promet
  d'ailleurs aucune. À implémenter si une durée de conservation limitée est souhaitée.
- **Aucune vérification de l'âge déclaré ni de la réalité de l'accord parental** : comme la
  quasi-totalité des services, Mavi'oh s'en remet à la déclaration.
- **Aucun registre des violations** encore ouvert : à créer le jour où il en survient une, ou
  d'avance, vide.
- **Pas de relecture juridique** à ce stade.
- **Application mobile** : les pages légales ne sont pas encore liées depuis l'application
  Flutter.
