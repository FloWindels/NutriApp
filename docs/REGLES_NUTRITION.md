# Règles nutritionnelles de Mavi'oh

Toutes les règles ci-dessous sont appliquées **côté serveur** par `App\Services\NutritionCalculator`
et couvertes par `tests/Unit/Services/NutritionCalculatorTest.php`. Les clients (site web,
application mobile) n'effectuent aucun calcul : ils affichent ce que renvoient `GET /api/profile`
et `POST /api/profile/preview`.

> **Avertissement produit.** Les valeurs produites sont des estimations issues de formules publiques.
> Elles ne constituent ni une mesure clinique ni un avis médical. Ce texte est renvoyé par l'API dans
> `besoins.mention` et affiché sous les objectifs sur les deux clients.

---

## 1. Métabolisme de base

**Adultes (18 ans et plus)** — Mifflin-St Jeor :

| Sexe | Formule |
|---|---|
| Homme | `10 × poids(kg) + 6,25 × taille(cm) − 5 × âge + 5` |
| Femme | `10 × poids(kg) + 6,25 × taille(cm) − 5 × âge − 161` |

**Mineurs (12 à 17 ans)** — Schofield, mieux adapté à la croissance :

| Sexe | Formule |
|---|---|
| Garçon | `17,7 × poids(kg) + 657` |
| Fille | `13,4 × poids(kg) + 692` |

Le champ `besoins.profil_mineur` vaut alors `true` et la mention précise que les besoins varient avec
la croissance.

## 2. Dépense énergétique totale

`TDEE = métabolisme de base × facteur d'activité`

| Niveau | Facteur | Description affichée |
|---|---|---|
| `sedentaire` | 1,2 | Peu ou pas d'activité |
| `leger` | 1,375 | Activité légère 1 à 3 jours par semaine |
| `modere` | 1,55 | Activité modérée 3 à 5 jours |
| `eleve` | 1,725 | Activité soutenue 6 à 7 jours |
| `tres_eleve` | 1,9 | Travail physique ou double entraînement |

Le niveau décrit **l'activité quotidienne hors séances enregistrées dans Mavi'oh**, puisque ces
séances ajoutent déjà des calories au budget (voir `REGLES_SPORT.md`). Si l'utilisateur enregistre au
moins trois séances par semaine et choisit un niveau élevé ou très élevé, un avertissement le lui
signale.

## 3. Objectif et ajustement calorique

L'algorithme est appliqué dans cet ordre, chaque étape étant tracée dans `besoins.etapes[]`.

1. **Cohérence** (erreur 422 sinon) : `perdre` exige un poids souhaité inférieur au poids actuel,
   `prendre` un poids supérieur, `maintenir` ignore le poids souhaité et le délai.
2. **Demande** : `ajustement_demandé = |poids − poids_souhaité| × 7700 / jours_restants`
   (7 700 kcal par kilogramme de masse corporelle, valeur indicative).
   `jours_restants = max(28, date_de_fin − aujourd'hui)`.
3. **Bornes** :
   - perte : entre 250 et `min(1000 ; 1 % du poids par semaine)` kcal par jour,
     plafonné à 500 kcal au-delà de 65 ans ;
   - prise : entre 250 et 500 kcal par jour ;
   - maintien, mineur ou situation particulière : 0.
4. **Cible** = `TDEE + ajustement`.
5. **Plancher de sécurité** (adultes) : `max(1200 kcal pour une femme, 1500 kcal pour un homme, et le
   métabolisme de base en cas de perte)`. La cible est remontée si nécessaire, avec avertissement.
6. **Variation hebdomadaire** = `ajustement réellement appliqué × 7 / 7700`, jamais la valeur
   demandée. La cible est arrondie à 10 kcal près.

## 4. Macronutriments

`poids_référence = min(poids réel, poids correspondant à un IMC de 30)` : les règles en grammes par
kilogramme ne sur-prescrivent pas en cas d'obésité.

**Adultes**

| Macro | Règle | Bornes |
|---|---|---|
| Protéines | 1,6 g/kg (maintien), 1,8 (perte), 2,0 (prise ou objectif prise de muscle) | ≤ 35 % des calories ; ≥ 1,2 g/kg à partir de 65 ans |
| Lipides | `max(0,8 g/kg ; 20 % des calories)` | ≤ 40 % des calories |
| Glucides | le reste | ≥ 50 g (hors keto et low carb) |

**Mineurs** : protéines 1,0 g/kg, lipides 30 % des calories, glucides le reste, sans surcharge liée
au régime.

Si les bornes entrent en conflit, la résolution suit cet ordre : réduire les lipides à leur plancher
de 20 %, puis les protéines à 1,2 g/kg, puis remonter la cible calorique avec un avertissement.
Aucune macro n'est jamais négative, et la somme `4P + 9L + 4G` reste à moins de 3 % de la cible.

## 5. Adaptations par régime

Appliquées après le calcul des protéines :

| Régime | Effet sur les macros |
|---|---|
| `keto` | glucides à 25 g, lipides prennent le reste |
| `low_carb` | glucides plafonnés à 100 g, surplus vers les lipides |
| `mediterraneen`, `dash` | 50 % glucides, 30 % lipides, 20 % protéines, sauf si la règle en g/kg donne davantage de protéines |
| `montignac` | même répartition, plus un avertissement précisant que l'index glycémique n'est pas calculé |
| autres | aucune modification des macros, seules les exclusions d'aliments s'appliquent |

Les quatorze régimes gérés et leurs règles d'exclusion sont décrits dans `config/diets.php` et
exposés par `GET /api/diets`.

## 6. Garde-fous

| Situation | Comportement |
|---|---|
| IMC visé inférieur à 18,5 pour une perte | refus (422) avec renvoi vers un professionnel de santé |
| IMC actuel inférieur à 18,5 avec objectif de perte | même refus |
| IMC visé supérieur à 30 pour une prise | avertissement, pas de blocage |
| Moins de 18 ans | objectif forcé au maintien ; keto, low carb, jeûne intermittent et Montignac refusés |
| Moins de 15 ans | accord parental requis |
| Grossesse, allaitement, suivi médical | objectif forcé au maintien, pas de surcharge de macros liée au régime |
| Premier enregistrement du profil | consentement explicite au traitement des données de santé requis |

## 7. Cibles saisies manuellement

Quand l'utilisateur désactive le calcul automatique, ses valeurs sont contrôlées avec la même
enveloppe de sécurité : calories au-dessus du plancher et sous `TDEE + 1000`, protéines entre 0,8 et
2,2 g/kg, lipides au moins 20 % des calories, glucides au moins 50 g (25 g en keto), et cohérence de
la somme des macros à 10 % près. La source est indiquée dans `cibles_effectives.source`
(`calcul` ou `utilisateur`).

## 8. Suivi du poids et stabilité des objectifs

Les objectifs ne sont pas recalculés à chaque pesée : ils le sont uniquement si le poids s'écarte
d'au moins 1 kg du poids de référence, ou après 14 jours. Cela évite que les cibles oscillent avec
les variations quotidiennes.

## 9. Répartition du budget par repas

| Repas | Part du budget |
|---|---|
| Petit-déjeuner | 25 % |
| Déjeuner | 35 % |
| Dîner | 30 % |
| Collation | 10 % |

Le budget d'un repas tient compte de ce qui a déjà été enregistré dans la journée. En jeûne
intermittent, les repas hors de la fenêtre alimentaire reçoivent une part nulle et les autres sont
renormalisés.

## 10. Vecteurs de test de référence

Ces cas sont vérifiés automatiquement à chaque exécution de la suite de tests.

| Profil | Résultat attendu |
|---|---|
| Homme, 70 kg, 175 cm, 30 ans, activité modérée, perdre 5 kg en 90 jours | BMR 1648,75 · TDEE 2555,6 · cible 2130 kcal · −0,39 kg/semaine · P 126 g · L 56 g · G 280 g |
| Femme, 40 kg, 150 cm, sédentaire, perte | refus : IMC cible inférieur à 18,5 |
| Homme, 130 kg, 165 cm, perte | poids de référence 81,7 kg, déficit ramené à la borne, avertissement de délai |
| Adolescent de 15 ans, maintien | formule de Schofield, aucun déficit, mention spécifique aux mineurs |
