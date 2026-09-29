# Décisions techniques et périmètre

Ce document explique les choix structurants de Mavi'oh et ce qui est volontairement laissé de côté
pour la version 1. Il complète le cahier des charges, qui listait ces points comme « à arbitrer ».

---

## Base de données : PostgreSQL et SQLite, pas MySQL

Le cahier des charges prévoyait PostgreSQL. Le schéma utilise des index uniques partiels
(`CREATE UNIQUE INDEX … WHERE …`) pour que les lieux de stock soient uniques par utilisateur **ou**
par foyer selon le cas. PostgreSQL et SQLite savent le faire, MySQL et MariaDB non. SQLite sert au
développement et aux tests automatisés, PostgreSQL à la production.

Conséquence pratique : les migrations évitent toute syntaxe propriétaire, les clés étrangères sont
déclarées à la création des tables, et les suppressions en cascade métier sont faites explicitement
par les services plutôt que confiées à la base, car SQLite n'applique pas les clés étrangères
ajoutées après coup.

## Gel du contrat d'API existant

Les premiers écrans (connexion, profil, aliments, recettes, stock) étaient déjà en production côté
clients. Leurs réponses ont été **gelées** : mêmes clés, mêmes enveloppes, mêmes codes d'erreur. Les
nouveautés sont uniquement additives. Des tests de contrat vérifient les jeux de clés exacts à chaque
exécution de la suite, ce qui permet de faire évoluer le backend sans casser une version d'application
déjà installée sur un téléphone.

Le profil conserve ses noms de champs en français (`poids`, `taille`, `objectif_type`…), les
ressources plus récentes utilisent des noms anglais en snake_case. Cette incohérence est assumée :
la renommer aurait cassé les clients existants sans bénéfice fonctionnel.

## Versionnage `/api` et `/api/v1`

Le cahier des charges demandait `/api/v1`. Les clients existants appelaient `/api`. Les deux préfixes
servent exactement les mêmes routes, ce qui permet d'adopter `v1` progressivement sans rupture.

## Calculs côté serveur

Les objectifs nutritionnels étaient calculés en double, en Dart et en TypeScript, avec des constantes
divergentes. Tout est désormais calculé par le serveur et testé unitairement. Les clients affichent
et proposent une prévisualisation via un endpoint dédié, sans jamais recalculer.

## Open Food Facts en cache serveur

Les clients interrogeaient Open Food Facts directement, chacun avec sa propre normalisation, et l'un
d'eux confondait kilojoules et kilocalories : les produits importés affichaient des valeurs environ
4,2 fois trop élevées, dans une base partagée par tous les utilisateurs. La recherche par code-barres
passe maintenant par le serveur, qui normalise, met en cache, conserve la provenance et la date de
récupération, et rafraîchit les fiches de plus de 90 jours.

## Coach IA optionnel

La génération de séances par Claude est un confort, pas une dépendance. Sans clé d'API, avec un quota
dépassé, un réseau coupé ou une réponse illisible, les règles Mavi'oh prennent le relais
automatiquement et l'utilisateur reçoit quand même une séance. Aucune donnée nominative n'est envoyée
au modèle : seul un contexte anonymisé l'est.

Le planificateur suit la même règle avec le champ `demande` de `POST /api/planner/generate`
(« le matin je n'ai pas le temps de cuisiner »). Sans demande, la génération reste celle d'avant, par
les règles — c'est la garantie de non-régression dont dépend l'application mobile. Avec une demande,
le modèle ne rédige rien : il choisit parmi les recettes que les règles retiendraient déjà et ne
renvoie que des identifiants, chacun revérifié par le serveur avant d'entrer au plan. Régime et
allergènes ne sont jamais confiés à sa bonne volonté. La réponse porte `generated_by`, avec les mêmes
valeurs que le module sport, pour dire par quel moteur la semaine a été composée.

## Une semaine planifiée qui ne se répète pas

La génération ramenait les mêmes plats tous les deux jours, pour trois raisons cumulées : le
classement des candidats ne dépendait pas du jour, donc la même recette gagnait toujours ; le
garde-fou n'interdisait la répétition qu'à moins de trois jours, ce qui, sur sept, revient à servir
chaque plat deux fois ; et le vivier était trop petit pour faire autrement — huit recettes et
vingt-quatre idées de repas pour quatorze créneaux hebdomadaires.

Le vivier compte désormais plus de cent idées de repas courants en Belgique et en France
(`config/meal_ideas.php`) et vingt-quatre recettes publiques dans le jeu de démonstration. Un plat
n'est plus servi deux fois dans la semaine générée tant qu'il reste de quoi choisir ; quand le vivier
est épuisé, la génération répète le plat le plus ancien plutôt que de laisser un créneau vide. Deux
plats de la même famille — volaille, poisson, légumineuses… — ne se suivent pas deux jours d'affilée,
et un plat mangé dans le mois écoulé redescend au classement, ce qui évite deux semaines identiques.
Les priorités qui avaient du sens n'ont pas bougé : mes recettes d'abord, puis celles qui consomment
un stock qui périme.

Rien de tout cela n'est tiré au sort : l'ordre tourne selon la date et la personne, et deux
générations de la même semaine par la même personne donnent exactement le même menu.

Les valeurs nutritionnelles des idées de repas sont des estimations, pas des mesures, et les plans
qui en viennent restent marqués comme telles (`is_estimate`). Ce sont des ordres de grandeur destinés
à choisir un plat proche du budget du repas, rien de plus.

## Ce que couvre l'offre gratuite

Le partage n'est pas « les fonctions simples d'un côté, l'IA de l'autre ». Il porte sur le travail
rendu :

- **Gratuit, le suivi.** Enregistrer ses repas, chercher un aliment, scanner un code-barres, écrire
  ses recettes, se peser, tenir son calendrier sportif, consigner une activité ou une séance faite,
  relire son historique. Plus le **coach du jour**, un moteur de règles qui ne coûte rien à faire
  tourner et qui donne envie du reste.
- **Payant, ce qui travaille à la place de l'utilisateur.** Le stock et ses alertes, la liste de
  courses, le planificateur, l'évaluation d'un régime, le mode foyer, l'IA — et le **coach sportif**,
  c'est-à-dire la séance proposée.

Le coach sportif est payant **quel que soit son moteur**. L'IA n'est qu'une implémentation : quand
elle est absente, lente ou illisible, les règles Mavi'oh rendent le même service, une séance
construite pour quelqu'un. Laisser passer le repli reviendrait à offrir la fonction dès que l'IA
tombe, et à faire dépendre un droit d'accès de la disponibilité d'un serveur de modèles. Le verrou
est donc posé sur les routes qui *proposent* (`sessions/generate`, `calendar/{plan}/propose`,
`calendar/plan-week`), jamais sur celles qui *enregistrent*.

## Poser une offre à la main

Aucun paiement n'est branché. Une offre s'ouvre donc de deux façons : un **code d'accès**, que
l'on distribue, ou un **réglage direct** depuis le panneau d'administration, qui vise une personne.
Les deux passent par le journal, parce que décider de ce à quoi quelqu'un a droit — parfois contre
de l'argent — doit rester défendable devant lui.

Trois règles encadrent le geste :

- **Il ne détruit rien.** Seules `offre` et `offre_expire_le` bougent. Repasser un compte au gratuit
  lui retire des fonctions, jamais ses repas, son profil ou ses pesées, et il retrouve tout le jour
  où il reprend une offre.
- **Un code n'abaisse jamais une offre.** Quelqu'un à qui on a posé le Foyer garderait autrement son
  offre jusqu'au jour où il saisirait un code « Complet » reçu ailleurs — et ferait tomber les
  membres de son foyer avec lui. À rang égal, une échéance ne se rapproche pas non plus.
- **L'écran dit ce que le geste va faire.** L'offre en cours et son échéance sont rappelées, la durée
  est préremplie avec ce qu'il reste à courir, et retirer l'offre de quelqu'un qui porte un foyer
  affiche combien de personnes perdront l'accès en même temps que lui.

L'expiration est tranchée par le serveur, jamais par le navigateur : elle se joue à l'heure près,
et une comparaison de dates côté client afficherait une offre encore active des heures après que
l'accès a réellement été coupé.

## État et navigation côté mobile

`flutter_riverpod` et `go_router` étaient déclarés mais inutilisés. La version 1 s'appuie sur des
widgets à état et une session partagée observable, ce qui suffit à une application à cinq onglets et
évite une migration à moitié faite. Les deux dépendances ont été retirées ; elles reviendront si des
liens profonds ou une navigation web deviennent nécessaires.

## Relais d'API côté web

Le navigateur n'appelle jamais Laravel directement. Chaque requête passe par un route handler Next
qui ajoute le jeton, applique un délai d'attente et normalise les erreurs. Cela supprime toute
configuration CORS, garde une seule origine et permettra de passer le jeton en cookie httpOnly sans
toucher aux pages.

## Sécurité

Mots de passe hachés par bcrypt, jetons Sanctum expirant au bout de 30 jours et purgés
quotidiennement, limitation à 10 tentatives par minute sur la connexion et l'inscription, 120 requêtes
par minute par utilisateur ailleurs. Chaque ressource est chargée à travers son propriétaire ou son
foyer : l'identifiant d'un autre utilisateur donne une erreur « Introuvable », jamais une fuite de
données. Un test d'isolation le vérifie pour chaque endpoint identifiant une ressource.

## Données de santé

Le premier enregistrement du profil demande un consentement explicite. Les profils de moins de 18 ans
suivent des règles adaptées, ceux de moins de 15 ans requièrent un accord parental, et les situations
de grossesse, allaitement ou suivi médical désactivent tout objectif de perte ou de prise. Le compte
peut être exporté et supprimé par son propriétaire.

## Magasins, prix indicatifs et promotions

Le choix d'un magasin repose sur un **catalogue interne**, pas sur une API commerciale ni sur
l'aspiration du site des enseignes : elles l'interdisent dans leurs conditions, et une dépendance
à leur HTML se casserait à la première refonte. Quatre enseignes sont servies — Lidl, Colruyt,
Delhaize, Aldi — avec un assortiment de base commun, parce que ce sont les mêmes produits courants,
et un positionnement tarifaire distinct.

**Aucun prix n'est présenté comme réel.** Chaque produit porte sa date de relevé et sa réserve,
le panier rend `estimation.total_estime` et jamais `total`, et un article sans correspondance dans
l'enseigne reste dans la liste **sans prix** — jamais une moyenne, jamais un zéro qui fausserait le
total. Le rattachement se fait par code-barres quand il existe, sinon par libellé normalisé sur des
mots entiers : sans cette exigence, « riz » se rattacherait à « fricadelle ».

Les promotions relevées automatiquement sur le web sont enregistrées **non vérifiées, avec l'URL de
leur source**, et une source que la recherche n'a pas réellement consultée est rejetée : une
promotion invérifiable est inutilisable. Le contenu ramené est une donnée inerte, comme pour la
recette depuis le stock ; seul le nom de l'enseigne part sur Internet.

Dans le planificateur, une promotion fait remonter une recette **à qualité égale seulement** :
après les recettes personnelles et après le stock qui périme. Jeter un aliment déjà payé coûte plus
cher que de rater une remise.

Le propriétaire corrige et complète l'assortiment sans toucher au code :

```
php artisan mavioh:magasin-importer lidl chemin/lidl.csv --date=2026-10-05
php artisan mavioh:magasin-importer colruyt colruyt.json --simulation
```

Le CSV attend les colonnes `libelle;rayon;prix;unite;quantite;marque;code_barres` (séparateur `;`
ou `,`, virgule décimale acceptée) ; le JSON attend les mêmes clés. Seul `libelle` est obligatoire,
un rayon inconnu range le produit en « autre » plutôt que de le perdre, et un prix illisible laisse
la ligne sans prix. L'import est idempotent.

## Portions et estimations

Toute conversion d'une portion ménagère vers des grammes est signalée comme estimation dans l'API et
affichée comme telle dans les deux interfaces. L'utilisateur voit donc toujours si une valeur est
mesurée ou approchée, comme le demandait le cahier des charges.

---

## Hors périmètre de la version 1

| Sujet | Raison |
|---|---|
| Restaurants et carte | dépend d'une source de données et d'une géolocalisation à choisir |
| OCR d'étiquette, saisie vocale | phases ultérieures du cahier des charges |
| Écran d'invitation sur mobile | Le backend refuse en 402 partout : aucune fonctionnalité payante n'est atteignable depuis le téléphone, et l'application affiche le message du serveur, qui dit quelle offre est requise. Ce qui manque encore, c'est l'écran qui vante la fonction et propose de saisir un code, comme sur le web. À compléter |
| Espace d'administration sur mobile | La modération se fait posément, depuis un bureau. Le porter doublerait la surface d'attaque et de test pour un usage marginal, alors que le mobile n'a aucune notion de rôle. Décision assumée, pas un oubli |
| Notifications système (push) | les notifications sont pour l'instant internes à l'application |
| Espace d'administration `/admin` | périmètre et droits non définis |
| Mode hors ligne en écriture | demanderait une file de synchronisation et une résolution de conflits |
| Thème sombre sur mobile | l'ensemble des écrans est conçu en clair ; l'API conserve le réglage pour plus tard |
| Statistiques anti-gaspillage | les données sont collectées, l'écran reste à concevoir |
