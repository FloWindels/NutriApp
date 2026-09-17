import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// Centered spinner with optional message, or a skeleton list.
class LoadingState extends StatelessWidget {
  final String? message;
  final bool skeleton;
  final int skeletonCount;

  const LoadingState({super.key, this.message, this.skeleton = false, this.skeletonCount = 3});

  @override
  Widget build(BuildContext context) {
    if (skeleton) {
      return ListView.separated(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
        physics: const NeverScrollableScrollPhysics(),
        itemCount: skeletonCount,
        separatorBuilder: (_, _) => const SizedBox(height: 14),
        itemBuilder: (_, index) => SkeletonBox(height: index == 0 ? 150 : 96),
      );
    }
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const SizedBox(width: 28, height: 28, child: CircularProgressIndicator(strokeWidth: 2.6)),
          if (message != null) ...[
            const SizedBox(height: 14),
            Text(message!, style: const TextStyle(color: MaviohColors.muted, fontWeight: FontWeight.w600)),
          ],
        ],
      ),
    );
  }
}

/// Grey animated placeholder block.
class SkeletonBox extends StatefulWidget {
  final double height;
  final double? width;
  final double radius;

  const SkeletonBox({super.key, required this.height, this.width, this.radius = 20});

  @override
  State<SkeletonBox> createState() => _SkeletonBoxState();
}

class _SkeletonBoxState extends State<SkeletonBox> with SingleTickerProviderStateMixin {
  late final AnimationController _controller =
      AnimationController(vsync: this, duration: const Duration(milliseconds: 1100))..repeat(reverse: true);

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _controller,
      builder: (context, _) {
        final t = 0.55 + 0.45 * _controller.value;
        return Container(
          height: widget.height,
          width: widget.width ?? double.infinity,
          decoration: BoxDecoration(
            color: Color.lerp(MaviohColors.border, MaviohColors.surface, t),
            borderRadius: BorderRadius.circular(widget.radius),
          ),
        );
      },
    );
  }
}
