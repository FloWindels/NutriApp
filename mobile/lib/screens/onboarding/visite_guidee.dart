import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';

import '../../core/session.dart';
import '../../core/strings.dart';
import '../../theme/app_theme.dart';
import '../../widgets/repere_visite.dart';

/// Une étape de la visite : l'écran à montrer, ce qu'on y éclaire, et ce que ça fait.
class EtapeVisite {
  final String titre;
  final String texte;
  final IconData icone;
  final Color teinte;

  /// Écran ouvert pendant l'étape (slug de `AppSections`).
  final String ecran;

  /// Repère éclairé sur cet écran (voir [Reperes]).
  final String repere;

  /// Où la trouver dans l'app (onglet du bas ou « Plus »).
  final String? ou;

  /// Capacité payante dont parle l'étape (backend/config/offres.php).
  final String? capacite;

  /// Ce qui reste accessible sans l'offre, quand l'étape n'est payante qu'en partie.
  final String? sansOffre;

  const EtapeVisite({
    required this.titre,
    required this.texte,
    required this.icone,
    required this.ecran,
    required this.repere,
    this.teinte = MaviohColors.primary,
    this.ou,
    this.capacite,
    this.sansOffre,
  });
}

/// Mêmes étapes que la visite du site, adaptées aux onglets de l'app : chacune ouvre
/// l'écran dont elle parle et y éclaire le bouton ou la carte à connaître.
const List<EtapeVisite> etapesVisite = [
  EtapeVisite(
    titre: 'Bienvenue sur ${AppStrings.brand}',
    texte: 'Ton coach nutrition et sport. Voici l’accueil : ton budget calorique du jour, '
        'ce que tu as déjà mangé et ce qu’il te reste. En une minute, on fait le tour de l’app ; '
        'tu pourras revoir cette visite depuis les Paramètres.',
    icone: Icons.waving_hand_outlined,
    ecran: 'dashboard',
    repere: Reperes.accueilBudget,
    ou: 'Onglet « ${AppStrings.tabHome} »',
  ),
  EtapeVisite(
    titre: 'Ton journal des repas',
    texte: 'Note ce que tu manges au fil de la journée : le + ajoute un aliment ou une recette, '
        'l’appareil photo découpe ton assiette en aliments et en calories.',
    icone: Icons.restaurant_outlined,
    teinte: MaviohColors.indigo,
    ecran: 'historique-repas-journee',
    repere: Reperes.repasAjout,
    ou: 'Onglet « ${AppStrings.tabMeals} »',
    capacite: 'ia',
    sansOffre: 'Le journal est gratuit ; la photo d’assiette fait partie de l’offre Complet.',
  ),
  EtapeVisite(
    titre: 'Trouver un aliment',
    texte: 'Cherche parmi des milliers d’aliments par leur nom, ou scanne le code-barres d’un produit '
        'pour retrouver ses valeurs nutritionnelles.',
    icone: Icons.qr_code_scanner_rounded,
    teinte: MaviohColors.cyan,
    ecran: 'recherche-aliments',
    repere: Reperes.alimentsRecherche,
    ou: '« ${AppStrings.tabMore} » › ${AppStrings.sectionFoodSearch}',
  ),
  EtapeVisite(
    titre: 'Tes recettes',
    texte: 'Enregistre tes recettes : Mavi’oh calcule les calories par portion. '
        'Dis-lui de quoi tu as envie et il t’en écrit une à ta mesure.',
    icone: Icons.menu_book_outlined,
    teinte: MaviohColors.emerald,
    ecran: 'recettes',
    repere: Reperes.recettesNouvelle,
    ou: '« ${AppStrings.tabMore} » › ${AppStrings.sectionRecipes}',
    capacite: 'ia',
    sansOffre: 'Tes recettes sont gratuites ; celles écrites par Mavi’oh font partie de l’offre Complet.',
  ),
  EtapeVisite(
    titre: 'Le coach du jour',
    texte: 'Chaque jour, Mavi’oh regarde ce que tu as mangé et te dit quoi ajuster au repas suivant : '
        'la protéine qui manque, l’écart à rattraper.',
    icone: Icons.tips_and_updates_outlined,
    teinte: MaviohColors.teal,
    ecran: 'recommandations-repas-journee',
    repere: Reperes.coachConseils,
    ou: '« ${AppStrings.tabMore} » › ${AppStrings.sectionCoach}',
    capacite: 'coach',
  ),
  EtapeVisite(
    titre: 'Ton frigo et tes placards',
    texte: 'Range ce que tu as chez toi : Mavi’oh te prévient avant qu’un produit ne périme et compose tes repas avec.',
    icone: Icons.kitchen_outlined,
    teinte: MaviohColors.lime,
    ecran: 'stock',
    repere: Reperes.stockAjouter,
    ou: 'Onglet « ${AppStrings.tabStock} »',
    capacite: 'stock',
  ),
  EtapeVisite(
    titre: 'Ta semaine planifiée',
    texte: 'Remplis ta semaine de repas en un geste, selon tes objectifs, ton régime et ce que tu as déjà sous la main.',
    icone: Icons.calendar_month_outlined,
    teinte: MaviohColors.orange,
    ecran: 'planificateur-semaine',
    repere: Reperes.planificateurGenerer,
    ou: '« ${AppStrings.tabMore} » › ${AppStrings.sectionPlanner}',
    capacite: 'planificateur',
  ),
  EtapeVisite(
    titre: 'La liste de courses',
    texte: 'Les ingrédients de ta semaine s’y ajoutent tout seuls, moins ce que tu as déjà. '
        'Choisis ton magasin pour la voir rangée par rayon, avec des prix indicatifs.',
    icone: Icons.shopping_cart_outlined,
    teinte: MaviohColors.slate,
    ecran: 'liste-course',
    repere: Reperes.coursesGenerer,
    ou: '« ${AppStrings.tabMore} » › ${AppStrings.sectionShopping}',
    capacite: 'courses',
  ),
  EtapeVisite(
    titre: 'Le sport',
    texte: 'Enregistre tes activités : les calories brûlées s’ajoutent à ton budget. '
        'Mavi’oh peut aussi construire ta séance selon ton niveau, ton matériel et ton temps.',
    icone: Icons.fitness_center_outlined,
    teinte: MaviohColors.amber,
    ecran: 'sport',
    repere: Reperes.sportGenerer,
    ou: 'Onglet « ${AppStrings.tabSport} »',
    capacite: 'seances',
    sansOffre: 'Enregistrer tes activités est gratuit ; les séances construites par Mavi’oh font partie de l’offre Complet.',
  ),
  EtapeVisite(
    titre: 'En famille',
    texte: 'Invite jusqu’à cinq personnes : stock, courses et repas planifiés partagés, '
        'et un repas commun dont Mavi’oh calcule la part de chacun.',
    icone: Icons.family_restroom_outlined,
    teinte: MaviohColors.sky,
    ecran: 'famille',
    repere: Reperes.familleFoyer,
    ou: '« ${AppStrings.tabMore} » › ${AppStrings.sectionFamily}',
    capacite: 'foyer',
  ),
  EtapeVisite(
    titre: 'Ton profil',
    texte: 'Tes objectifs naissent de ton profil : poids, objectif, régime, allergies, préférences sportives. '
        'Mets-le à jour quand ta situation change.',
    icone: Icons.person_outline_rounded,
    teinte: MaviohColors.rose,
    ecran: 'profil',
    repere: Reperes.profilFormulaire,
    ou: '« ${AppStrings.tabMore} » › ${AppStrings.sectionProfile}',
  ),
  EtapeVisite(
    titre: 'Les paramètres',
    texte: 'Rappels, alertes de péremption, export de tes données… et, ici, le bouton pour revoir cette visite.',
    icone: Icons.settings_outlined,
    teinte: MaviohColors.violet,
    ecran: 'settings',
    repere: Reperes.parametresVisite,
    ou: '« ${AppStrings.tabMore} » › ${AppStrings.sectionSettings}',
  ),
  EtapeVisite(
    titre: 'À toi de jouer',
    texte: 'Commence par noter ton prochain repas avec ce bouton + : le reste viendra naturellement.',
    icone: Icons.flag_outlined,
    ecran: 'dashboard',
    repere: Reperes.accueilAjouter,
  ),
];

/// Offre la moins chère qui inclut une capacité (même table que le site).
String _offrePour(String capacite) => capacite == 'foyer' ? 'Foyer' : 'Complet';

/// Visite guidée : ouvre chaque écran de l'app et y éclaire ce qu'il faut connaître,
/// avec une carte d'explication placée pour ne jamais masquer l'élément éclairé.
///
/// [naviguer] est la navigation de l'écran principal (`HomeScreen.navigate`) : la visite
/// change d'onglet ou ouvre une section de « Plus » sans empiler de nouvelles pages.
/// Sans elle, la visite reste sur l'écran courant. Renvoie quand la visite est fermée,
/// terminée ou passée, une fois revenue sur l'accueil.
class VisiteGuidee extends StatefulWidget {
  const VisiteGuidee({super.key, this.naviguer});

  final ValueChanged<String>? naviguer;

  static Future<void> show(BuildContext context, {ValueChanged<String>? naviguer}) async {
    await showGeneralDialog<void>(
      context: context,
      barrierDismissible: false,
      barrierLabel: 'Visite guidée',
      // Le voile est peint par la visite elle-même, percé autour de l'élément éclairé.
      barrierColor: Colors.transparent,
      transitionDuration: const Duration(milliseconds: 200),
      pageBuilder: (_, _, _) => VisiteGuidee(naviguer: naviguer),
    );
    naviguer?.call('dashboard');
  }

  @override
  State<VisiteGuidee> createState() => _VisiteGuideeState();
}

class _VisiteGuideeState extends State<VisiteGuidee> {
  /// Le temps laissé à un écran pour afficher son repère (chargement), avant de se rabattre
  /// sur la barre de titre : une fonction verrouillée ou en erreur n'a pas de repère.
  static const _attente = Duration(milliseconds: 1200);

  /// Après l'avoir trouvé, on suit encore le repère le temps que l'écran défile et se pose.
  static const _suivi = Duration(milliseconds: 700);

  /// Hauteur de la `NavigationBar` de l'écran principal, que la carte ne recouvre pas.
  static const _hauteurBarreNavigation = 80.0;

  final GlobalKey _zone = GlobalKey();

  int _index = 0;

  /// Zone éclairée, dans le repère de la visite ; null tant que rien n'est trouvé.
  Rect? _cible;

  /// Change à chaque étape : les mesures d'une étape quittée s'arrêtent d'elles-mêmes.
  int _jeton = 0;
  String? _cle;
  Duration? _debut;
  Duration? _trouveA;

  EtapeVisite get _etape => etapesVisite[_index];

  @override
  void initState() {
    super.initState();
    // Après la première image : changer d'onglet pendant une construction est interdit.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _montrer();
    });
  }

  /// Ouvre l'écran de l'étape courante puis cherche son repère.
  void _montrer() {
    final jeton = ++_jeton;
    _cle = _etape.repere;
    _debut = null;
    _trouveA = null;
    setState(() => _cible = null);
    widget.naviguer?.call(_etape.ecran);
    _mesurerApres(jeton);
  }

  /// Mesure le repère après chaque image, jusqu'à ce qu'il soit posé (ou abandonné).
  void _mesurerApres(int jeton) {
    final binding = SchedulerBinding.instance;
    binding.addPostFrameCallback((horodatage) {
      if (!mounted || jeton != _jeton) return;
      final debut = _debut ??= horodatage;
      final cle = _cle;
      final repere = cle == null ? null : RepereVisite.trouver(cle);

      if (repere != null) {
        if (_trouveA == null) {
          _trouveA = horodatage;
          if (cle != Reperes.barre) _defilerVers(repere);
        }
        _placer(repere);
        if (horodatage - _trouveA! < _suivi) _mesurerApres(jeton);
        return;
      }

      if (_trouveA != null) {
        // Perdu de vue après coup (écran reconstruit) : on le cherche encore un instant.
        if (horodatage - _trouveA! < _suivi) _mesurerApres(jeton);
        return;
      }

      if (horodatage - debut >= _attente) {
        if (cle == Reperes.barre) return; // ni repère ni barre : la carte suffit
        // Fonction verrouillée, en chargement ou en erreur : on montre au moins l'écran.
        _cle = Reperes.barre;
        _debut = horodatage;
      }
      _mesurerApres(jeton);
    });
    binding.scheduleFrame();
  }

  void _defilerVers(BuildContext repere) {
    // Haut d'écran : la carte se place alors en dessous, et la liste reste lisible.
    Scrollable.ensureVisible(
      repere,
      alignment: 0.2,
      duration: const Duration(milliseconds: 300),
      curve: Curves.easeOutCubic,
    );
  }

  void _placer(BuildContext repere) {
    final boite = repere.findRenderObject();
    final zone = _zone.currentContext?.findRenderObject();
    if (boite is! RenderBox || zone is! RenderBox || !boite.attached || !zone.hasSize) return;
    final origine = zone.globalToLocal(boite.localToGlobal(Offset.zero));
    final rect = (origine & boite.size).inflate(6).intersect(Offset.zero & zone.size);
    if (rect.isEmpty || rect == _cible) return;
    setState(() => _cible = rect);
  }

  void _fermer() => Navigator.of(context).pop();

  void _suivant() {
    if (_index >= etapesVisite.length - 1) {
      _fermer();
    } else {
      _index++;
      _montrer();
    }
  }

  void _precedent() {
    if (_index == 0) return;
    _index--;
    _montrer();
  }

  @override
  Widget build(BuildContext context) {
    final media = MediaQuery.of(context);
    return PopScope(
      canPop: false,
      // Le bouton retour d'Android revient d'une étape ; à la première, il ferme la visite.
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        if (_index > 0) {
          _precedent();
        } else {
          _fermer();
        }
      },
      child: LayoutBuilder(
        builder: (context, contraintes) {
          final taille = contraintes.biggest;
          final haut = media.padding.top + 12;
          final bas = media.padding.bottom + (widget.naviguer != null ? _hauteurBarreNavigation : 0) + 12;
          final cible = _cible;

          // La carte va du côté opposé à l'élément éclairé : en bas s'il est dans la moitié
          // haute, en haut sinon — sauf si la place y manque nettement.
          var enBas = true;
          var hauteurMax = taille.height - haut - bas;
          if (cible != null) {
            final dessous = taille.height - bas - cible.bottom - 12;
            final dessus = cible.top - haut - 12;
            enBas = cible.center.dy < taille.height / 2;
            if (enBas && dessous < 220 && dessus > dessous) enBas = false;
            if (!enBas && dessus < 220 && dessous > dessus) enBas = true;
            hauteurMax = math.max(enBas ? dessous : dessus, 220);
          }

          return Stack(
            key: _zone,
            children: [
              Positioned.fill(
                child: GestureDetector(
                  // Le voile retient les touchers : la visite montre, elle ne fait pas agir.
                  behavior: HitTestBehavior.opaque,
                  onTap: () {},
                  child: cible == null
                      ? const CustomPaint(key: ValueKey('visite-voile'), painter: ProjecteurVisite(null))
                      // Le projecteur glisse d'une position à l'autre pendant que l'écran défile.
                      : TweenAnimationBuilder<Rect?>(
                          tween: RectTween(begin: cible, end: cible),
                          duration: const Duration(milliseconds: 220),
                          curve: Curves.easeOutCubic,
                          builder: (_, rect, _) => CustomPaint(
                            key: const ValueKey('visite-voile'),
                            painter: ProjecteurVisite(rect),
                          ),
                        ),
                ),
              ),
              Positioned(
                left: 16,
                right: 16,
                top: enBas ? null : haut,
                bottom: enBas ? bas : null,
                child: Center(
                  child: ConstrainedBox(
                    constraints: BoxConstraints(maxWidth: 440, maxHeight: hauteurMax),
                    child: _carte(),
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  Widget _carte() {
    final etape = _etape;
    final derniere = _index == etapesVisite.length - 1;
    final user = Session.instance.user;
    final verrou = etape.capacite != null && user != null && !user.peut(etape.capacite!);

    return Material(
      key: const ValueKey('visite-carte'),
      color: Colors.white,
      borderRadius: BorderRadius.circular(24),
      elevation: 12,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(18, 16, 18, 8),
        child: Semantics(
          scopesRoute: true,
          namesRoute: true,
          explicitChildNodes: true,
          label: 'Visite guidée',
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Container(
                    width: 38,
                    height: 38,
                    decoration: BoxDecoration(
                      color: etape.teinte.withValues(alpha: 0.12),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Icon(etape.icone, color: etape.teinte, size: 21),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'VISITE GUIDÉE · ${_index + 1}/${etapesVisite.length}',
                          style: const TextStyle(
                              fontSize: 10.5, letterSpacing: 1.1, fontWeight: FontWeight.w700, color: MaviohColors.primary),
                        ),
                        const SizedBox(height: 2),
                        Semantics(
                          header: true,
                          liveRegion: true,
                          child: Text(etape.titre,
                              style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: MaviohColors.text)),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              // Seule partie qui défile quand la place manque : les boutons restent visibles.
              Flexible(
                child: SingleChildScrollView(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(etape.texte, style: const TextStyle(color: MaviohColors.textTertiary, height: 1.4)),
                      if (etape.ou != null) ...[
                        const SizedBox(height: 8),
                        Row(
                          children: [
                            const Icon(Icons.place_outlined, size: 16, color: MaviohColors.muted),
                            const SizedBox(width: 6),
                            Expanded(
                              child: Text(etape.ou!,
                                  style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: MaviohColors.textSecondary)),
                            ),
                          ],
                        ),
                      ],
                      if (verrou) ...[
                        const SizedBox(height: 8),
                        Container(
                          width: double.infinity,
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                          decoration: BoxDecoration(
                            color: MaviohColors.warningBg,
                            borderRadius: BorderRadius.circular(14),
                            border: Border.all(color: const Color(0xFFFDE68A)),
                          ),
                          child: Text(
                            etape.sansOffre ?? 'Inclus dans l’offre ${_offrePour(etape.capacite!)}.',
                            style: const TextStyle(fontSize: 13, color: MaviohColors.warning),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 12),
              ExcludeSemantics(
                child: Row(
                  children: [
                    for (var i = 0; i < etapesVisite.length; i++)
                      AnimatedContainer(
                        duration: const Duration(milliseconds: 200),
                        margin: const EdgeInsets.only(right: 4),
                        width: i == _index ? 16 : 6,
                        height: 6,
                        decoration: BoxDecoration(
                          color: i == _index
                              ? MaviohColors.primary
                              : (i < _index ? const Color(0xFF6EE7B7) : MaviohColors.border),
                          borderRadius: BorderRadius.circular(4),
                        ),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: 4),
              Row(
                children: [
                  if (!derniere) TextButton(onPressed: _fermer, child: const Text('Passer')),
                  const Spacer(),
                  // Une icône plutôt qu'un libellé : trois boutons tiennent ainsi sur 320 px.
                  if (_index > 0) ...[
                    IconButton.outlined(
                      tooltip: 'Précédent',
                      onPressed: _precedent,
                      icon: const Icon(Icons.arrow_back_rounded),
                    ),
                    const SizedBox(width: 8),
                  ],
                  FilledButton(
                    onPressed: _suivant,
                    child: Text(derniere ? 'Terminer' : (_index == 0 ? 'C’est parti' : 'Suivant')),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Le voile sombre, percé d’un rectangle arrondi autour de l’élément éclairé ([cible]).
class ProjecteurVisite extends CustomPainter {
  const ProjecteurVisite(this.cible);

  final Rect? cible;

  @override
  void paint(Canvas canvas, Size size) {
    final voile = Paint()..color = const Color(0xB30F172A);
    final cible = this.cible;
    if (cible == null) {
      canvas.drawRect(Offset.zero & size, voile);
      return;
    }
    final trou = RRect.fromRectAndRadius(cible, const Radius.circular(16));
    canvas.drawPath(
      Path()
        ..fillType = PathFillType.evenOdd
        ..addRect(Offset.zero & size)
        ..addRRect(trou),
      voile,
    );
    canvas.drawRRect(
      trou,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..color = Colors.white.withValues(alpha: 0.85),
    );
  }

  @override
  bool shouldRepaint(ProjecteurVisite oldDelegate) => oldDelegate.cible != cible;
}
