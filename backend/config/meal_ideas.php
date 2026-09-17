<?php

/*
|--------------------------------------------------------------------------
| Idées de repas simples (repli du planificateur, brief §12)
|--------------------------------------------------------------------------
|
| 24 idées avec valeurs indicatives par portion. Utilisées par PlannerGenerator
| quand aucune recette compatible n'est trouvée : créées en plans « titre seul ».
| tags ⊂ vocabulaire recettes (§5) ; meal_types ⊂ MealType.
|
*/

return [
    // --- Petit-déjeuner -------------------------------------------------------------------
    [
        'title' => 'Porridge aux flocons d’avoine, banane et amandes',
        'calories' => 420, 'proteins' => 14, 'carbs' => 62, 'fat' => 12,
        'tags' => ['vegetarien', 'rapide', 'economique'],
        'meal_types' => ['petit_dejeuner'],
    ],
    [
        'title' => 'Tartines de pain complet, fromage blanc et fruits rouges',
        'calories' => 360, 'proteins' => 18, 'carbs' => 50, 'fat' => 8,
        'tags' => ['vegetarien', 'rapide', 'riche_en_proteines'],
        'meal_types' => ['petit_dejeuner'],
    ],
    [
        'title' => 'Œufs brouillés, pain complet et tomate',
        'calories' => 390, 'proteins' => 22, 'carbs' => 30, 'fat' => 18,
        'tags' => ['vegetarien', 'rapide', 'riche_en_proteines', 'sans_lactose'],
        'meal_types' => ['petit_dejeuner'],
    ],
    [
        'title' => 'Yaourt nature, muesli et pomme',
        'calories' => 340, 'proteins' => 13, 'carbs' => 55, 'fat' => 8,
        'tags' => ['vegetarien', 'rapide', 'economique'],
        'meal_types' => ['petit_dejeuner', 'collation'],
    ],
    [
        'title' => 'Omelette aux épinards et feta',
        'calories' => 380, 'proteins' => 24, 'carbs' => 6, 'fat' => 28,
        'tags' => ['vegetarien', 'keto', 'low_carb', 'sans_gluten', 'rapide'],
        'meal_types' => ['petit_dejeuner', 'diner'],
    ],
    [
        'title' => 'Smoothie banane, lait végétal et beurre de cacahuète',
        'calories' => 350, 'proteins' => 12, 'carbs' => 45, 'fat' => 14,
        'tags' => ['vegan', 'sans_lactose', 'rapide'],
        'meal_types' => ['petit_dejeuner', 'collation'],
    ],

    // --- Déjeuner --------------------------------------------------------------------------
    [
        'title' => 'Poulet grillé, riz complet et brocolis',
        'calories' => 560, 'proteins' => 42, 'carbs' => 58, 'fat' => 14,
        'tags' => ['riche_en_proteines', 'sans_gluten', 'sans_lactose', 'halal'],
        'meal_types' => ['dejeuner', 'diner'],
    ],
    [
        'title' => 'Salade de lentilles, œuf mollet et légumes croquants',
        'calories' => 480, 'proteins' => 26, 'carbs' => 52, 'fat' => 16,
        'tags' => ['vegetarien', 'economique', 'mediterraneen', 'sans_gluten'],
        'meal_types' => ['dejeuner', 'diner'],
    ],
    [
        'title' => 'Pâtes complètes à la sauce tomate et thon',
        'calories' => 540, 'proteins' => 32, 'carbs' => 70, 'fat' => 12,
        'tags' => ['rapide', 'economique', 'mediterraneen', 'riche_en_proteines'],
        'meal_types' => ['dejeuner', 'diner'],
    ],
    [
        'title' => 'Bowl de quinoa, pois chiches rôtis et avocat',
        'calories' => 590, 'proteins' => 20, 'carbs' => 66, 'fat' => 24,
        'tags' => ['vegan', 'sans_gluten', 'sans_lactose', 'mediterraneen'],
        'meal_types' => ['dejeuner', 'diner'],
    ],
    [
        'title' => 'Saumon au four, pommes de terre vapeur et haricots verts',
        'calories' => 580, 'proteins' => 36, 'carbs' => 45, 'fat' => 26,
        'tags' => ['mediterraneen', 'dash', 'sans_gluten', 'sans_lactose', 'riche_en_proteines'],
        'meal_types' => ['dejeuner', 'diner'],
    ],
    [
        'title' => 'Wrap de dinde, crudités et fromage frais',
        'calories' => 470, 'proteins' => 30, 'carbs' => 44, 'fat' => 16,
        'tags' => ['rapide', 'riche_en_proteines', 'halal'],
        'meal_types' => ['dejeuner'],
    ],
    [
        'title' => 'Chili végétarien aux haricots rouges et riz',
        'calories' => 520, 'proteins' => 22, 'carbs' => 80, 'fat' => 10,
        'tags' => ['vegan', 'economique', 'sans_gluten', 'sans_lactose'],
        'meal_types' => ['dejeuner', 'diner'],
    ],
    [
        'title' => 'Steak haché 5 %, purée de patate douce et salade verte',
        'calories' => 550, 'proteins' => 38, 'carbs' => 48, 'fat' => 18,
        'tags' => ['riche_en_proteines', 'sans_gluten', 'halal'],
        'meal_types' => ['dejeuner', 'diner'],
    ],

    // --- Dîner -----------------------------------------------------------------------------
    [
        'title' => 'Soupe de légumes maison et tartine de chèvre',
        'calories' => 380, 'proteins' => 14, 'carbs' => 42, 'fat' => 16,
        'tags' => ['vegetarien', 'economique', 'dash'],
        'meal_types' => ['diner'],
    ],
    [
        'title' => 'Poêlée de tofu, légumes sautés et nouilles de riz',
        'calories' => 500, 'proteins' => 24, 'carbs' => 60, 'fat' => 16,
        'tags' => ['vegan', 'sans_lactose', 'sans_gluten', 'rapide'],
        'meal_types' => ['diner', 'dejeuner'],
    ],
    [
        'title' => 'Cabillaud en papillote, courgettes et boulgour',
        'calories' => 450, 'proteins' => 34, 'carbs' => 46, 'fat' => 10,
        'tags' => ['mediterraneen', 'dash', 'sans_lactose', 'riche_en_proteines'],
        'meal_types' => ['diner', 'dejeuner'],
    ],
    [
        'title' => 'Ratatouille et œufs au plat',
        'calories' => 400, 'proteins' => 18, 'carbs' => 24, 'fat' => 24,
        'tags' => ['vegetarien', 'mediterraneen', 'low_carb', 'sans_gluten', 'sans_lactose'],
        'meal_types' => ['diner'],
    ],
    [
        'title' => 'Salade César légère au poulet',
        'calories' => 430, 'proteins' => 34, 'carbs' => 18, 'fat' => 24,
        'tags' => ['low_carb', 'riche_en_proteines', 'rapide', 'halal'],
        'meal_types' => ['diner', 'dejeuner'],
    ],
    [
        'title' => 'Dahl de lentilles corail au lait de coco et riz basmati',
        'calories' => 520, 'proteins' => 20, 'carbs' => 74, 'fat' => 14,
        'tags' => ['vegan', 'sans_gluten', 'sans_lactose', 'economique'],
        'meal_types' => ['diner', 'dejeuner'],
    ],

    // --- Collation -------------------------------------------------------------------------
    [
        'title' => 'Pomme et poignée d’amandes',
        'calories' => 240, 'proteins' => 6, 'carbs' => 28, 'fat' => 14,
        'tags' => ['vegan', 'sans_gluten', 'sans_lactose', 'rapide', 'economique'],
        'meal_types' => ['collation'],
    ],
    [
        'title' => 'Fromage blanc, miel et noix',
        'calories' => 250, 'proteins' => 16, 'carbs' => 22, 'fat' => 10,
        'tags' => ['vegetarien', 'riche_en_proteines', 'rapide'],
        'meal_types' => ['collation'],
    ],
    [
        'title' => 'Houmous et bâtonnets de carotte',
        'calories' => 220, 'proteins' => 8, 'carbs' => 24, 'fat' => 10,
        'tags' => ['vegan', 'sans_gluten', 'sans_lactose', 'mediterraneen', 'rapide'],
        'meal_types' => ['collation'],
    ],
    [
        'title' => 'Banane et carré de chocolat noir',
        'calories' => 180, 'proteins' => 3, 'carbs' => 32, 'fat' => 5,
        'tags' => ['vegan', 'sans_gluten', 'sans_lactose', 'rapide', 'economique'],
        'meal_types' => ['collation'],
    ],
];
