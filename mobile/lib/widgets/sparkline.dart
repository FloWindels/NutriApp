import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// Tiny line chart (no axes) for weight history and similar short series.
class Sparkline extends StatelessWidget {
  final List<double> values;
  final Color color;
  final double height;
  final bool fill;
  final double strokeWidth;
  final double? referenceValue;

  const Sparkline({
    super.key,
    required this.values,
    this.color = MaviohColors.primary,
    this.height = 56,
    this.fill = true,
    this.strokeWidth = 2.2,
    this.referenceValue,
  });

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: height,
      width: double.infinity,
      child: CustomPaint(
        painter: _SparklinePainter(
          values: values,
          color: color,
          fill: fill,
          strokeWidth: strokeWidth,
          referenceValue: referenceValue,
        ),
      ),
    );
  }
}

class _SparklinePainter extends CustomPainter {
  final List<double> values;
  final Color color;
  final bool fill;
  final double strokeWidth;
  final double? referenceValue;

  _SparklinePainter({
    required this.values,
    required this.color,
    required this.fill,
    required this.strokeWidth,
    this.referenceValue,
  });

  @override
  void paint(Canvas canvas, Size size) {
    if (values.isEmpty) {
      final paint = Paint()
        ..color = MaviohColors.border
        ..strokeWidth = 1;
      canvas.drawLine(Offset(0, size.height / 2), Offset(size.width, size.height / 2), paint);
      return;
    }

    final all = [...values, ?referenceValue];
    var min = all.reduce((a, b) => a < b ? a : b);
    var max = all.reduce((a, b) => a > b ? a : b);
    if (max - min < 0.001) {
      min -= 1;
      max += 1;
    }
    final pad = (max - min) * 0.15;
    min -= pad;
    max += pad;

    const inset = 4.0;
    final w = size.width - inset * 2;
    final h = size.height - inset * 2;

    double x(int i) => values.length == 1 ? inset + w / 2 : inset + w * i / (values.length - 1);
    double y(double v) => inset + h - ((v - min) / (max - min)) * h;

    if (referenceValue != null) {
      final refPaint = Paint()
        ..color = MaviohColors.muted.withValues(alpha: 0.5)
        ..strokeWidth = 1;
      final ry = y(referenceValue!);
      var dx = inset;
      while (dx < size.width - inset) {
        canvas.drawLine(Offset(dx, ry), Offset((dx + 4).clamp(0, size.width - inset), ry), refPaint);
        dx += 8;
      }
    }

    final path = Path()..moveTo(x(0), y(values[0]));
    for (var i = 1; i < values.length; i++) {
      path.lineTo(x(i), y(values[i]));
    }

    if (fill && values.length > 1) {
      final fillPath = Path.from(path)
        ..lineTo(x(values.length - 1), size.height)
        ..lineTo(x(0), size.height)
        ..close();
      canvas.drawPath(
        fillPath,
        Paint()
          ..shader = LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [color.withValues(alpha: 0.25), color.withValues(alpha: 0.0)],
          ).createShader(Rect.fromLTWH(0, 0, size.width, size.height)),
      );
    }

    canvas.drawPath(
      path,
      Paint()
        ..color = color
        ..style = PaintingStyle.stroke
        ..strokeWidth = strokeWidth
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round,
    );

    final last = Offset(x(values.length - 1), y(values.last));
    canvas.drawCircle(last, strokeWidth + 1.5, Paint()..color = Colors.white);
    canvas.drawCircle(last, strokeWidth + 0.5, Paint()..color = color);
  }

  @override
  bool shouldRepaint(covariant _SparklinePainter old) =>
      old.values != values || old.color != color || old.fill != fill || old.referenceValue != referenceValue;
}
