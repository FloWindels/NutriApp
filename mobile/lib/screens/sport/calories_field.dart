import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/formatters.dart';
import '../../services/sport_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/macro_pill.dart';

/// Calories field auto-filled from `POST /sport/calories/estimate`.
///
/// Shows an « auto » pill while the value comes from the server estimate and
/// switches to « manuel » as soon as the user edits it (addendum §B/§D).
class SportCaloriesField extends StatefulWidget {
  const SportCaloriesField({
    super.key,
    required this.durationMin,
    required this.onChanged,
    this.sportId,
    this.sportName,
    this.intensity,
    this.service,
    this.errorText,
    this.label = 'Calories brûlées',
  });

  /// Duration used for the estimate.
  final int durationMin;

  /// `(calories, manual)` — `calories` is null when the field is empty.
  final void Function(double? calories, bool manual) onChanged;

  final int? sportId;
  final String? sportName;
  final String? intensity;
  final SportService? service;
  final String? errorText;
  final String label;

  @override
  State<SportCaloriesField> createState() => _SportCaloriesFieldState();
}

class _SportCaloriesFieldState extends State<SportCaloriesField> {
  final TextEditingController _controller = TextEditingController();
  SportService get _service => widget.service ?? SportService();

  Timer? _debounce;
  bool _manual = false;
  bool _estimating = false;
  String? _estimateError;
  int _requestId = 0;

  @override
  void initState() {
    super.initState();
    _scheduleEstimate(immediate: true);
  }

  @override
  void didUpdateWidget(covariant SportCaloriesField oldWidget) {
    super.didUpdateWidget(oldWidget);
    final changed = oldWidget.durationMin != widget.durationMin ||
        oldWidget.intensity != widget.intensity ||
        oldWidget.sportId != widget.sportId ||
        oldWidget.sportName != widget.sportName;
    if (changed && !_manual) _scheduleEstimate();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _controller.dispose();
    super.dispose();
  }

  void _scheduleEstimate({bool immediate = false}) {
    _debounce?.cancel();
    if (widget.durationMin <= 0) return;
    if (immediate) {
      _estimate();
    } else {
      _debounce = Timer(const Duration(milliseconds: 400), _estimate);
    }
  }

  Future<void> _estimate() async {
    if (_manual || widget.durationMin <= 0) return;
    final id = ++_requestId;
    setState(() {
      _estimating = true;
      _estimateError = null;
    });
    try {
      final estimate = await _service.estimateCalories(
        sportId: widget.sportId,
        sportName: widget.sportName,
        durationMin: widget.durationMin,
        intensity: widget.intensity,
      );
      if (!mounted || id != _requestId || _manual) return;
      _controller.text = estimate.calories.round().toString();
      setState(() => _estimating = false);
      widget.onChanged(estimate.calories, false);
    } on ApiException catch (e) {
      if (!mounted || id != _requestId) return;
      setState(() {
        _estimating = false;
        _estimateError = e.message;
      });
    }
  }

  void _onEdited(String text) {
    if (!_manual) setState(() => _manual = true);
    widget.onChanged(parseDecimal(text), true);
  }

  void _backToAuto() {
    setState(() {
      _manual = false;
      _estimateError = null;
    });
    widget.onChanged(null, false);
    _scheduleEstimate(immediate: true);
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Text(
              widget.label,
              style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary),
            ),
            const SizedBox(width: 8),
            if (_manual)
              const TonePill(label: 'manuel', tone: MaviohColors.slate, icon: Icons.edit_outlined)
            else
              const EstimatePill(label: 'auto'),
            const Spacer(),
            if (_estimating)
              const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
            else if (_manual)
              IconButton(
                tooltip: 'Revenir au calcul automatique',
                onPressed: _backToAuto,
                icon: const Icon(Icons.auto_awesome_outlined, size: 20),
                constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
              ),
          ],
        ),
        const SizedBox(height: 6),
        TextField(
          controller: _controller,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))],
          onChanged: _onEdited,
          decoration: InputDecoration(
            hintText: 'kcal',
            suffixText: 'kcal',
            errorText: widget.errorText ?? _estimateError,
          ),
        ),
        const SizedBox(height: 6),
        Text(
          _manual
              ? 'Valeur saisie à la main : elle sera enregistrée telle quelle.'
              : 'Estimation à partir de ton poids, du sport et de la durée. Tu peux la corriger.',
          style: const TextStyle(fontSize: 11.5, color: MaviohColors.muted, height: 1.35),
        ),
      ],
    );
  }
}
