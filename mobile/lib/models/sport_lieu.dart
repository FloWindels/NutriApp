import '../core/api_client.dart';
import '../core/strings.dart';

/// Lieu d'entraînement (Sport v2, § 1) : chacun a son matériel, la séance se génère pour un lieu.
class SportLieu {
  final int id;
  final String nom;
  final String type; // maison | exterieur | salle_publique | salle_privee
  final String? typeLibelle;
  final List<String> materiel;
  final bool parDefaut;

  const SportLieu({
    required this.id,
    required this.nom,
    this.type = 'maison',
    this.typeLibelle,
    this.materiel = const [],
    this.parDefaut = false,
  });

  factory SportLieu.fromJson(Map<String, dynamic> json) => SportLieu(
        id: parseIntOr(json['id'], 0),
        nom: parseString(json['nom']) ?? 'Lieu',
        type: parseString(json['type']) ?? 'maison',
        typeLibelle: parseString(json['type_libelle']),
        materiel: ApiClient.asStringList(json['materiel']),
        parDefaut: parseBool(json['par_defaut']),
      );

  /// Libellé du type : celui du serveur, sinon le vocabulaire local.
  String get typeLabel => typeLibelle ?? AppStrings.vocabLabel(AppStrings.sportLieux, type);
}

/// Entrée de `materiel_catalogue` : `{valeur, libelle, categorie}`.
class MaterielItem {
  final String valeur;
  final String libelle;
  final String categorie; // cardio | musculation | accessoire | aucun

  const MaterielItem(this.valeur, this.libelle, this.categorie);

  factory MaterielItem.fromJson(Map<String, dynamic> json) => MaterielItem(
        parseString(json['valeur']) ?? parseString(json['key']) ?? '',
        parseString(json['libelle']) ?? parseString(json['label']) ?? parseString(json['valeur']) ?? '',
        parseString(json['categorie']) ?? 'accessoire',
      );
}

/// Catégories de matériel, dans l'ordre d'affichage.
const List<VocabItem> categoriesMateriel = [
  VocabItem('aucun', 'Sans matériel'),
  VocabItem('cardio', 'Cardio'),
  VocabItem('musculation', 'Musculation'),
  VocabItem('accessoire', 'Accessoires'),
];

/// Catalogue local (repli quand `GET /sport/config` n'envoie pas encore `materiel_catalogue`).
const List<MaterielItem> materielCatalogueLocal = [
  MaterielItem('aucun', 'Poids du corps', 'aucun'),
  MaterielItem('tapis', 'Tapis de sol', 'accessoire'),
  MaterielItem('elastiques', 'Élastiques', 'accessoire'),
  MaterielItem('corde_a_sauter', 'Corde à sauter', 'cardio'),
  MaterielItem('trx', 'Sangles de suspension (TRX)', 'accessoire'),
  MaterielItem('swiss_ball', 'Swiss ball', 'accessoire'),
  MaterielItem('medecine_ball', 'Médecine-ball', 'accessoire'),
  MaterielItem('step', 'Step / box', 'accessoire'),
  MaterielItem('halteres', 'Haltères', 'musculation'),
  MaterielItem('kettlebell', 'Kettlebell', 'musculation'),
  MaterielItem('barre', 'Barre et disques', 'musculation'),
  MaterielItem('banc', 'Banc', 'musculation'),
  MaterielItem('barre_traction', 'Barre de traction', 'musculation'),
  MaterielItem('machine', 'Machines guidées', 'musculation'),
  MaterielItem('poulie', 'Poulie / vis-à-vis', 'musculation'),
  MaterielItem('velo', 'Vélo d’appartement / home trainer', 'cardio'),
  MaterielItem('velo_route', 'Vélo (route, VTT)', 'cardio'),
  MaterielItem('tapis_course', 'Tapis de course', 'cardio'),
  MaterielItem('rameur', 'Rameur', 'cardio'),
  MaterielItem('elliptique', 'Vélo elliptique', 'cardio'),
  MaterielItem('piscine', 'Piscine', 'cardio'),
];

/// Préréglages locaux (repli de `materiel_par_type`).
Map<String, List<String>> materielParTypeLocal() {
  final tout = materielCatalogueLocal.map((m) => m.valeur).toList();
  return {
    'maison': const ['aucun', 'tapis'],
    'exterieur': const ['aucun'],
    'salle_publique': tout.where((v) => v != 'velo_route' && v != 'piscine').toList(),
    'salle_privee': tout.where((v) => v != 'velo_route').toList(),
  };
}

/// « Haltères, Banc, Rameur… » : le matériel d'un lieu en une ligne.
String resumeMateriel(List<String> materiel, List<MaterielItem> catalogue, {int max = 4}) {
  if (materiel.isEmpty) return 'Aucun matériel renseigné';
  String label(String v) {
    for (final m in catalogue) {
      if (m.valeur == v) return m.libelle;
    }
    return v;
  }

  final labels = materiel.map(label).toList();
  if (labels.length <= max) return labels.join(', ');
  return '${labels.take(max).join(', ')} +${labels.length - max}';
}
