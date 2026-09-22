# Règles du module sport de Mavi'oh

Le module sport couvre quatre besoins : planifier ses jours d'entraînement, enregistrer ce qui a été
fait, convertir l'effort en calories réutilisables dans le budget du jour, et proposer des séances
adaptées à la personne.

Les calculs sont faits côté serveur par `App\Services\Sport\*` et `App\Services\SportNutrition`.

> **Avertissement produit.** Les séances proposées sont indicatives et ne remplacent ni un coach ni un
> avis médical. Chaque proposition se termine par la phrase : « Arrête l'exercice en cas de douleur ou
> de malaise. »

---

## 1. Catalogue de sports

Plus de quarante sports sont fournis (course à pied, marche, randonnée, trail, vélo, VTT, natation,
aquagym, musculation, crossfit, HIIT, corde à sauter, rameur, elliptique, yoga, pilates, football,
basketball, handball, volley, rugby, tennis, padel, badminton, squash, escalade, boxe, arts martiaux,
danse, ski, roller, surf, golf, équitation, kayak…). Chacun porte trois valeurs MET, pour une
intensité faible, modérée ou élevée.

Si un sport manque, l'utilisateur le crée lui-même : nom, catégorie et, facultativement, une valeur
MET. Sans valeur fournie, Mavi'oh applique la valeur par défaut de la catégorie et en déduit les
intensités faible et élevée. Un sport personnel n'est visible que par son auteur, et sa suppression
est refusée tant qu'il est utilisé dans le calendrier ou une séance.

## 2. Calendrier

L'utilisateur planifie un sport pour une date, avec une durée prévue, éventuellement une heure, un
lieu et une note. La planification peut être répétée chaque semaine sur une à douze semaines : les
entrées créées partagent un identifiant de série, et leur suppression propose de supprimer toute la
série à partir de la date choisie.

Un plan passe à l'état « réalisé » lorsqu'il est enregistré comme séance faite, et garde un lien vers
la séance correspondante.

## 3. Calories dépensées

La formule retient l'énergie **nette**, c'est-à-dire l'effort au-delà du métabolisme de repos déjà
compté dans la dépense quotidienne :

```
calories = (MET − 1) × poids(kg) × durée(heures)
```

- **Activité libre** : MET du sport selon l'intensité choisie (modérée par défaut).
- **Séance structurée** : MET moyen pondéré par la durée estimée de chaque exercice, avec des valeurs
  par défaut de 3,5 à 6,0 pour la musculation selon le niveau, 7,0 en cardio, 3,0 en gainage et 2,5 en
  mobilité.
- **Effort ressenti** : un RPE déclaré module le résultat de −15 % (RPE ≤ 4) à +15 % (RPE ≥ 8).
- **Poids utilisé** : la pesée la plus récente antérieure ou égale à la date, sinon le poids du profil,
  sinon 70 kg.

Les valeurs MET indicatives pour les activités libres :

| Activité | Faible | Modérée | Élevée |
|---|---|---|---|
| Marche | 3,0 | 3,5 | 4,3 |
| Course à pied | 8,0 | 9,8 | 11,5 |
| Vélo | 5,8 | 7,5 | 10,0 |
| Natation | 6,0 | 8,0 | 10,0 |
| Autre | 4,0 | 6,0 | 8,0 |

**Saisie manuelle.** L'utilisateur peut remplacer la valeur calculée par la sienne : la séance est
alors marquée `calories_source = manuel` et la valeur n'est plus recalculée. Effacer le champ rétablit
le calcul automatique.

## 4. Report sur le budget alimentaire

Les calories brûlées sont des calories **consommables en plus** :

```
bonus = arrondi( min(somme des calories des séances terminées du jour ; 1500) × coefficient / 100 )
budget restant = cible + bonus − consommé
```

Le coefficient est réglable par l'utilisateur dans son profil : 100 % par défaut, 75 % ou 50 % pour
les personnes qui préfèrent garder une marge, les estimations MET étant imprécises. Le plafond de
1 500 kcal évite qu'une saisie erronée ne double le budget de la journée.

Seules les séances **terminées** et datées du jour comptent ; une séance prévue ne rapporte rien.
L'explication affichée à l'utilisateur détaille le calcul, et les valeurs issues d'une estimation MET
portent le drapeau `is_estimate`.

## 5. Génération d'une séance par les règles

Utilisée quand l'IA n'est pas configurée, indisponible ou refusée par l'utilisateur. Le résultat est
déterministe pour une même demande et une même graine.

**Séries et répétitions selon le niveau**

| Niveau | Séries | Répétitions | Repos | Fractionné cardio |
|---|---|---|---|---|
| Débutant | 2 | 10 à 12 | 90 s | 20 s effort / 40 s récupération |
| Intermédiaire | 3 | 8 à 12 | 60 s | 30 s / 30 s |
| Avancé | 4 | 6 à 10 | 60 à 90 s | 40 s / 20 s |

**Composition du bloc principal selon l'objectif**

| Objectif | Force | Cardio |
|---|---|---|
| Perte de gras | 50 % | 50 % |
| Prise de muscle | 80 % | 20 % |
| Endurance | 30 % | 70 % |
| Forme | 60 % | 40 % + 1 mobilité |
| Force | 100 % en 4 à 6 répétitions (niveau avancé uniquement) | — |

**Structure** : échauffement de 5 à 8 minutes, circuit principal, retour au calme de 5 minutes. Le
nombre d'exercices découle de la durée disponible, entre 3 et 8.

**Contraintes respectées** : matériel strictement disponible (« aucun » toujours permis), lieu
d'entraînement, focus demandé, jamais deux exercices consécutifs sur le même groupe musculaire, pas
d'exercice de niveau avancé ni de barre lourde pour un débutant, et surtout **aucun exercice
sollicitant une zone déclarée douloureuse** (genoux, dos, épaules, poignets, hanches, cou, chevilles,
coudes) grâce aux contre-indications portées par chaque exercice du catalogue.

Pour un sport d'endurance (course, vélo, natation, marche, rameur), le bloc principal est un
fractionné ou un effort continu avec durées et allures plutôt qu'une liste d'exercices.

## 6. Coach sportif par IA

Deux fournisseurs sont pris en charge, au choix via `LLM_PROVIDER` :

| Valeur | Effet |
|---|---|
| `auto` (défaut) | Anthropic si `ANTHROPIC_API_KEY` est renseignée, sinon Ollama s'il est activé, sinon les règles |
| `anthropic` | API Claude |
| `ollama` | modèle exécuté en local, aucune donnée ne quitte la machine, aucun coût par appel |
| `none` | IA désactivée, seules les règles Mavi'oh s'appliquent |

Pour Ollama il suffit d'un service en fonctionnement et d'un modèle installé :

```bash
ollama pull llama3.2
```

puis, dans `backend/.env` : `OLLAMA_ENABLED=true` et `OLLAMA_MODEL=llama3.2`.

Mesures faites sur une machine de développement avec le catalogue d'exercices filtré par
matériel, comme en production :

| Modèle | Temps de réponse | Séance conforme |
|---|---|---|
| llama3.2 (3 milliards de paramètres) | 3 à 10 s | oui, zones douloureuses respectées |
| qwen3-coder (30 milliards) | 87 s | oui |

Le petit modèle suffit et reste bien plus confortable à l'usage. Quel que soit le fournisseur,
le modèle reçoit un contexte **anonymisé** (aucun nom ni e-mail) :

- profil : âge, sexe, poids, taille, IMC, niveau d'activité, objectif, calories cibles, régime,
  profil mineur, situation particulière ;
- sport : niveau, objectif sportif, matériel, lieu, zones à éviter, focus, notes libres, jours par
  semaine, temps disponible ;
- historique des quatorze derniers jours (sport, durée, effort ressenti, calories) ;
- plan du jour éventuel ;
- catalogue d'exercices compatibles (60 au maximum) avec leurs identifiants.

Le modèle répond selon un schéma JSON strict. La réponse est ensuite **vérifiée par le serveur** :
structure et durées contrôlées, exercices rattachés au catalogue, exercices contre-indiqués retirés
avec un avertissement, calories recalculées si elles sont absentes ou aberrantes.

**Repli systématique.** Fournisseur non configuré, service arrêté, quota dépassé, panne réseau,
refus du modèle ou JSON illisible :
la séance est produite par les règles, avec le message « Génération IA indisponible : séance proposée
par les règles Mavi'oh. » L'IA n'est jamais indispensable au fonctionnement du produit.

La proposition indique toujours son origine : « Proposée par l'IA » avec le nom du modèle, ou
« Règles Mavi'oh ».

## 7. Déroulé d'une séance

Proposition → démarrage (la séance passe en cours, l'heure de départ est enregistrée) → validation
des exercices un à un, avec possibilité d'ajuster répétitions et charges → fin de séance avec durée
réelle et effort ressenti. Le résultat affiche les calories dépensées et le bonus ajouté au budget du
jour, suivis du conseil de récupération.

Une séance interrompue reste « en cours » et peut être reprise : le chronomètre se recalcule à partir
de l'heure de départ, même après une fermeture de l'application.

## 8. Conseils autour de l'entraînement

- **Avant** (séance prévue avec une heure, d'au moins 30 minutes, entre 3 h et 1 h avant) : viser
  environ 1 g de glucides par kilogramme (0,5 g pour une séance courte). En keto ou low carb, une
  collation légère protéinée ou lipidique. En jeûne intermittent hors fenêtre, hydratation et
  collation reportée au début de la fenêtre.
- **Après** (dans les deux heures suivant la fin, si moins de 20 g de protéines ont été enregistrés) :
  viser environ 0,3 g de protéines par kilogramme, entre 20 et 40 g, avec des aliments du stock en
  priorité.

Aucun conseil n'emploie de vocabulaire promettant un résultat médical ou miraculeux ; une vérification
automatique le garantit à chaque exécution des tests.

## 9. Série et régularité

La série (`streak_days`) compte les jours consécutifs, en terminant aujourd'hui ou hier, comportant au
moins une séance terminée ou une activité d'au moins dix minutes.
