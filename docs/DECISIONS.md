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

## Portions et estimations

Toute conversion d'une portion ménagère vers des grammes est signalée comme estimation dans l'API et
affichée comme telle dans les deux interfaces. L'utilisateur voit donc toujours si une valeur est
mesurée ou approchée, comme le demandait le cahier des charges.

---

## Hors périmètre de la version 1

| Sujet | Raison |
|---|---|
| Restaurants et carte | dépend d'une source de données et d'une géolocalisation à choisir |
| OCR d'étiquette, saisie vocale, photo d'assiette | phases ultérieures du cahier des charges |
| Notifications système (push) | les notifications sont pour l'instant internes à l'application |
| Espace d'administration `/admin` | périmètre et droits non définis |
| Mode hors ligne en écriture | demanderait une file de synchronisation et une résolution de conflits |
| Thème sombre sur mobile | l'ensemble des écrans est conçu en clair ; l'API conserve le réglage pour plus tard |
| Statistiques anti-gaspillage | les données sont collectées, l'écran reste à concevoir |
