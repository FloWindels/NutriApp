import 'package:flutter/material.dart';

import '../core/strings.dart';
import '../theme/app_theme.dart';

/// Initial-load failure: message + « Réessayer » (+ optional secondary action).
class ErrorState extends StatelessWidget {
  final String message;
  final VoidCallback? onRetry;
  final String retryLabel;
  final String? secondaryLabel;
  final VoidCallback? onSecondary;
  final bool compact;

  const ErrorState({
    super.key,
    required this.message,
    this.onRetry,
    this.retryLabel = AppStrings.retry,
    this.secondaryLabel,
    this.onSecondary,
    this.compact = false,
  });

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: EdgeInsets.symmetric(horizontal: 24, vertical: compact ? 16 : 32),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 360),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: compact ? 48 : 64,
                height: compact ? 48 : 64,
                decoration: BoxDecoration(color: MaviohColors.errorBg, borderRadius: BorderRadius.circular(compact ? 16 : 20)),
                child: Icon(Icons.cloud_off_rounded, color: MaviohColors.error, size: compact ? 24 : 30),
              ),
              SizedBox(height: compact ? 10 : 16),
              Text(
                message,
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: compact ? 14 : 15, fontWeight: FontWeight.w600, color: MaviohColors.textSecondary, height: 1.45),
              ),
              if (onRetry != null) ...[
                const SizedBox(height: 16),
                FilledButton.icon(
                  onPressed: onRetry,
                  icon: const Icon(Icons.refresh_rounded),
                  label: Text(retryLabel),
                ),
              ],
              if (secondaryLabel != null && onSecondary != null) ...[
                const SizedBox(height: 8),
                TextButton(onPressed: onSecondary, child: Text(secondaryLabel!)),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
