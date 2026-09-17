import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// White rounded card with a soft border and shadow (radius 24 by default, 28 for hero cards).
class AppCard extends StatelessWidget {
  final Widget child;
  final EdgeInsetsGeometry padding;
  final double radius;
  final VoidCallback? onTap;
  final Color color;
  final Color? borderColor;
  final bool shadow;
  final EdgeInsetsGeometry? margin;

  const AppCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(18),
    this.radius = AppTheme.radiusCard,
    this.onTap,
    this.color = Colors.white,
    this.borderColor,
    this.shadow = true,
    this.margin,
  });

  /// Larger hero variant (radius 28).
  const AppCard.hero({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(20),
    this.onTap,
    this.color = Colors.white,
    this.borderColor,
    this.shadow = true,
    this.margin,
  }) : radius = 28;

  @override
  Widget build(BuildContext context) {
    final decoration = BoxDecoration(
      color: color,
      borderRadius: BorderRadius.circular(radius),
      border: Border.all(color: borderColor ?? MaviohColors.border),
      boxShadow: shadow
          ? const [
              BoxShadow(color: Color(0x0F0F172A), blurRadius: 18, offset: Offset(0, 10)),
            ]
          : null,
    );

    Widget content = Padding(padding: padding, child: child);

    if (onTap != null) {
      content = Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(radius),
          onTap: onTap,
          child: content,
        ),
      );
    }

    return Container(
      margin: margin,
      decoration: decoration,
      clipBehavior: Clip.antiAlias,
      child: content,
    );
  }
}
