# Ce qu'il reste à faire, côté légal

Liste de travail pour Mavi'oh, établie le 28 septembre 2026, dans l'hypothèse retenue avec le
propriétaire : **service ouvert à des utilisateurs extérieurs, éditeur établi en Belgique**.

Trois sections : ce qui est **obligatoire** et bloque une ouverture au public, ce qui le devient
**à partir d'un certain seuil**, et ce qui **serait bien** sans être exigé.

> Ni ce document ni les pages légales du site ne constituent un avis juridique. Ils sont écrits à
> partir du fonctionnement réel du logiciel et du droit applicable, mais la responsabilité du
> responsable du traitement reste entière : fais-les relire par un juriste avant l'ouverture.

L'état détaillé des traitements est dans [RGPD.md](RGPD.md). Ce document-ci est la liste d'actions.

---

## A. Obligatoire — bloquant avant d'ouvrir à qui que ce soit

| # | Action | Pourquoi | Effort |
|---|---|---|---|
| A1 | **Remplir `web/src/lib/legal/operateur.ts`** : dénomination, forme juridique, adresse géographique, courriel, hébergeur. Puis reconstruire le site | Livre XII du Code de droit économique et art. 13 RGPD. Tant qu'une valeur manque, les pages affichent un bandeau « à ne pas publier en l'état » | 10 min |
| A2 | **Passer en HTTPS** avec un certificat valide (`certbot`) | La politique de confidentialité affirme que les échanges sont chiffrés. En HTTP c'est faux, et les mots de passe circulent en clair | 15 min |
| A3 | **Installer la sauvegarde quotidienne** (`scripts/deploy/backup.sh`) | La politique annonce une rotation de quatorze jours. Et l'art. 32 exige de pouvoir rétablir les données | 5 min |
| A4 | **Signer un contrat de sous-traitance avec l'hébergeur** (art. 28 RGPD) | Obligatoire dès qu'un tiers héberge des données personnelles. Les grands hébergeurs proposent un modèle à accepter en ligne | 30 min |
| A5 | **Supprimer le compte de démonstration** `demo@mavioh.app` | Son mot de passe est public et il donne accès à un compte applicatif | 1 min |
| A6 | **Ouvrir un registre des violations de données**, même vide | Art. 33.5 : toute violation doit y être consignée, y compris celles qui ne sont pas notifiées | 5 min |
| A7 | **Décider du sort de l'IA Anthropic**. Soit tu l'actives et tu acceptes ses conditions professionnelles en conservant la preuve des clauses contractuelles types, soit tu restes sur Ollama en local | Transfert de données de santé vers les États-Unis. En local, ce point disparaît entièrement | 15 min |
| A8 | **Mettre en place un point de contact pour les signalements** de contenus illicites, et l'indiquer dans les mentions légales | Règlement européen sur les services numériques : Mavi'oh héberge des recettes et des aliments publiés par ses utilisateurs, c'est un service d'hébergement | 30 min |

**A8 mérite un mot.** Dès que des recettes sont publiques, Mavi'oh héberge du contenu produit par
des tiers. Le règlement sur les services numériques impose alors un mécanisme permettant à
n'importe qui de signaler un contenu illicite, et l'obligation de motiver un retrait auprès de
celui qui l'a publié. Les micro et petites entreprises sont dispensées d'une partie des
obligations renforcées, mais pas de celles-là. L'espace d'administration en cours de construction
est le bon endroit pour traiter ces signalements.

---

## B. Obligatoire à partir d'un certain seuil

| Déclencheur | Ce qui devient obligatoire |
|---|---|
| Traitement de données de santé **à grande échelle** — plusieurs milliers d'utilisateurs actifs | **Analyse d'impact** complète (art. 35). L'évaluation préalable qui conclut aujourd'hui à la non-obligation est datée dans `RGPD.md` ; elle est à réexaminer chaque année |
| Suivi systématique à grande échelle, ou traitement de santé à grande échelle | **Désignation d'un délégué à la protection des données** (art. 37) |
| Le service devient **payant** | Conditions générales de vente, droit de rétractation de 14 jours, information précontractuelle, obligations comptables et TVA, mentions du Code de droit économique sur la vente à distance |
| Ouverture **hors Union européenne** | Analyse des transferts sortants, éventuel représentant dans l'Union si l'éditeur en sort |
| Ajout d'une **décision automatisée produisant un effet juridique** | Art. 22 : droit d'obtenir une intervention humaine, information spécifique. Aujourd'hui les objectifs calculés ne relèvent pas de l'art. 22, et c'est écrit dans la politique |
| **Publicité, mesure d'audience ou traceur** ajoutés | Bandeau de consentement, politique de cookies revue, éventuel registre de consentement |

---

## C. Ce que les nouvelles fonctionnalités changent

Trois des demandes en cours ont des conséquences légales directes. Elles ne sont pas des détails.

### C1 — L'espace d'administration

Un administrateur qui peut consulter les comptes accède à des **données de santé de tiers**. Il
faut :

- **limiter techniquement ce qu'il voit** : compter, agréger, modérer — mais pas lire le journal
  alimentaire ni le profil de santé d'un utilisateur. Ce garde-fou doit être dans le code, pas
  dans une consigne ;
- **journaliser les actions d'administration** : qui, quand, sur quoi. C'est ce qui permet de
  prouver qu'il n'y a pas eu d'abus ;
- **ajouter ce traitement au registre** de `RGPD.md` ;
- **mentionner l'administration dans la politique de confidentialité** : la personne doit savoir
  qu'un administrateur existe et ce qu'il peut voir. Aujourd'hui la politique ne le dit pas, ce
  qui la rend incomplète dès que l'espace existe ;
- prévoir une **authentification renforcée** pour ce compte — au minimum un mot de passe long et
  distinct, idéalement un second facteur.

### C2 — L'accès Internet des modèles

La politique publiée affirme aujourd'hui qu'en configuration locale **aucune donnée ne quitte la
machine**. Une recherche sur Internet rompt cette promesse : la requête part chez un moteur de
recherche, qui devient un destinataire.

- **mettre à jour la politique de confidentialité** avant d'activer la fonction, sans quoi elle
  devient mensongère ;
- **rendre la fonction désactivable** par l'utilisateur, et probablement désactivée par défaut ;
- **ne jamais envoyer de données personnelles dans une requête de recherche** : « recette à base
  de bananes pauvre en calories » est acceptable, le poids ou l'objectif de la personne ne le sont
  pas ;
- ajouter le moteur de recherche au registre des destinataires.

### C3 — La recette générée par IA

- **les allergènes déclarés sont une contrainte de sécurité**, pas une préférence. Une
  vérification côté serveur doit écarter toute recette contenant un allergène déclaré, en plus de
  la consigne donnée au modèle ;
- **conserver l'avertissement** : une recette proposée est une suggestion, pas un conseil
  diététique ;
- **attention aux allégations** : le règlement européen 1924/2006 encadre les allégations
  nutritionnelles et de santé. « Recette pour perdre du poids » est à la limite. Le prompt
  système du coach interdit déjà « brûle-graisse », « détox » et « garanti » — la même interdiction
  doit couvrir la génération de recettes.

---

## D. Ce qui serait bien, sans être obligatoire

| # | Quoi | Bénéfice |
|---|---|---|
| D1 | **Vérification de l'adresse e-mail** à l'inscription | Évite les comptes fantômes, fiabilise la réinitialisation de mot de passe, et conditionne un vrai contact avec la personne |
| D2 | **Second facteur d'authentification**, au moins pour l'administrateur | Un compte administrateur compromis expose tous les autres |
| D3 | **Suppression automatique des comptes inactifs** après 36 mois, avec un courriel d'avertissement | Principe de limitation de la conservation. La politique n'en promet aucune aujourd'hui, ce qui est honnête mais perfectible |
| D4 | **Journal des accès** aux données sensibles, côté serveur | Permet de détecter et de prouver ce qui s'est passé lors d'un incident |
| D5 | **Export au format lisible par un humain** en plus du JSON — un PDF ou un CSV | Le droit d'accès est mieux servi par quelque chose qu'on peut lire |
| D6 | **Page « statut du service »** et information en cas de panne | Bonne pratique, et utile en cas d'incident de sécurité |
| D7 | **Accessibilité numérique** : contrastes, navigation au clavier, lecteurs d'écran | L'acte européen sur l'accessibilité vise surtout les services marchands, mais c'est une question de qualité autant que de conformité |
| D8 | **Assurance responsabilité civile professionnelle** | Un service qui donne des conseils nutritionnels et sportifs à des tiers expose son éditeur |
| D9 | **Chiffrement au repos** de la base de données | Réduit fortement la gravité d'un vol de disque ou de sauvegarde |
| D10 | **Test de restauration d'une sauvegarde**, une fois par trimestre | Une sauvegarde jamais restaurée n'est pas une sauvegarde |
| D11 | **Mention explicite du statut non médical** à la première ouverture, pas seulement dans les conditions | Le risque principal du produit est qu'une personne prenne une estimation pour un avis médical |
| D12 | **Politique de divulgation de vulnérabilités** : une adresse pour signaler une faille | Évite qu'un chercheur bien intentionné publie sans prévenir |

---

## E. Ce qui n'est pas nécessaire, et pourquoi

Autant le dire, pour éviter le travail inutile :

- **Pas de bandeau de cookies.** Seuls un jeton d'authentification et le nom d'affichage sont
  stockés, tous deux strictement nécessaires au service demandé. Un bandeau serait une gêne sans
  objet.
- **Pas de marquage CE ni de statut de dispositif médical.** Mavi'oh ne pose pas de diagnostic, ne
  traite pas de maladie et ne prescrit rien : il calcule des estimations à partir de formules
  publiques. Cette qualification changerait si le produit se mettait à interpréter des résultats
  cliniques ou à s'adresser à des patients pour une pathologie donnée.
- **Pas de délégué à la protection des données** au stade actuel : aucun des trois cas de
  l'art. 37 n'est rempli. À réévaluer selon le seuil indiqué en section B.
- **Pas d'analyse d'impact** complète aujourd'hui, pour les raisons détaillées dans `RGPD.md`.
  L'évaluation préalable est elle-même une obligation, et elle est faite.
