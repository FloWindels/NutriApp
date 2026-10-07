# Sport v2 — lieux, consignes précises, séance guidée

Refonte du module sport demandée le 7 octobre 2026 : « pour le matériel, pouvoir sélectionner
l'endroit où on est pour savoir quel matériel on a » ; « une séance cardio en vélo ne se fait pas
comme ça : que veut dire 10×30 1 min 52 ? Il faut donner des tr/min précis » ; « revois tout ».

Trois problèmes de fond, trois réponses :

| Problème | Réponse |
|---|---|
| Une seule liste de matériel, la même partout, et des types flous (« vélo », « tapis ») | **Lieux d'entraînement** : chacun a son matériel ; la séance se génère pour un lieu |
| Un exercice n'a que séries / répétitions / durée / repos : impossible de dire « 100–110 tr/min, résistance 7/10, zone 4 » | **Prescription structurée** par exercice, une **phrase lisible** calculée par le serveur, **zones cardiaques personnalisées** (âge) |
| La séance est une liste à lire | **Étapes minutées** calculées par le serveur → **séance guidée** (minuteur, cible en cours, étape suivante) sur le site et l'app |

Le contrat ci-dessous est ADDITIF : aucune clé existante ne disparaît (applications installées).

---

## 1. Lieux d'entraînement

Table `sport_lieux` : `id`, `user_id`, `nom` (≤ 60), `type` (`maison` | `exterieur` |
`salle_publique` | `salle_privee`, enum `Lieu` existant), `materiel` (json, valeurs de l'enum
`Equipment`), `par_defaut` (bool, un seul par compte), timestamps.

- `GET /api/sport/lieux` → `{data: [Lieu]}` (par défaut en premier, puis par nom).
- `POST /api/sport/lieux` `{nom, type, materiel: string[], par_defaut?: bool}` → 201 `{message, data: Lieu}`.
- `PUT /api/sport/lieux/{id}` mêmes champs → `{message, data: Lieu}`.
- `DELETE /api/sport/lieux/{id}` → `{message}` ; si c'était le lieu par défaut, le plus ancien restant le devient.
- 404 opaque pour le lieu d'un autre compte. Pas d'offre requise (le sport de base est gratuit).

`Lieu` = `{id, nom, type, type_libelle, materiel: string[], par_defaut: bool}`.

Reprise de l'existant : une migration crée, pour chaque profil ayant `sport_lieu` ou
`sport_materiel`, un lieu par défaut (nom = libellé du type, matériel = `sport_materiel` ou le
préréglage du type). `profile.sport_lieu` / `sport_materiel` restent lus pour la compatibilité.

`GET /api/sport/config` ajoute :
- `materiel_catalogue`: `[{valeur, libelle, categorie: "cardio" | "musculation" | "accessoire" | "aucun"}]`
- `materiel_par_type`: `{maison: [...], exterieur: [...], salle_publique: [...], salle_privee: [...]}` (préréglages proposés à la création d'un lieu, modifiables)

### Matériel (enum `Equipment`, valeurs existantes conservées)

| Valeur | Libellé | Catégorie |
|---|---|---|
| `aucun` | Poids du corps | aucun |
| `tapis` | Tapis de sol | accessoire |
| `elastiques` | Élastiques | accessoire |
| `corde_a_sauter` | Corde à sauter | cardio |
| `trx` | Sangles de suspension (TRX) | accessoire |
| `swiss_ball` | Swiss ball | accessoire |
| `medecine_ball` | Médecine-ball | accessoire |
| `step` | Step / box | accessoire |
| `halteres` | Haltères | musculation |
| `kettlebell` | Kettlebell | musculation |
| `barre` | Barre et disques | musculation |
| `banc` | Banc | musculation |
| `barre_traction` | Barre de traction | musculation |
| `machine` | Machines guidées | musculation |
| `poulie` | Poulie / vis-à-vis | musculation |
| `velo` | Vélo d'appartement / home trainer | cardio |
| `velo_route` | Vélo (route, VTT) | cardio |
| `tapis_course` | Tapis de course | cardio |
| `rameur` | Rameur | cardio |
| `elliptique` | Vélo elliptique | cardio |
| `piscine` | Piscine | cardio |

Préréglages : `maison` = aucun, tapis ; `exterieur` = aucun ; `salle_publique` = tout sauf
`velo_route` et `piscine` ; `salle_privee` = tout sauf `velo_route`.

## 2. Génération pour un lieu

`POST /api/sport/sessions/generate` accepte `lieu_id` (nullable). Quand il est fourni, le type de
lieu et le matériel viennent du lieu (ils l'emportent sur `lieu` / `equipment`). Sans `lieu_id` :
lieu par défaut du compte, sinon comportement actuel. `sport_plans` et `workout_sessions` gagnent
`lieu_id` (nullable) ; les ressources exposent `lieu_id` et `lieu_nom`.

Cardio impossible avec le matériel du lieu (vélo demandé, ni `velo` ni `velo_route`, ou piscine
absente) : la séance propose l'alternative la plus proche disponible (elliptique, rameur, course,
corde, circuit au poids du corps) et l'explique dans `warnings`.

## 3. Prescription structurée

Chaque exercice (proposition, séance enregistrée, `WorkoutExerciseResource`) gagne :

```json
"prescription": {
  "type": "force | cardio_continu | intervalles | mobilite",
  "repetitions": 10,            // intervalles : nombre de répétitions
  "effort_sec": 30,             // intervalles : durée d'un effort ; continu : durée totale
  "recup_sec": 90,              // intervalles : récupération entre deux efforts
  "cible": {                    // pendant l'effort ; chaque clé est facultative
    "cadence_rpm": [100, 110],  // vélo, elliptique
    "resistance": [7, 8],       // échelle 1–10 (vélo, elliptique, rameur)
    "puissance_w": null,        // si connue
    "vitesse_kmh": null,        // marche, tapis de course
    "allure_min_km": null,      // course : [5.5, 6.0] = 5:30–6:00 /km
    "inclinaison_pct": null,    // tapis de course
    "cadence_coups_min": null,  // rameur
    "allure_500m_sec": null,    // rameur : temps au 500 m
    "zone_fc": 4,               // 1–5
    "fc_bpm": [150, 167],       // CALCULÉ par le serveur depuis zone_fc et l'âge
    "rpe": [8, 9]               // ressenti 1–10
  },
  "recup_cible": { "cadence_rpm": [80, 85], "resistance": [3, 3], "zone_fc": 2, "fc_bpm": [..] },
  "rir": 2,                     // force : répétitions gardées en réserve
  "tempo": "3-1-1",             // force : descente-pause-montée (s)
  "charge_conseil": "Une charge que tu pourrais soulever 12 fois ; arrête-toi à 10.",
  "consigne": "Phrase courte et concrète : ce que la personne doit ressentir/faire."
}
"resume": "10 × 30 s à 100–110 tr/min · résistance 7–8/10 · zone 4 (150–167 bpm) — récup 1 min 30 à 80–85 tr/min, résistance 3/10",
"etapes": [ {"label": "Effort 1/10", "type": "effort", "duree_sec": 30, "cible": "100–110 tr/min · résistance 7–8/10 · 150–167 bpm"}, ... ]
```

- `resume` et `etapes` sont CALCULÉS par le serveur depuis `prescription` (une seule source de
  vérité, les deux clients affichent la même chose). `etapes.type` : `effort` | `recup` | `travail`
  (série de force) | `repos`. Une série de force sans durée donne une étape `travail` sans
  `duree_sec` (null) : le minuteur attend que la personne valide.
- Colonne `workout_exercises.prescription` (json, nullable). Les anciennes lignes sans
  prescription gardent `resume` construit depuis séries/répétitions/durée/repos — lisible, jamais
  « 10×30 1 min 52 » : « 10 séries de 30 s, 1 min 52 de repos ».
- Zones (FCmax = 208 − 0,7 × âge, Tanaka) : Z1 50–60 %, Z2 60–70 %, Z3 70–80 %, Z4 80–90 %, Z5
  90–100 %. Sans âge connu : pas de `fc_bpm`, la zone et le RPE suffisent.

## 4. Générateur par règles (expert)

Les séances cardio (`velo`, `course_a_pied`, `rameur`, `natation`, `marche`, `hiit`, elliptique) se
construisent depuis des **modèles d'entraînement** reconnus, choisis selon objectif × niveau ×
durée, même en mode IA (fiables et précis) :

- **Vélo** — échauffement 8–10 min Z1→Z2 85–90 tr/min résistance 3–4 ; endurance Z2 85–95 tr/min ;
  tempo/seuil (ex. 3 × 8 min Z4 90–95 tr/min, récup 4 min Z1) ; fractionné court (ex. 10 × 30 s
  Z5 100–110 tr/min résistance 7–8, récup 1 min 30 à 80 tr/min résistance 3) ; force-vélo (5 × 3 min
  60–70 tr/min résistance 8) ; retour au calme 5 min.
- **Course** — allure conversationnelle Z2, seuil Z4, fractionné 30/30 Z5, côtes ; allures en
  min/km quand le profil le permet, sinon zones + RPE + test de la parole.
- **Rameur** — 22–24 coups/min en endurance, 28–32 en fractionné ; temps au 500 m par niveau.
- **Elliptique, marche, tapis de course** — vitesse/inclinaison/résistance chiffrées.
- **Natation** — distances (ex. 8 × 50 m), récup en secondes, RPE.
- **Force** — séries × répétitions selon objectif (force 4–6, hypertrophie 8–12, endurance 15–20),
  RIR par niveau (débutant 3, intermédiaire 2, avancé 1), tempo, repos, conseil de charge.

## 5. IA

L'IA garde la main sur le CHOIX des exercices de musculation/HIIT/mobilité et leur volume ; le
schéma compact ajoute pour chaque exercice `rir`, `tempo` et `consigne` (courte). Le serveur
complète la prescription (règles) et calcule `resume` / `etapes`. Les séances cardio passent par
les modèles experts du § 4 ; l'IA peut en choisir la variante et la durée.

## 6. Clients (site et application)

- **Mes lieux** (Sport › Préférences, et depuis le formulaire de génération) : créer, renommer,
  type, matériel par catégories avec le préréglage du type, lieu par défaut.
- **Générer une séance** : choix du lieu (puces, matériel visible), sport, objectif, durée, niveau,
  zones à éviter, notes.
- **Détail d'une séance** : blocs échauffement / principal / retour au calme ; chaque exercice
  montre `resume` en clair et ses cibles en pastilles (tr/min, résistance, zone + bpm, allure, RPE,
  RIR, tempo), la consigne, la figure d'exercice existante.
- **Séance guidée** (bouton « Démarrer ») : plein écran, étape en cours en grand, compte à rebours,
  cible en cours (ex. « 100–110 tr/min · 150–167 bpm »), étape suivante, pause / passer / précédent,
  signal sonore ou vibration aux changements, progression ; à la fin, « Terminer » enregistre la
  séance comme réalisée (endpoint `complete` existant) avec RPE ressenti.
