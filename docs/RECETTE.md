# Recette fonctionnelle de Mavi'oh

Scénarios à dérouler manuellement avant une mise en production. Ils couvrent les critères
d'acceptation du cahier des charges (§21) et les demandes ajoutées pour le module sport.

**Préparation.** Base fraîche avec les données de démonstration :

```bash
cd backend && php artisan migrate:fresh --seed && php artisan serve --host 0.0.0.0 --port 8000
cd web && npm run dev
cd mobile && flutter run --dart-define=API_BASE_URL=http://<IP-du-PC>:8000/api
```

Compte de démonstration : `demo@mavioh.app` / `Demo1234!`

Chaque scénario se déroule **sur le site web et sur l'application mobile**, sauf mention contraire.

---

## A. Compte et session (critère §21.1)

| # | Étapes | Attendu |
|---|---|---|
| A1 | Créer un compte avec un e-mail déjà pris | Message d'erreur en français sous le champ e-mail, pas de compte créé |
| A2 | Créer un compte valide | Connexion automatique, arrivée sur l'accueil avec l'invitation à compléter le profil |
| A3 | Se déconnecter puis rouvrir l'application | Écran de connexion, aucune donnée du compte visible |
| A4 | Se connecter avec un mauvais mot de passe | « Identifiants invalides. », pas de jeton délivré |
| A5 | Se connecter, fermer l'application, la rouvrir | Session restaurée sans ressaisie |
| A6 | Se déconnecter, puis rejouer une ancienne requête avec le jeton révoqué | Erreur 401, retour à la connexion |
| A7 | Demander une réinitialisation de mot de passe | Message neutre ; le lien est visible dans `backend/storage/logs/laravel-*.log` en local |
| A8 | Couper le backend puis naviguer | Message « serveur injoignable » avec bouton Réessayer, pas de déconnexion |

## B. Profil nutritionnel (critère §21.2)

| # | Étapes | Attendu |
|---|---|---|
| B1 | Renseigner homme, 70 kg, 175 cm, 30 ans, activité modérée, perdre 5 kg en 90 jours | Cible ≈ 2130 kcal, ≈ −0,39 kg/semaine, protéines ≈ 126 g |
| B2 | Modifier un champ | La prévisualisation se met à jour sans enregistrer |
| B3 | Demander une perte vers un poids d'IMC inférieur à 18,5 | Refus expliqué, renvoi vers un professionnel de santé |
| B4 | Choisir « perdre » avec un poids souhaité supérieur au poids actuel | Message d'incohérence sous le champ |
| B5 | Saisir un âge de 15 ans | Objectif forcé au maintien, keto et low carb indisponibles, mention spécifique, accord parental demandé |
| B6 | Activer l'ajustement manuel et saisir 800 kcal | Refus avec le seuil de sécurité rappelé |
| B7 | Première sauvegarde sans cocher le consentement | Refus expliqué |
| B8 | Enregistrer un poids du jour | Le profil se met à jour, les objectifs ne bougent pas pour une variation inférieure à 1 kg |

## C. Aliments et code-barres (critère §21.4)

| # | Étapes | Attendu |
|---|---|---|
| C1 | Rechercher « poulet » | Résultats du catalogue local, avec pastille de provenance |
| C2 | Scanner un produit du placard (mobile) | Fiche complète ; si absent en local, récupéré depuis Open Food Facts et enregistré |
| C3 | Scanner un code inexistant | « Produit introuvable, même sur Open Food Facts. », proposition de le créer |
| C4 | Créer un aliment sans code-barres | Création acceptée |
| C5 | Vérifier les calories d'un produit importé | Valeur en kcal cohérente, jamais un nombre en kilojoules |
| C6 | Ajouter un aliment aux favoris puis rouvrir l'écran | Favori conservé |

## D. Repas et suivi du jour (critère §21.5)

| # | Étapes | Attendu |
|---|---|---|
| D1 | Ajouter 150 g d'un aliment à 120 kcal/100 g au déjeuner | 180 kcal ajoutées, totaux et restants mis à jour partout |
| D2 | Ajouter une recette en 1,5 portion | Calories et macros au prorata |
| D3 | Modifier la quantité d'un aliment déjà enregistré | Totaux recalculés immédiatement |
| D4 | Supprimer un aliment | Totaux recalculés, action annulable |
| D5 | Ajouter un aliment en « 1 bol » | Mention d'estimation affichée |
| D6 | Utiliser « Copier le repas d'hier » | Le repas est recréé à l'identique |
| D7 | Consulter l'historique sur 30 jours | Graphique cohérent avec les repas enregistrés |
| D8 | Ajouter un aliment depuis l'accueil, puis depuis la recherche, puis par le scan | Trois chemins, même feuille d'ajout, pas plus de trois touchers hors saisie |

## E. Stock et anti-gaspillage (critère §21.6)

| # | Étapes | Attendu |
|---|---|---|
| E1 | Ouvrir le stock d'un compte neuf | Frigo, congélateur et placard créés automatiquement |
| E2 | Ajouter un produit avec une date dans deux jours | Pastille d'alerte, produit remonté dans la liste |
| E3 | Consommer 100 g d'un produit et cocher « Retirer du stock » | Repas complété et quantité décrémentée, annulation possible |
| E4 | Consommer la totalité d'un produit | Produit marqué épuisé, jamais supprimé, ajouté à la liste de courses |
| E5 | Renommer puis supprimer un lieu non vide | Renommage accepté, suppression refusée avec explication |
| E6 | Consulter l'accueil | Les produits bientôt périmés y apparaissent |

## F. Coach et régimes

| # | Étapes | Attendu |
|---|---|---|
| F1 | Consulter le coach du jour avec un produit périmant demain | Conseil anti-gaspillage citant le produit, avec une recette si possible |
| F2 | Dépasser le budget de plus de 10 % | Alerte de dépassement, ton neutre, aucune formulation culpabilisante |
| F3 | Ouvrir « Pourquoi ? » sur un conseil | Facteurs déclencheurs listés |
| F4 | Ignorer un conseil puis recharger | Le conseil reste ignoré |
| F5 | Choisir le régime végan et enregistrer trois jours de repas dont un avec du fromage | Écart détecté et expliqué, score cohérent |
| F6 | Consulter le régime reconnu avec moins de trois jours enregistrés | Message « données insuffisantes » et invitation à enregistrer un repas |

## G. Sport (demandes du propriétaire)

| # | Étapes | Attendu |
|---|---|---|
| G1 | Planifier « course à pied, 30 min » pour jeudi | Point visible dans le calendrier à la bonne date |
| G2 | Planifier « musculation » répété huit semaines | Huit entrées créées, suppression proposant la série entière |
| G3 | Créer le sport « padel-maison » absent du catalogue | Sport créé, réutilisable, visible de soi seul |
| G4 | Enregistrer 45 min de vélo en intensité modérée | Calories calculées automatiquement à partir du poids réel |
| G5 | Corriger manuellement les calories | Valeur conservée telle quelle, marquée « manuel » |
| G6 | Consulter le budget du jour après la séance | Calories brûlées ajoutées au budget, explication du coefficient accessible |
| G7 | Passer le coefficient à 50 % dans le profil | Bonus divisé par deux, explication mise à jour |
| G8 | Générer une séance : salle publique, 45 min, prise de muscle, haltères et machines, éviter les genoux | Séance structurée en trois blocs, aucun exercice sollicitant les genoux, matériel respecté |
| G9 | Générer une séance avec la note « j'ai mal à l'épaule droite » | Contrainte prise en compte, avertissement affiché |
| G10 | Générer une séance de course à pied de 30 min | Bloc principal en fractionné ou continu avec durées, pas une liste de mouvements de musculation |
| G11 | Vider `ANTHROPIC_API_KEY` puis régénérer | Séance produite par les règles, badge « Règles Mavi'oh », message expliquant le repli |
| G12 | Démarrer une séance, cocher des exercices, fermer l'application, la rouvrir | Séance reprise avec le chronomètre correct |
| G13 | Terminer une séance avec un effort ressenti élevé | Calories, bonus et conseil de récupération affichés |
| G14 | Planifier sa semaine depuis le calendrier | Jours choisis remplis avec des sports cohérents avec le profil |

## H. Planification, courses et foyer

| # | Étapes | Attendu |
|---|---|---|
| H1 | Générer la semaine dans le planificateur | Créneaux remplis avec des recettes compatibles avec le régime |
| H2 | Marquer un repas planifié comme réalisé | Repas créé dans le journal, plan marqué réalisé |
| H3 | Envoyer le planning vers la liste de courses | Seuls les ingrédients absents du stock sont ajoutés |
| H4 | Cocher un article puis « Mettre au stock » | Article transféré avec sa quantité |
| H5 | Créer un foyer sur un compte, le rejoindre avec un second | Avertissement de fusion avant validation, stock partagé ensuite |
| H6 | Préparer un repas commun pour deux membres | Portions proposées différentes selon les objectifs de chacun |
| H7 | Depuis le second compte, ouvrir l'historique du premier | Impossible : données strictement personnelles (critère §21.7) |
| H8 | Quitter le foyer | Le stock reste au foyer, le compte retrouve un stock personnel |

## I. Paramètres et données personnelles

| # | Étapes | Attendu |
|---|---|---|
| I1 | Modifier le nom et l'e-mail | Enregistré, visible immédiatement |
| I2 | Changer le mot de passe | Les autres appareils sont déconnectés |
| I3 | Exporter ses données | Fichier JSON contenant profil, repas, stock, séances |
| I4 | Régler l'alerte de péremption à 7 jours | Plus de produits remontent dans les alertes |
| I5 | Désactiver les séances proposées par l'IA | La génération repasse sur les règles |
| I6 | Supprimer son compte | Suppression après saisie du mot de passe, reconnexion impossible |

## J. Parité et robustesse (critère §21.8)

| # | Étapes | Attendu |
|---|---|---|
| J1 | Enregistrer un repas sur mobile, ouvrir le site web | La même journée s'affiche avec les mêmes totaux |
| J2 | Planifier une séance sur le site, ouvrir le mobile | Séance présente dans le calendrier |
| J3 | Couper le réseau du téléphone au milieu d'un ajout | Message clair, feuille conservée, aucune donnée perdue |
| J4 | Utiliser l'application avec une taille de police augmentée | Aucun texte tronqué sur l'accueil |
| J5 | Naviguer le site au clavier | Tous les champs et boutons atteignables |

---

## Vérifications automatiques associées

```bash
cd backend && php artisan test
cd web && npx tsc --noEmit && npm run lint && npm run build
cd mobile && flutter analyze && flutter test
```
