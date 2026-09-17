import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import '../core/formatters.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../models/household.dart';
import '../models/recipe.dart';
import '../services/household_service.dart';
import '../theme/app_theme.dart';
import '../widgets/app_card.dart';
import '../widgets/confirm_dialog.dart';
import '../widgets/error_state.dart';
import '../widgets/fm7_recipe_picker.dart';
import '../widgets/loading_state.dart';
import '../widgets/macro_pill.dart';
import '../widgets/section_header.dart';
import '../widgets/status_banner.dart';

/// Famille / foyer (§10 + §16.4).
///
/// Rendue dans l’onglet Plus : pas de `Scaffold` avec `AppBar`.
class FamilyScreen extends StatefulWidget {
  const FamilyScreen({super.key, this.onNavigate});

  /// Navigation rapide vers une autre section (slug).
  final ValueChanged<String>? onNavigate;

  @override
  State<FamilyScreen> createState() => _FamilyScreenState();
}

class _FamilyScreenState extends State<FamilyScreen> {
  static const String _cacheKey = 'household';

  final HouseholdService _service = HouseholdService();

  Household? _household;
  bool _loaded = false;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    final cached = Session.instance.cached(_cacheKey);
    if (cached != null) {
      _household = HouseholdService.parse(cached.data);
      _loaded = true;
      _loading = false;
      if (cached.isStale()) _load(silent: true);
    } else {
      _load();
    }
  }

  Future<void> _load({bool silent = false}) async {
    if (!silent) {
      setState(() {
        _loading = !_loaded;
        _error = null;
      });
    }
    try {
      final json = await _service.raw();
      if (!mounted) return;
      Session.instance.put(_cacheKey, json);
      setState(() {
        _household = HouseholdService.parse(json);
        _loaded = true;
        _loading = false;
        _error = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        if (!_loaded) {
          _error = e.message;
        } else {
          _snack(e.message);
        }
      });
    }
  }

  void _snack(String message) {
    ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(message)));
  }

  void _invalidate() {
    Session.instance.invalidate(_cacheKey);
    Session.instance.invalidate('stock');
    Session.instance.invalidate('dashboard');
    Session.instance.invalidatePrefix('meals');
    Session.instance.invalidate('shopping');
  }

  Future<void> _refreshMe() async {
    try {
      await Session.instance.refreshMe();
    } on ApiException {
      // Le rafraîchissement de l’utilisateur est accessoire.
    }
  }

  // ----- Mutations ----------------------------------------------------------

  Future<void> _create(String name) async {
    final household = await _service.create(name);
    if (!mounted) return;
    _invalidate();
    await _refreshMe();
    if (!mounted) return;
    setState(() {
      _household = household;
      _loaded = true;
    });
    _snack('Foyer « ${household.name} » créé.');
  }

  Future<void> _join(String code) async {
    final preview = await _service.preview(code);
    if (!mounted) return;
    final ok = await ConfirmDialog.show(
      context,
      title: 'Rejoindre « ${preview.name} » ?',
      message: 'Ce foyer compte ${preview.membersCount} membre${preview.membersCount > 1 ? 's' : ''}. '
          'Ton stock personnel sera fusionné avec celui du foyer et visible par ses membres.',
      confirmLabel: 'Rejoindre',
      icon: Icons.group_add_outlined,
    );
    if (!ok || !mounted) return;
    final result = await _service.join(code);
    if (!mounted) return;
    _invalidate();
    await _refreshMe();
    if (!mounted) return;
    setState(() {
      _household = result.household;
      _loaded = true;
    });
    _snack('${result.mergedStockItems} article${result.mergedStockItems > 1 ? 's' : ''} fusionné'
        '${result.mergedStockItems > 1 ? 's' : ''}.');
  }

  Future<void> _copyCode(String code) async {
    await Clipboard.setData(ClipboardData(text: code));
    if (!mounted) return;
    _snack('Code copié.');
  }

  Future<void> _regenerateCode() async {
    final ok = await ConfirmDialog.show(
      context,
      title: 'Générer un nouveau code ?',
      message: 'L’ancien code ne fonctionnera plus. Les membres déjà présents ne sont pas affectés.',
      confirmLabel: 'Nouveau code',
      icon: Icons.autorenew_rounded,
    );
    if (!ok || !mounted) return;
    try {
      final household = await _service.regenerateCode();
      if (!mounted) return;
      _invalidate();
      setState(() => _household = household);
      _snack('Nouveau code d’invitation généré.');
    } on ApiException catch (e) {
      if (!mounted) return;
      _snack(e.message);
    }
  }

  Future<void> _removeMember(HouseholdMember member) async {
    final ok = await ConfirmDialog.show(
      context,
      title: 'Retirer ${member.name} ?',
      message: 'Cette personne ne verra plus le stock ni la liste du foyer. Elle ne prend rien avec elle.',
      confirmLabel: 'Retirer',
      destructive: true,
      icon: Icons.person_remove_outlined,
    );
    if (!ok || !mounted) return;
    try {
      await _service.removeMember(member.userId);
      if (!mounted) return;
      _invalidate();
      _snack('${member.name} a été retiré du foyer.');
      await _load(silent: true);
    } on ApiException catch (e) {
      if (!mounted) return;
      _snack(e.message);
    }
  }

  Future<void> _setShareProfile(bool value) async {
    try {
      await _service.setShareProfile(value);
      if (!mounted) return;
      _invalidate();
      await _load(silent: true);
    } on ApiException catch (e) {
      if (!mounted) return;
      _snack(e.message);
      await _load(silent: true);
    }
  }

  Future<void> _leave() async {
    final ok = await ConfirmDialog.show(
      context,
      title: 'Quitter le foyer ?',
      message: 'Tu perdras l’accès au stock, à la liste de courses et au planning partagés. '
          'Tu ne prends aucun article avec toi.',
      confirmLabel: 'Quitter',
      destructive: true,
      icon: Icons.logout_rounded,
    );
    if (!ok || !mounted) return;
    try {
      final message = await _service.leave();
      if (!mounted) return;
      _invalidate();
      await _refreshMe();
      if (!mounted) return;
      setState(() => _household = null);
      _snack(message);
    } on ApiException catch (e) {
      if (!mounted) return;
      _snack(e.message);
    }
  }

  Future<void> _dissolve() async {
    final ok = await ConfirmDialog.show(
      context,
      title: 'Supprimer le foyer ?',
      message: 'Le foyer est dissous pour tout le monde. Le stock, la liste de courses et le planning '
          'reviennent au propriétaire.',
      confirmLabel: AppStrings.delete,
      destructive: true,
      icon: Icons.delete_outline_rounded,
    );
    if (!ok || !mounted) return;
    try {
      final message = await _service.dissolve();
      if (!mounted) return;
      _invalidate();
      await _refreshMe();
      if (!mounted) return;
      setState(() => _household = null);
      _snack(message);
    } on ApiException catch (e) {
      if (!mounted) return;
      _snack(e.message);
    }
  }

  // ----- Build --------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    if (_loading && !_loaded) return const LoadingState(skeleton: true, skeletonCount: 2);
    if (_error != null && !_loaded) return ErrorState(message: _error!, onRetry: _load);

    final household = _household;
    return RefreshIndicator(
      onRefresh: () => _load(silent: true),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 96),
        children: household == null ? _noHousehold() : _withHousehold(household),
      ),
    );
  }

  List<Widget> _noHousehold() {
    final firstName = Session.instance.user?.firstName ?? '';
    return [
      const SectionHeader(
        eyebrow: 'Foyer',
        title: 'Cuisinez à plusieurs',
        subtitle: 'Un foyer partage le stock, la liste de courses et le planning des repas.',
      ),
      _CreateHouseholdCard(initialName: firstName.isEmpty ? 'Mon foyer' : 'Foyer de $firstName', onSubmit: _create),
      const SizedBox(height: 14),
      _JoinHouseholdCard(onSubmit: _join),
    ];
  }

  List<Widget> _withHousehold(Household household) {
    final me = Session.instance.user;
    final myMember = me == null ? null : household.member(me.id);
    return [
      SectionHeader(
        eyebrow: 'Foyer',
        title: household.name,
        subtitle: '${household.members.length} membre${household.members.length > 1 ? 's' : ''} · '
            '${household.stockItemsCount} article${household.stockItemsCount > 1 ? 's' : ''} en stock partagé',
      ),
      if (household.isOwner && household.inviteCode != null) ...[
        _InviteCodeCard(
          code: household.inviteCode!,
          onCopy: () => _copyCode(household.inviteCode!),
          onRegenerate: _regenerateCode,
        ),
        const SizedBox(height: 14),
      ],
      const SectionHeader(eyebrow: 'Membres', title: 'Qui compose ton foyer'),
      for (final member in household.members) ...[
        _MemberTile(
          member: member,
          canRemove: household.isOwner && !member.isOwner,
          onRemove: () => _removeMember(member),
        ),
        const SizedBox(height: 10),
      ],
      const SizedBox(height: 8),
      _CommonMealCard(service: _service, onSaved: () => _load(silent: true)),
      const SizedBox(height: 14),
      AppCard(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 4),
        child: Material(
          type: MaterialType.transparency,
          child: SwitchListTile.adaptive(
            contentPadding: EdgeInsets.zero,
            value: myMember?.shareProfile ?? true,
            onChanged: _setShareProfile,
            title: const Text('Partager mon profil', style: TextStyle(fontWeight: FontWeight.w700)),
            subtitle: const Text(
              'Les membres voient tes cibles caloriques et ton régime pour ajuster les repas communs.',
              style: TextStyle(fontSize: 12.5, color: MaviohColors.muted),
            ),
          ),
        ),
      ),
      const SizedBox(height: 18),
      const SectionHeader(eyebrow: 'Zone sensible', title: 'Quitter ou supprimer'),
      AppCard(
        borderColor: MaviohColors.error.withValues(alpha: 0.3),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Si le foyer est supprimé, le stock, la liste de courses et le planning reviennent au propriétaire.',
              style: TextStyle(fontSize: 12.5, color: MaviohColors.textSecondary, height: 1.4),
            ),
            const SizedBox(height: 12),
            SizedBox(
              height: 48,
              width: double.infinity,
              child: OutlinedButton.icon(
                onPressed: _leave,
                style: OutlinedButton.styleFrom(foregroundColor: MaviohColors.error),
                icon: const Icon(Icons.logout_rounded, size: 18),
                label: const Text('Quitter le foyer'),
              ),
            ),
            if (household.isOwner) ...[
              const SizedBox(height: 10),
              SizedBox(
                height: 48,
                width: double.infinity,
                child: FilledButton.icon(
                  onPressed: _dissolve,
                  style: FilledButton.styleFrom(backgroundColor: MaviohColors.error),
                  icon: const Icon(Icons.delete_outline_rounded, size: 18),
                  label: const Text('Supprimer le foyer'),
                ),
              ),
            ],
          ],
        ),
      ),
    ];
  }
}

// ----- Sans foyer -----------------------------------------------------------

class _CreateHouseholdCard extends StatefulWidget {
  const _CreateHouseholdCard({required this.initialName, required this.onSubmit});

  final String initialName;
  final Future<void> Function(String name) onSubmit;

  @override
  State<_CreateHouseholdCard> createState() => _CreateHouseholdCardState();
}

class _CreateHouseholdCardState extends State<_CreateHouseholdCard> {
  late final TextEditingController _name = TextEditingController(text: widget.initialName);

  bool _saving = false;
  String? _error;
  String? _fieldError;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final name = _name.text.trim();
    if (name.length < 2) {
      setState(() => _fieldError = 'Donne un nom d’au moins 2 caractères.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
      _fieldError = null;
    });
    try {
      await widget.onSubmit(name);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.message;
        _fieldError = e.fieldError('name');
      });
      return;
    }
    if (mounted) setState(() => _saving = false);
  }

  @override
  Widget build(BuildContext context) {
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Créer un foyer',
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: MaviohColors.text),
          ),
          const SizedBox(height: 4),
          const Text(
            'Tu deviens propriétaire et tu invites les autres avec un code à 8 caractères.',
            style: TextStyle(fontSize: 12.5, color: MaviohColors.muted, height: 1.4),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            StatusBanner.error(_error!),
          ],
          const SizedBox(height: 14),
          TextField(
            controller: _name,
            textCapitalization: TextCapitalization.sentences,
            decoration: InputDecoration(labelText: 'Nom du foyer', errorText: _fieldError),
          ),
          const SizedBox(height: 14),
          SizedBox(
            height: 50,
            width: double.infinity,
            child: FilledButton.icon(
              onPressed: _saving ? null : _submit,
              icon: _saving
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
                  : const Icon(Icons.home_outlined),
              label: const Text('Créer un foyer'),
            ),
          ),
        ],
      ),
    );
  }
}

class _JoinHouseholdCard extends StatefulWidget {
  const _JoinHouseholdCard({required this.onSubmit});

  final Future<void> Function(String code) onSubmit;

  @override
  State<_JoinHouseholdCard> createState() => _JoinHouseholdCardState();
}

class _JoinHouseholdCardState extends State<_JoinHouseholdCard> {
  final TextEditingController _code = TextEditingController();

  bool _saving = false;
  String? _error;
  String? _fieldError;

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  bool get _ready => _code.text.trim().length == 8;

  Future<void> _paste() async {
    final data = await Clipboard.getData(Clipboard.kTextPlain);
    final text = data?.text;
    if (text == null || !mounted) return;
    final cleaned = text.toUpperCase().replaceAll(RegExp('[^A-Z0-9]'), '');
    _code.text = cleaned.length > 8 ? cleaned.substring(0, 8) : cleaned;
    setState(() => _fieldError = null);
  }

  Future<void> _submit() async {
    setState(() {
      _saving = true;
      _error = null;
      _fieldError = null;
    });
    try {
      await widget.onSubmit(_code.text.trim());
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.message;
        _fieldError = e.fieldError('invite_code');
      });
      return;
    }
    if (mounted) setState(() => _saving = false);
  }

  @override
  Widget build(BuildContext context) {
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Rejoindre un foyer',
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: MaviohColors.text),
          ),
          const SizedBox(height: 4),
          const Text(
            'Saisis le code à 8 caractères que le propriétaire t’a transmis.',
            style: TextStyle(fontSize: 12.5, color: MaviohColors.muted, height: 1.4),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            StatusBanner.error(_error!),
          ],
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _code,
                  textCapitalization: TextCapitalization.characters,
                  autocorrect: false,
                  maxLength: 8,
                  style: const TextStyle(
                    fontFamily: 'monospace',
                    fontSize: 20,
                    letterSpacing: 4,
                    fontWeight: FontWeight.w800,
                  ),
                  inputFormatters: [
                    FilteringTextInputFormatter.allow(RegExp('[A-Za-z0-9]')),
                    LengthLimitingTextInputFormatter(8),
                    TextInputFormatter.withFunction(
                      (oldValue, newValue) => newValue.copyWith(text: newValue.text.toUpperCase()),
                    ),
                  ],
                  onChanged: (_) => setState(() => _fieldError = null),
                  decoration: InputDecoration(
                    labelText: 'Code d’invitation',
                    counterText: '',
                    errorText: _fieldError,
                  ),
                ),
              ),
              const SizedBox(width: 10),
              SizedBox(
                height: 48,
                child: OutlinedButton.icon(
                  onPressed: _paste,
                  icon: const Icon(Icons.content_paste_rounded, size: 18),
                  label: const Text('Coller'),
                ),
              ),
            ],
          ),
          const SizedBox(height: 4),
          SizedBox(
            height: 50,
            width: double.infinity,
            child: FilledButton.icon(
              onPressed: (_saving || !_ready) ? null : _submit,
              icon: _saving
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
                  : const Icon(Icons.group_add_outlined),
              label: const Text('Rejoindre un foyer'),
            ),
          ),
        ],
      ),
    );
  }
}

// ----- Avec foyer -----------------------------------------------------------

class _InviteCodeCard extends StatelessWidget {
  const _InviteCodeCard({required this.code, required this.onCopy, required this.onRegenerate});

  final String code;
  final VoidCallback onCopy;
  final VoidCallback onRegenerate;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'CODE D’INVITATION',
            style: TextStyle(fontSize: 11, letterSpacing: 1.2, fontWeight: FontWeight.w800, color: MaviohColors.muted),
          ),
          const SizedBox(height: 8),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              code,
              style: const TextStyle(
                fontFamily: 'monospace',
                fontSize: 30,
                letterSpacing: 6,
                fontWeight: FontWeight.w800,
                color: MaviohColors.text,
              ),
            ),
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: SizedBox(
                  height: 48,
                  child: FilledButton.icon(
                    onPressed: onCopy,
                    icon: const Icon(Icons.copy_rounded, size: 18),
                    label: const Text('Copier'),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: SizedBox(
                  height: 48,
                  child: OutlinedButton.icon(
                    onPressed: onRegenerate,
                    icon: const Icon(Icons.autorenew_rounded, size: 18),
                    label: const Text('Nouveau code'),
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _MemberTile extends StatelessWidget {
  const _MemberTile({required this.member, required this.canRemove, required this.onRemove});

  final HouseholdMember member;
  final bool canRemove;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final details = <String>[
      if (member.caloriesCibles != null) fmtKcal(member.caloriesCibles),
      if (member.regime != null) AppStrings.regimeLabels[member.regime] ?? member.regime!,
    ];
    return AppCard(
      padding: const EdgeInsets.fromLTRB(16, 12, 8, 12),
      child: Row(
        children: [
          CircleAvatar(
            radius: 20,
            backgroundColor: MaviohColors.tint(MaviohColors.primary),
            child: Text(
              member.name.isEmpty ? '?' : member.name.characters.first.toUpperCase(),
              style: const TextStyle(fontWeight: FontWeight.w800, color: MaviohColors.primary),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Flexible(
                      child: Text(
                        member.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
                      ),
                    ),
                    const SizedBox(width: 8),
                    TonePill(
                      label: member.roleLabel,
                      tone: member.isOwner ? MaviohColors.primary : MaviohColors.slate,
                    ),
                  ],
                ),
                const SizedBox(height: 3),
                Text(
                  member.shareProfile
                      ? (details.isEmpty ? 'Profil partagé' : details.join(' · '))
                      : 'Profil non partagé',
                  style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted),
                ),
              ],
            ),
          ),
          if (canRemove)
            IconButton(
              tooltip: 'Retirer ${member.name}',
              onPressed: onRemove,
              icon: const Icon(Icons.person_remove_outlined, color: MaviohColors.error),
              constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
            ),
        ],
      ),
    );
  }
}

/// « Repas commun » : recette + type de repas → aperçu par membre → enregistrement.
class _CommonMealCard extends StatefulWidget {
  const _CommonMealCard({required this.service, required this.onSaved});

  final HouseholdService service;
  final VoidCallback onSaved;

  @override
  State<_CommonMealCard> createState() => _CommonMealCardState();
}

class _CommonMealCardState extends State<_CommonMealCard> {
  Recipe? _recipe;
  String _mealType = 'diner';
  CommonMealPreview? _preview;
  Map<int, double> _portions = <int, double>{};

  bool _loadingPreview = false;
  bool _saving = false;
  String? _error;
  String? _success;

  Future<void> _pickRecipe() async {
    final recipe = await Fm7RecipePickerSheet.show(context, selectedId: _recipe?.id);
    if (recipe == null || !mounted) return;
    setState(() {
      _recipe = recipe;
      _preview = null;
      _success = null;
    });
    await _loadPreview();
  }

  Future<void> _loadPreview() async {
    final recipe = _recipe;
    if (recipe == null) return;
    setState(() {
      _loadingPreview = true;
      _error = null;
      _success = null;
    });
    try {
      final preview = await widget.service.commonMealPreview(recipeId: recipe.id, mealType: _mealType);
      if (!mounted) return;
      setState(() {
        _preview = preview;
        _portions = {for (final m in preview.members) m.userId: m.portions};
        _loadingPreview = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loadingPreview = false;
        _error = e.message;
      });
    }
  }

  void _step(CommonMealMember member, double delta) {
    final current = _portions[member.userId] ?? member.portions;
    final next = (current + delta).clamp(0.5, 2.0);
    setState(() => _portions[member.userId] = next);
  }

  Future<void> _save() async {
    final preview = _preview;
    if (preview == null) return;
    setState(() {
      _saving = true;
      _error = null;
      _success = null;
    });
    try {
      final created = await widget.service.commonMeal(
        recipeId: preview.recipeId,
        mealType: _mealType,
        date: today(),
        portions: _portions,
      );
      if (!mounted) return;
      Session.instance.invalidatePrefix('meals');
      Session.instance.invalidate('dashboard');
      setState(() {
        _saving = false;
        _success = 'Repas enregistré pour ${created.length} membre${created.length > 1 ? 's' : ''}.';
      });
      widget.onSaved();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final preview = _preview;
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Repas commun',
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: MaviohColors.text),
          ),
          const SizedBox(height: 4),
          const Text(
            'Choisis une recette : Mavi’oh répartit les portions selon les cibles de chacun.',
            style: TextStyle(fontSize: 12.5, color: MaviohColors.muted, height: 1.4),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            StatusBanner.error(_error!),
          ],
          if (_success != null) ...[
            const SizedBox(height: 12),
            StatusBanner.success(_success!),
          ],
          const SizedBox(height: 14),
          SizedBox(
            height: 52,
            child: OutlinedButton.icon(
              onPressed: _pickRecipe,
              icon: const Icon(Icons.menu_book_outlined, size: 18),
              label: Align(
                alignment: Alignment.centerLeft,
                child: Text(_recipe?.title ?? 'Choisir une recette', maxLines: 1, overflow: TextOverflow.ellipsis),
              ),
            ),
          ),
          const SizedBox(height: 12),
          SegmentedButton<String>(
            segments: [
              for (final type in AppStrings.mealTypeOrder)
                ButtonSegment<String>(value: type, label: Text(AppStrings.mealType(type))),
            ],
            selected: {_mealType},
            showSelectedIcon: false,
            onSelectionChanged: (value) {
              setState(() => _mealType = value.first);
              if (_recipe != null) _loadPreview();
            },
          ),
          if (_loadingPreview) ...[
            const SizedBox(height: 16),
            const LoadingState(),
          ],
          if (preview != null && !_loadingPreview) ...[
            const SizedBox(height: 14),
            for (final member in preview.members) ...[
              _PortionRow(
                member: member,
                portions: _portions[member.userId] ?? member.portions,
                perServingKcal: preview.perServing.calories,
                onStep: (delta) => _step(member, delta),
              ),
              const SizedBox(height: 8),
            ],
            const SizedBox(height: 8),
            SizedBox(
              height: 50,
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: _saving ? null : _save,
                icon: _saving
                    ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
                    : const Icon(Icons.group_outlined),
                label: const Text('Enregistrer pour tout le monde'),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _PortionRow extends StatelessWidget {
  const _PortionRow({
    required this.member,
    required this.portions,
    required this.perServingKcal,
    required this.onStep,
  });

  final CommonMealMember member;
  final double portions;
  final double? perServingKcal;
  final ValueChanged<double> onStep;

  @override
  Widget build(BuildContext context) {
    final kcal = perServingKcal == null ? member.calories : perServingKcal! * portions;
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 8, 6, 8),
      decoration: BoxDecoration(
        color: MaviohColors.surface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: MaviohColors.border),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  member.name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w800, color: MaviohColors.text),
                ),
                const SizedBox(height: 2),
                Row(
                  children: [
                    Flexible(
                      child: Text(
                        fmtKcal(kcal),
                        style: const TextStyle(fontSize: 12.5, color: MaviohColors.muted),
                      ),
                    ),
                    if (member.label != null) ...[
                      const SizedBox(width: 6),
                      EstimatePill(label: member.label!),
                    ] else if (member.isEstimate) ...[
                      const SizedBox(width: 6),
                      const EstimatePill(),
                    ],
                  ],
                ),
              ],
            ),
          ),
          IconButton(
            tooltip: 'Moins de portions pour ${member.name}',
            onPressed: portions <= 0.5 ? null : () => onStep(-0.5),
            icon: const Icon(Icons.remove_circle_outline_rounded),
            constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
          ),
          SizedBox(
            width: 40,
            child: Text(
              fmtDecimal(portions),
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: MaviohColors.text),
            ),
          ),
          IconButton(
            tooltip: 'Plus de portions pour ${member.name}',
            onPressed: portions >= 2.0 ? null : () => onStep(0.5),
            icon: const Icon(Icons.add_circle_outline_rounded),
            constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
          ),
        ],
      ),
    );
  }
}
