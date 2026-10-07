import 'package:flutter/widgets.dart';

/// Les repères que la visite guidée met en lumière, écran par écran.
class Reperes {
  Reperes._();

  /// Titre de la barre du haut : le repli quand l'écran n'a pas (encore) son repère.
  static const barre = 'barre';
  static const accueilBudget = 'accueil.budget';
  static const accueilAjouter = 'accueil.ajouter';
  static const repasAjout = 'repas.ajout';
  static const alimentsRecherche = 'aliments.recherche';
  static const recettesNouvelle = 'recettes.nouvelle';
  static const coachConseils = 'coach.conseils';
  static const stockAjouter = 'stock.ajouter';
  static const planificateurGenerer = 'planificateur.generer';
  static const coursesGenerer = 'courses.generer';
  static const sportGenerer = 'sport.generer';
  static const familleFoyer = 'famille.foyer';
  static const profilFormulaire = 'profil.formulaire';
  static const parametresVisite = 'parametres.visite';
}

/// Marque un widget que la visite guidée peut montrer du doigt.
///
/// Plutôt qu'une `GlobalKey` partagée, chaque repère s'inscrit sous son identifiant tant
/// qu'il est monté : deux écrans qui affichent le même repère ne se gênent pas, et la
/// visite retient celui qui est réellement à l'écran.
class RepereVisite extends StatefulWidget {
  const RepereVisite({super.key, required this.id, required this.child});

  final String id;
  final Widget child;

  static final Map<String, List<_RepereVisiteState>> _montes = {};

  /// Le repère [id] affiché à l'écran (le dernier monté s'il y en a plusieurs), ou null
  /// s'il n'est pas monté, pas encore mesuré, ou caché dans un onglet inactif.
  static BuildContext? trouver(String id) {
    final etats = _montes[id];
    if (etats == null) return null;
    for (final etat in etats.reversed) {
      if (!etat.mounted || !etat._actif) continue;
      final boite = etat.context.findRenderObject();
      if (boite is RenderBox && boite.attached && boite.hasSize && !boite.size.isEmpty) return etat.context;
    }
    return null;
  }

  @override
  State<RepereVisite> createState() => _RepereVisiteState();
}

class _RepereVisiteState extends State<RepereVisite> {
  /// Faux dans un onglet inactif de l'`IndexedStack` : il y reste monté, mais invisible.
  bool _actif = true;

  void _inscrire(String id) => RepereVisite._montes.putIfAbsent(id, () => []).add(this);

  void _retirer(String id) {
    final etats = RepereVisite._montes[id];
    etats?.remove(this);
    if (etats != null && etats.isEmpty) RepereVisite._montes.remove(id);
  }

  @override
  void initState() {
    super.initState();
    _inscrire(widget.id);
  }

  @override
  void didUpdateWidget(RepereVisite oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.id != widget.id) {
      _retirer(oldWidget.id);
      _inscrire(widget.id);
    }
  }

  @override
  void dispose() {
    _retirer(widget.id);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    _actif = TickerMode.valuesOf(context).enabled;
    return widget.child;
  }
}
