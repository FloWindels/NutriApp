import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/api_client.dart';
import '../../core/formatters.dart';
import '../../theme/app_theme.dart';
import '../../models/meal.dart';
import '../../models/plate.dart';
import '../../services/meal_service.dart';
import '../../widgets/add_to_meal_sheet.dart';

/// Photo d'assiette sur mobile.
///
/// C'est l'usage le plus naturel de la fonction : l'appareil photo est déjà dans la main. Le
/// parcours est celui du site, et la règle est la même — l'analyse ne fait que proposer, rien
/// n'est enregistré avant que la personne ait relu et corrigé chaque ligne. Ce que le modèle
/// n'a pas vu s'ajoute à la main depuis le catalogue, dans la même liste.
class PlatePhotoSheet extends StatefulWidget {
  const PlatePhotoSheet({
    super.key,
    required this.date,
    required this.type,
    this.mealService,
    this.imagePicker,
    this.choisirAjout,
  });

  final DateTime date;
  final String type;
  final MealService? mealService;
  final ImagePicker? imagePicker;

  /// Choix d'un aliment oublié ; par défaut [AddToMealSheet.pick]. Remplaçable en test.
  final Future<PickedMealItem?> Function(BuildContext context)? choisirAjout;

  static Future<bool?> show(
    BuildContext context, {
    required DateTime date,
    required String type,
  }) {
    return showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => PlatePhotoSheet(date: date, type: type),
    );
  }

  @override
  State<PlatePhotoSheet> createState() => _PlatePhotoSheetState();
}

class _LigneEditable {
  _LigneEditable(PlateLine this.ligne)
      : ajout = null,
        quantite = ligne.quantite.toStringAsFixed(0);

  /// Ligne ajoutée à la main : absente de la photo, elle se retire au lieu de se décocher.
  _LigneEditable.ajoutee(PickedMealItem this.ajout)
      : ligne = null,
        quantite = _texte(ajout.input.quantity);

  final PlateLine? ligne;
  final PickedMealItem? ajout;
  String quantite;
  bool retenue = true;

  static String _texte(double q) => q == q.roundToDouble() ? q.toStringAsFixed(0) : q.toString();

  double? get quantiteSaisie {
    final q = double.tryParse(quantite.replaceAll(',', '.'));
    return q == null || q <= 0 ? null : q;
  }
}

class _PlatePhotoSheetState extends State<PlatePhotoSheet> {
  static const int _maxOctets = 350 * 1024;

  late final MealService _meals = widget.mealService ?? MealService();
  late final ImagePicker _picker = widget.imagePicker ?? ImagePicker();

  bool _analyse = false;
  bool _enregistre = false;
  String? _erreur;
  PlateAnalysis? _resultat;
  List<_LigneEditable> _lignes = const [];

  Future<void> _prendrePhoto(ImageSource source) async {
    setState(() {
      _erreur = null;
      _resultat = null;
      _lignes = const [];
    });

    try {
      final picked = await _picker.pickImage(source: source, maxWidth: 1024, imageQuality: 75);
      if (picked == null || !mounted) return;

      final bytes = await picked.readAsBytes();
      if (!mounted) return;

      if (bytes.lengthInBytes > _maxOctets) {
        setState(() => _erreur =
            'Photo trop lourde. Prends-la d’un peu plus loin ou choisis-en une plus légère.');
        return;
      }

      setState(() => _analyse = true);

      final analyse = await _meals.analyserPhoto('data:image/jpeg;base64,${base64Encode(bytes)}');
      if (!mounted) return;

      setState(() {
        _analyse = false;
        _resultat = analyse;
        _lignes = analyse.aliments.map(_LigneEditable.new).toList();
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _analyse = false;
        _erreur = e.message;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _analyse = false;
        _erreur = 'Impossible d’ouvrir l’appareil photo. Vérifie les autorisations.';
      });
    }
  }

  Future<void> _enregistrer() async {
    final items = <MealItemInput>[];

    for (final ligne in _lignes) {
      if (!ligne.retenue) continue;

      final quantite = ligne.quantiteSaisie;
      if (quantite == null) continue;

      final ajout = ligne.ajout?.input;
      if (ajout != null) {
        items.add(MealItemInput(
          foodId: ajout.foodId,
          recipeId: ajout.recipeId,
          custom: ajout.custom,
          quantity: quantite,
          unit: ajout.unit,
        ));
        continue;
      }

      final plate = ligne.ligne!;
      final food = plate.food;
      items.add(food != null
          ? MealItemInput(foodId: food.id, quantity: quantite, unit: plate.unite)
          : MealItemInput(
              custom: CustomItemInput(
                label: plate.nom,
                calories: plate.valeursProposees?.calories ?? 0,
                proteins: plate.valeursProposees?.proteines ?? 0,
                carbs: plate.valeursProposees?.glucides ?? 0,
                fat: plate.valeursProposees?.lipides ?? 0,
              ),
              quantity: quantite,
              unit: plate.unite,
            ));
    }

    if (items.isEmpty) {
      setState(() => _erreur = 'Garde au moins une ligne avec une quantité valide.');
      return;
    }

    setState(() {
      _enregistre = true;
      _erreur = null;
    });

    try {
      await _meals.createOrAppend(date: widget.date, type: widget.type, items: items);
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _enregistre = false;
        _erreur = e.message;
      });
    }
  }

  Future<void> _ajouter() async {
    final choisir = widget.choisirAjout ??
        (BuildContext context) => AddToMealSheet.pick(context, title: 'Ajouter un aliment oublié');
    final choix = await choisir(context);
    if (choix == null || !mounted) return;

    setState(() {
      _erreur = null;
      _lignes = [..._lignes, _LigneEditable.ajoutee(choix)];
    });
  }

  @override
  Widget build(BuildContext context) {
    final resultat = _resultat;

    return DraggableScrollableSheet(
      initialChildSize: 0.85,
      minChildSize: 0.5,
      maxChildSize: 0.95,
      expand: false,
      builder: (context, controller) => Container(
        decoration: const BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        ),
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
        child: ListView(
          controller: controller,
          children: [
            const Text(
              'Photo de mon assiette',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
            ),
            const SizedBox(height: 8),
            const Text(
              'Estimation à partir de ta photo : les quantités sont approximatives. Vérifie et '
              'corrige chaque ligne avant d’enregistrer. Ce n’est pas une mesure clinique ni un '
              'avis médical.',
              style: TextStyle(fontSize: 12.5, color: MaviohColors.muted),
            ),
            const SizedBox(height: 16),

            Row(
              children: [
                Expanded(
                  child: FilledButton.icon(
                    onPressed: _analyse ? null : () => _prendrePhoto(ImageSource.camera),
                    icon: const Icon(Icons.photo_camera_outlined),
                    label: const Text('Photographier'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _analyse ? null : () => _prendrePhoto(ImageSource.gallery),
                    icon: const Icon(Icons.photo_library_outlined),
                    label: const Text('Galerie'),
                  ),
                ),
              ],
            ),

            if (_analyse) ...[
              const SizedBox(height: 20),
              const Center(child: CircularProgressIndicator()),
              const SizedBox(height: 10),
              const Center(
                child: Text('Analyse en cours…', style: TextStyle(color: MaviohColors.muted)),
              ),
            ],

            if (_erreur != null) ...[
              const SizedBox(height: 14),
              Text(_erreur!, style: const TextStyle(color: Colors.red, fontSize: 13)),
            ],

            if (resultat != null && !resultat.disponible) ...[
              const SizedBox(height: 14),
              Text(
                resultat.avertissements.isNotEmpty
                    ? resultat.avertissements.first
                    : 'Aucun aliment n’a pu être identifié. Ajoute toi-même ce que contient l’assiette.',
                style: const TextStyle(fontSize: 13, color: MaviohColors.textSecondary),
              ),
            ],

            // La revue s'ouvre dès que l'analyse a répondu, même sans rien reconnaître.
            if (resultat != null) ...[
              const SizedBox(height: 18),
              for (final ligne in _lignes)
                KeyedSubtree(
                  key: ObjectKey(ligne),
                  child: ligne.ajout != null ? _ligneAjouteeWidget(ligne) : _ligneWidget(ligne),
                ),
              OutlinedButton.icon(
                onPressed: _enregistre ? null : _ajouter,
                icon: const Icon(Icons.add_rounded),
                label: const Text('Ajouter un aliment oublié'),
              ),
              const SizedBox(height: 12),
              FilledButton(
                onPressed: _enregistre || !_lignes.any((l) => l.retenue) ? null : _enregistrer,
                child: Text(_enregistre ? 'Enregistrement…' : 'Vérifié, ajouter au repas'),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _ligneAjouteeWidget(_LigneEditable ligne) {
    final ajout = ligne.ajout!;
    final quantite = ligne.quantiteSaisie;
    // Les calories du choix portent sur sa quantité d'origine : on les met à l'échelle.
    final kcal = ajout.calories == null || quantite == null || ajout.input.quantity <= 0
        ? null
        : ajout.calories! * quantite / ajout.input.quantity;

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          const SizedBox(width: 48, child: Icon(Icons.edit_outlined, size: 20, color: MaviohColors.muted)),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(ajout.label, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
                Text(
                  kcal == null ? 'Ajouté par toi' : 'Ajouté par toi · ${fmtKcal(kcal)}',
                  style: const TextStyle(fontSize: 11.5, color: MaviohColors.muted),
                ),
              ],
            ),
          ),
          SizedBox(
            width: 72,
            child: TextFormField(
              initialValue: ligne.quantite,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(isDense: true),
              onChanged: (v) => setState(() => ligne.quantite = v),
            ),
          ),
          const SizedBox(width: 6),
          Text(unitLabelShort(ajout.input.unit), style: const TextStyle(color: MaviohColors.muted)),
          IconButton(
            tooltip: 'Retirer ${ajout.label}',
            onPressed: _enregistre ? null : () => setState(() => _lignes = [..._lignes]..remove(ligne)),
            icon: const Icon(Icons.close_rounded),
          ),
        ],
      ),
    );
  }

  Widget _ligneWidget(_LigneEditable ligne) {
    final plate = ligne.ligne!;
    final food = plate.food;

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Checkbox(
            value: ligne.retenue,
            onChanged: (v) => setState(() => ligne.retenue = v ?? true),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  food?.name ?? plate.nom,
                  style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
                ),
                Text(
                  food != null ? 'Fiche : ${food.name}' : 'Valeurs estimées, à vérifier',
                  style: const TextStyle(fontSize: 11.5, color: MaviohColors.muted),
                ),
              ],
            ),
          ),
          SizedBox(
            width: 72,
            child: TextFormField(
              initialValue: ligne.quantite,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(isDense: true),
              onChanged: (v) => ligne.quantite = v,
            ),
          ),
          const SizedBox(width: 6),
          Text(plate.unite, style: const TextStyle(color: MaviohColors.muted)),
        ],
      ),
    );
  }
}
