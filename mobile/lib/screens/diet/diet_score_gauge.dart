import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/formatters.dart';
import '../../theme/app_theme.dart';

/// Jauge de score du régime reconnu (§16.4 « DietScreen »).
///
/// Arc de 240° dessiné au [CustomPainter] : piste grise + arc coloré
/// proportionnel au score, avec le pourcentage et le statut au centre.
class DietScoreGauge extends StatelessWidget {
  const DietScoreGauge({
    super.key,
    required this.scorePct,
    required this.color,
    this.statutLabel,
    this.size = 148,
  });

  /// Score sur 100 (`null` → jauge vide, « — » au centre).
  final double? scorePct;

  /// Couleur de l’arc (dérivée du statut).
  final Color color;

  /// Libellé affiché sous le pourcentage.
  final String? statutLabel;

  final double size;

  @override
  Widget build(BuildContext context) {
    final value = scorePct == null ? 0.0 : (scorePct! / 100).clamp(0.0, 1.0).toDouble();
    return Semantics(
      label: scorePct == null
          ? 'Score du régime non disponible'
          : 'Score du régime : ${fmtPct(scorePct)}',
      child: SizedBox(
        width: size,
        height: size * 0.82,
        child: CustomPaint(
          painter: _DietScoreGaugePainter(value: value, color: color),
          child: Center(
            child: Padding(
              padding: EdgeInsets.only(top: size * 0.08),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Text(
                      scorePct == null ? '—' : fmtPct(scorePct),
                      style: TextStyle(
                        fontSize: size * 0.22,
                        fontWeight: FontWeight.w800,
                        color: MaviohColors.text,
                        height: 1.1,
                      ),
                    ),
                  ),
                  if (statutLabel != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Text(
                        statutLabel!,
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          fontSize: size * 0.085,
                          fontWeight: FontWeight.w700,
                          color: color,
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _DietScoreGaugePainter extends CustomPainter {
  _DietScoreGaugePainter({required this.value, required this.color});

  /// Fraction 0..1 de l’arc coloré.
  final double value;
  final Color color;

  static const double _startAngle = math.pi * 0.85;
  static const double _sweepAngle = math.pi * 1.3;

  @override
  void paint(Canvas canvas, Size size) {
    final stroke = size.width * 0.085;
    final radius = (math.min(size.width, size.height * 1.2) - stroke) / 2;
    final center = Offset(size.width / 2, size.height / 2 + radius * 0.16);
    final rect = Rect.fromCircle(center: center, radius: radius);

    final track = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke
      ..strokeCap = StrokeCap.round
      ..color = MaviohColors.border;
    canvas.drawArc(rect, _startAngle, _sweepAngle, false, track);

    if (value <= 0) return;
    final arc = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke
      ..strokeCap = StrokeCap.round
      ..color = color;
    canvas.drawArc(rect, _startAngle, _sweepAngle * value, false, arc);
  }

  @override
  bool shouldRepaint(_DietScoreGaugePainter old) => old.value != value || old.color != color;
}
