<?php

/*
|--------------------------------------------------------------------------
| Catalogue des régimes reconnus (brief §9)
|--------------------------------------------------------------------------
|
| 14 clés : omnivore, mediterraneen, dash, flexitarien, low_carb, keto, jeune_intermittent,
| vegetarien, vegan, sans_gluten, sans_lactose, montignac, halal, autre.
|
| Chaque entrée : {nom, description, principes[], regles{…}, aliments_conseilles[],
| aliments_a_limiter[], conseils[], mineurs_autorise}.
|
| Schéma de `regles` (toutes les clés sont optionnelles) :
|   exclure_categories[]            catégories libres (français, pliées) cherchées dans food.category
|   exclure_categories_tags[]       tags catégories Open Food Facts (en:meats…) — food.category
|   exclure_allergenes_tags[]       tags allergènes Open Food Facts (en:milk…) — food.allergens
|   exclure_mots_cles[]             mots-clés minuscules sans accent, comparés mot à mot sur
|                                   food.name / recipe.title / ingrédients / libellés d'items
|   exceptions_mots_cles[]          expressions qui neutralisent un mot-clé (« lait de coco »)
|   frequence_max_par_semaine{}     {categorie: nombre de repas max par semaine}
|   glucides_max_g / glucides_min_g grammes par jour
|   glucides_pct / lipides_pct / proteines_pct   [min, max] en % des calories du jour
|   sucres_pct_max                  % des calories issues des sucres (max)
|   sel_max_g_jour                  grammes de sel par jour (max)
|   fibres_min_g_jour               grammes de fibres par jour (min)
|   fenetre_alimentaire{debut,fin}  fenêtre horaire des repas (HH:MM) — lue par MealBudget
|   portions_recommandees{}         repères indicatifs (texte libre)
|
| ATTENTION : MealBudget lit config('diets.jeune_intermittent.regles.fenetre_alimentaire')
| avec la forme {debut, fin} — ne pas la renommer.
|
*/

$viandes = ['viande', 'poulet', 'boeuf', 'porc', 'veau', 'agneau', 'mouton', 'dinde', 'canard', 'lapin',
    'jambon', 'lardon', 'lardons', 'bacon', 'saucisse', 'saucisson', 'chorizo', 'charcuterie', 'steak', 'escalope',
    'merguez', 'kebab', 'foie gras', 'gelatine'];
$poissons = ['poisson', 'saumon', 'thon', 'cabillaud', 'morue', 'sardine', 'maquereau', 'truite', 'dorade', 'bar',
    'colin', 'merlu', 'sole', 'hareng', 'anchois', 'crevette', 'crevettes', 'moule', 'moules', 'huitre', 'crabe',
    'homard', 'calamar', 'poulpe', 'fruits de mer', 'surimi'];
$laitages = ['lait', 'lactose', 'creme', 'creme fraiche', 'beurre', 'fromage', 'yaourt', 'yogourt', 'fromage blanc',
    'petit suisse', 'skyr', 'kefir', 'mascarpone', 'ricotta', 'mozzarella', 'parmesan', 'emmental', 'comte',
    'camembert', 'chevre', 'feta', 'brie', 'roquefort', 'gruyere', 'raclette', 'lait concentre', 'creme glacee'];
$exceptionsVegetales = ['lait de coco', 'lait d amande', 'lait d avoine', 'lait de soja', 'lait de riz',
    'lait vegetal', 'boisson vegetale', 'creme de coco', 'creme de soja', 'creme d avoine', 'beurre de cacahuete',
    'beurre d arachide', 'beurre d amande', 'beurre de karite', 'fromage vegetal', 'yaourt vegetal',
    'yaourt de soja', 'yaourt au soja', 'oeuf de lin', 'steak vegetal', 'steak de soja', 'saucisse vegetale',
    'saucisse vegetarienne', 'lait de chevre', 'sans lactose', 'vegan', 'vegetalien'];

return [

    'omnivore' => [
        'nom' => 'Omnivore',
        'description' => 'Alimentation variée sans exclusion : tous les groupes d’aliments sont présents, l’équilibre se fait sur la semaine.',
        'principes' => [
            'Varier les sources de protéines (animales et végétales).',
            'Des légumes et des fruits à chaque repas.',
            'Privilégier les céréales complètes et les matières grasses de qualité.',
        ],
        'regles' => [],
        'aliments_conseilles' => ['Légumes de saison', 'Fruits frais', 'Céréales complètes', 'Légumineuses', 'Poisson', 'Volaille', 'Huile d’olive'],
        'aliments_a_limiter' => ['Produits ultra-transformés', 'Charcuterie', 'Boissons sucrées', 'Plats très salés'],
        'conseils' => [
            'Aucune règle stricte : Mavi’oh suit surtout tes cibles caloriques et tes macros.',
            'Pense à la diversité : au moins cinq couleurs de légumes dans la semaine.',
        ],
        'mineurs_autorise' => true,
    ],

    'mediterraneen' => [
        'nom' => 'Méditerranéen',
        'description' => 'Beaucoup de végétaux, d’huile d’olive, de légumineuses et de poisson ; peu de viande rouge et de produits sucrés.',
        'principes' => [
            'Huile d’olive comme matière grasse principale.',
            'Poisson et fruits de mer plusieurs fois par semaine, viande rouge au plus une fois.',
            'Légumineuses, céréales complètes, noix et graines au quotidien.',
        ],
        'regles' => [
            'frequence_max_par_semaine' => ['viande_rouge' => 1],
            'glucides_pct' => [45, 55],
            'lipides_pct' => [30, 40],
            'fibres_min_g_jour' => 25,
            'portions_recommandees' => [
                'legumes' => 'au moins 3 portions par jour',
                'fruits' => '2 à 3 portions par jour',
                'huile_olive' => '2 à 4 cuillères à soupe par jour',
                'poisson' => '2 à 3 fois par semaine',
            ],
        ],
        'aliments_conseilles' => ['Huile d’olive', 'Légumes', 'Fruits', 'Légumineuses', 'Poisson gras', 'Noix et amandes', 'Pain complet', 'Herbes aromatiques'],
        'aliments_a_limiter' => ['Viande rouge', 'Charcuterie', 'Beurre et crème', 'Pâtisseries', 'Sodas'],
        'conseils' => [
            'Remplace le beurre par l’huile d’olive dans tes cuissons et assaisonnements.',
            'Une poignée de noix ou d’amandes fait une excellente collation.',
            'Vise environ 25 g de fibres par jour : légumineuses et légumes sont tes alliés.',
        ],
        'mineurs_autorise' => true,
    ],

    'dash' => [
        'nom' => 'DASH',
        'description' => 'Approche riche en fruits, légumes, produits laitiers allégés et céréales complètes, avec un apport en sel limité.',
        'principes' => [
            'Limiter le sel à environ 5,75 g par jour (2 300 mg de sodium).',
            'Beaucoup de fruits et de légumes, sources de potassium.',
            'Peu de sucres ajoutés et de graisses saturées.',
        ],
        'regles' => [
            'sel_max_g_jour' => 5.75,
            'sucres_pct_max' => 10,
            'fibres_min_g_jour' => 25,
            'glucides_pct' => [50, 60],
            'portions_recommandees' => [
                'legumes' => '4 à 5 portions par jour',
                'fruits' => '4 à 5 portions par jour',
                'laitages_allegés' => '2 à 3 portions par jour',
            ],
        ],
        'aliments_conseilles' => ['Légumes', 'Fruits', 'Produits laitiers allégés', 'Céréales complètes', 'Volaille', 'Poisson', 'Noix', 'Légumineuses'],
        'aliments_a_limiter' => ['Sel de table', 'Plats préparés salés', 'Charcuterie', 'Sucreries', 'Boissons sucrées', 'Viande rouge'],
        'conseils' => [
            'Goûte avant de saler : les épices et les herbes remplacent très bien le sel.',
            'Lis les étiquettes : le sel se cache dans le pain, les sauces et les plats préparés.',
            'Le sel des aliments n’est pas toujours renseigné : l’évaluation est indicative.',
        ],
        'mineurs_autorise' => true,
    ],

    'flexitarien' => [
        'nom' => 'Flexitarien',
        'description' => 'Majoritairement végétarien, avec de la viande et du poisson de façon occasionnelle et choisie.',
        'principes' => [
            'Des repas végétariens la plupart du temps.',
            'Viande au plus deux repas par semaine, poisson au plus trois.',
            'Légumineuses et céréales complètes comme base des repas.',
        ],
        'regles' => [
            'frequence_max_par_semaine' => ['viande' => 2, 'poisson' => 3],
        ],
        'aliments_conseilles' => ['Légumineuses', 'Tofu et tempeh', 'Œufs', 'Céréales complètes', 'Légumes', 'Fruits', 'Noix et graines'],
        'aliments_a_limiter' => ['Viande rouge', 'Charcuterie', 'Plats industriels'],
        'conseils' => [
            'Prévois tes repas carnés dans le planificateur pour rester dans la fréquence visée.',
            'Associe céréales et légumineuses pour des protéines complètes.',
        ],
        'mineurs_autorise' => true,
    ],

    'low_carb' => [
        'nom' => 'Low carb',
        'description' => 'Apport en glucides réduit (moins de 100 g par jour), compensé par des protéines et des lipides de qualité.',
        'principes' => [
            'Moins de 100 g de glucides par jour.',
            'Légumes non féculents à volonté.',
            'Protéines à chaque repas.',
        ],
        'regles' => [
            'glucides_max_g' => 100,
        ],
        'aliments_conseilles' => ['Légumes verts', 'Œufs', 'Poisson', 'Volaille', 'Fromages', 'Noix', 'Avocat', 'Huile d’olive'],
        'aliments_a_limiter' => ['Pain', 'Pâtes', 'Riz', 'Pommes de terre', 'Sucreries', 'Sodas', 'Jus de fruits'],
        'conseils' => [
            'Garde des légumes à chaque repas pour les fibres.',
            'Hydrate-toi bien : la réduction des glucides augmente les pertes en eau.',
        ],
        'mineurs_autorise' => false,
    ],

    'keto' => [
        'nom' => 'Cétogène (keto)',
        'description' => 'Très peu de glucides (moins de 30 g par jour) et une majorité de calories issues des lipides.',
        'principes' => [
            'Glucides limités à environ 30 g par jour.',
            'Lipides entre 60 et 80 % des calories.',
            'Protéines modérées.',
        ],
        'regles' => [
            'glucides_max_g' => 30,
            'lipides_pct' => [60, 80],
        ],
        'aliments_conseilles' => ['Avocat', 'Huile d’olive', 'Œufs', 'Poisson gras', 'Fromages', 'Noix', 'Légumes verts', 'Beurre'],
        'aliments_a_limiter' => ['Pain', 'Pâtes', 'Riz', 'Fruits sucrés', 'Légumineuses', 'Sucre', 'Pommes de terre'],
        'conseils' => [
            'Ce mode alimentaire est exigeant : un suivi par un professionnel de santé est recommandé.',
            'Pense au sel, au potassium et au magnésium, souvent en baisse au début.',
            'Non proposé aux moins de 18 ans.',
        ],
        'mineurs_autorise' => false,
    ],

    'jeune_intermittent' => [
        'nom' => 'Jeûne intermittent (16/8)',
        'description' => 'Tous les repas dans une fenêtre de 8 heures (12 h – 20 h), sans changer la qualité de l’alimentation.',
        'principes' => [
            'Manger entre 12:00 et 20:00, jeûner le reste du temps.',
            'Eau, thé et café non sucrés autorisés pendant le jeûne.',
            'Des repas complets et équilibrés dans la fenêtre.',
        ],
        'regles' => [
            'fenetre_alimentaire' => ['debut' => '12:00', 'fin' => '20:00'],
        ],
        'aliments_conseilles' => ['Légumes', 'Protéines maigres', 'Céréales complètes', 'Fruits', 'Eau et boissons non sucrées'],
        'aliments_a_limiter' => ['Grignotages hors fenêtre', 'Boissons sucrées', 'Repas très copieux en fin de fenêtre'],
        'conseils' => [
            'Sans heure enregistrée, Mavi’oh suppose l’heure habituelle du repas (estimation).',
            'Le petit-déjeuner classique (8 h) sort de la fenêtre : décale-le ou saute-le.',
            'Non proposé aux moins de 18 ans, ni en cas de grossesse ou d’allaitement.',
        ],
        'mineurs_autorise' => false,
    ],

    'vegetarien' => [
        'nom' => 'Végétarien',
        'description' => 'Sans viande ni poisson ; les œufs et les produits laitiers restent possibles.',
        'principes' => [
            'Aucune chair animale (viande, volaille, poisson, fruits de mer).',
            'Protéines via légumineuses, œufs, laitages, tofu.',
            'Attention au fer et à la vitamine B12.',
        ],
        'regles' => [
            'exclure_categories_tags' => ['en:meats', 'en:fish', 'en:seafood', 'en:poultry'],
            'exclure_allergenes_tags' => ['en:fish', 'en:crustaceans', 'en:molluscs'],
            'exclure_mots_cles' => array_values(array_unique(array_merge($viandes, $poissons))),
            'exceptions_mots_cles' => $exceptionsVegetales,
        ],
        'aliments_conseilles' => ['Légumineuses', 'Œufs', 'Tofu', 'Fromages', 'Yaourts', 'Céréales complètes', 'Noix et graines', 'Légumes'],
        'aliments_a_limiter' => ['Plats végétariens ultra-transformés', 'Fromages très gras en excès'],
        'conseils' => [
            'Associe légumineuses et céréales pour couvrir tous les acides aminés.',
            'Une source de vitamine C au repas améliore l’absorption du fer végétal.',
        ],
        'mineurs_autorise' => true,
    ],

    'vegan' => [
        'nom' => 'Végan',
        'description' => 'Aucun produit d’origine animale : ni viande, ni poisson, ni œufs, ni produits laitiers, ni miel.',
        'principes' => [
            'Exclusivement des aliments végétaux.',
            'Supplémentation en vitamine B12 indispensable.',
            'Varier légumineuses, céréales, oléagineux et légumes.',
        ],
        'regles' => [
            'exclure_categories_tags' => ['en:meats', 'en:fish', 'en:seafood', 'en:poultry', 'en:eggs', 'en:dairies', 'en:milk', 'en:cheeses'],
            'exclure_allergenes_tags' => ['en:milk', 'en:eggs', 'en:fish', 'en:crustaceans', 'en:molluscs'],
            'exclure_mots_cles' => array_values(array_unique(array_merge($viandes, $poissons, $laitages, ['oeuf', 'oeufs', 'miel', 'gelatine']))),
            'exceptions_mots_cles' => $exceptionsVegetales,
        ],
        'aliments_conseilles' => ['Légumineuses', 'Tofu et tempeh', 'Céréales complètes', 'Noix et graines', 'Légumes', 'Fruits', 'Boissons végétales enrichies'],
        'aliments_a_limiter' => ['Substituts ultra-transformés', 'Sucres ajoutés'],
        'conseils' => [
            'La B12 ne se trouve pas dans les végétaux : un complément est nécessaire.',
            'Pense au calcium (boissons végétales enrichies, chou, amandes) et aux oméga-3 (lin, noix).',
        ],
        'mineurs_autorise' => true,
    ],

    'sans_gluten' => [
        'nom' => 'Sans gluten',
        'description' => 'Exclusion du blé, du seigle, de l’orge et de l’épeautre, et des produits qui en contiennent.',
        'principes' => [
            'Aucune céréale contenant du gluten.',
            'Riz, maïs, sarrasin, quinoa et pommes de terre comme féculents.',
            'Vérifier les étiquettes (sauces, charcuteries, bières).',
        ],
        'regles' => [
            'exclure_allergenes_tags' => ['en:gluten'],
            'exclure_mots_cles' => ['ble', 'seigle', 'orge', 'epeautre', 'pain', 'pates', 'farine', 'semoule', 'couscous', 'boulgour',
                'biscuit', 'biscuits', 'gateau', 'pizza', 'brioche', 'croissant', 'biere', 'seitan', 'chapelure', 'crackers'],
            'exceptions_mots_cles' => ['sans gluten', 'farine de riz', 'farine de mais', 'farine de sarrasin', 'farine de pois chiche',
                'farine de chataigne', 'farine d amande', 'farine de coco', 'pates de riz', 'nouilles de riz', 'pain de mais',
                'galette de riz', 'galette de sarrasin', 'biere sans gluten'],
        ],
        'aliments_conseilles' => ['Riz', 'Quinoa', 'Sarrasin', 'Maïs', 'Pommes de terre', 'Légumineuses', 'Légumes', 'Fruits', 'Viandes et poissons nature'],
        'aliments_a_limiter' => ['Produits industriels « sans gluten » très sucrés'],
        'conseils' => [
            'En cas de maladie cœliaque, la moindre trace compte : vérifie les mentions d’allergènes.',
            'Les produits Open Food Facts portent le tag « en:gluten » quand il est connu.',
        ],
        'mineurs_autorise' => true,
    ],

    'sans_lactose' => [
        'nom' => 'Sans lactose',
        'description' => 'Exclusion du lait et des produits laitiers contenant du lactose ; les alternatives végétales sont bienvenues.',
        'principes' => [
            'Pas de lait, crème, beurre, fromages frais ni yaourts classiques.',
            'Boissons végétales enrichies en calcium.',
            'Les fromages très affinés contiennent peu de lactose (tolérance individuelle).',
        ],
        'regles' => [
            'exclure_allergenes_tags' => ['en:milk'],
            'exclure_mots_cles' => $laitages,
            'exceptions_mots_cles' => $exceptionsVegetales,
        ],
        'aliments_conseilles' => ['Boissons végétales enrichies', 'Yaourts au soja', 'Légumes verts', 'Amandes', 'Sardines', 'Tofu'],
        'aliments_a_limiter' => ['Lait', 'Crème', 'Fromages frais', 'Glaces', 'Sauces à base de crème'],
        'conseils' => [
            'Surveille ton apport en calcium : vise des alternatives enrichies.',
            'Le tag « en:milk » d’Open Food Facts signale la présence de lait.',
        ],
        'mineurs_autorise' => true,
    ],

    'montignac' => [
        'nom' => 'Montignac',
        'description' => 'Choix des glucides selon leur index glycémique : on évite les sucres rapides et les farines raffinées.',
        'principes' => [
            'Préférer les glucides à index glycémique bas (légumineuses, céréales complètes).',
            'Éviter le sucre, le pain blanc, les pommes de terre et le riz blanc.',
            'Séparer si possible les repas glucido-protidiques et lipido-protidiques.',
        ],
        'regles' => [
            'exclure_mots_cles' => ['sucre', 'pain blanc', 'baguette', 'pomme de terre', 'pommes de terre', 'frites', 'riz blanc',
                'farine blanche', 'soda', 'confiture'],
            'exceptions_mots_cles' => ['sans sucre', 'sucre de coco', 'sans sucres ajoutes'],
            'sucres_pct_max' => 10,
        ],
        'aliments_conseilles' => ['Légumineuses', 'Pain intégral', 'Riz complet ou basmati', 'Quinoa', 'Fruits frais', 'Légumes', 'Chocolat noir 70 %'],
        'aliments_a_limiter' => ['Sucre', 'Pain blanc', 'Pommes de terre', 'Riz blanc', 'Sodas', 'Confitures', 'Farines blanches'],
        'conseils' => [
            'L’index glycémique n’est pas calculé par Mavi’oh : la répartition affichée est indicative.',
            'Remplace le riz blanc par du riz basmati complet ou des lentilles.',
            'Non proposé aux moins de 18 ans.',
        ],
        'mineurs_autorise' => false,
    ],

    'halal' => [
        'nom' => 'Halal',
        'description' => 'Exclusion du porc, de ses dérivés (gélatine porcine) et de l’alcool ; viandes issues d’un abattage rituel.',
        'principes' => [
            'Aucun porc ni dérivé (lard, gélatine porcine).',
            'Pas d’alcool, y compris en cuisine.',
            'Viandes halal.',
        ],
        'regles' => [
            'exclure_mots_cles' => ['porc', 'lard', 'lardon', 'lardons', 'bacon', 'jambon', 'saucisson', 'chorizo', 'rillettes',
                'gelatine porcine', 'gelatine de porc', 'alcool', 'vin', 'biere', 'cidre', 'rhum', 'whisky', 'liqueur'],
            'exceptions_mots_cles' => ['jambon de dinde', 'jambon de volaille', 'jambon de poulet', 'jambon halal', 'sans alcool',
                'vinaigre', 'gelatine de boeuf', 'gelatine de poisson', 'gelatine vegetale'],
        ],
        'aliments_conseilles' => ['Volaille halal', 'Agneau et bœuf halal', 'Poisson', 'Légumineuses', 'Légumes', 'Fruits', 'Céréales'],
        'aliments_a_limiter' => ['Charcuteries de porc', 'Plats cuisinés au vin', 'Gélatine non identifiée'],
        'conseils' => [
            'Vérifie la gélatine des desserts et confiseries : sa provenance n’est pas toujours précisée.',
            'Mavi’oh ne connaît pas la certification des viandes : la détection porte sur le porc et l’alcool.',
        ],
        'mineurs_autorise' => true,
    ],

    'autre' => [
        'nom' => 'Autre',
        'description' => 'Régime personnalisé : Mavi’oh applique uniquement tes allergènes et tes aliments exclus.',
        'principes' => [
            'Tes propres règles, définies dans ton profil (allergènes, aliments exclus).',
            'Les cibles caloriques et les macros restent calculées.',
        ],
        'regles' => [],
        'aliments_conseilles' => [],
        'aliments_a_limiter' => [],
        'conseils' => [
            'Renseigne tes allergènes et tes aliments exclus dans ton profil pour affiner les suggestions.',
        ],
        'mineurs_autorise' => true,
    ],
];
