import 'package:flutter/material.dart';

import '../core/strings.dart';
import '../theme/app_theme.dart';

/// Yes/no dialog. Returns `true` when confirmed.
class ConfirmDialog {
  ConfirmDialog._();

  static Future<bool> show(
    BuildContext context, {
    required String title,
    String? message,
    String confirmLabel = AppStrings.confirm,
    String cancelLabel = AppStrings.cancel,
    bool destructive = false,
    IconData? icon,
  }) async {
    final result = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        icon: icon == null
            ? null
            : Icon(icon, color: destructive ? MaviohColors.error : MaviohColors.primary, size: 30),
        title: Text(title),
        content: message == null ? null : Text(message),
        actionsPadding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: Text(cancelLabel)),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            style: destructive ? FilledButton.styleFrom(backgroundColor: MaviohColors.error) : null,
            child: Text(confirmLabel),
          ),
        ],
      ),
    );
    return result ?? false;
  }

  /// Simple informational dialog with a single « Fermer ».
  static Future<void> info(
    BuildContext context, {
    required String title,
    required String message,
    IconData icon = Icons.info_outline_rounded,
  }) {
    return showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        icon: Icon(icon, color: MaviohColors.primary, size: 30),
        title: Text(title),
        content: SingleChildScrollView(child: Text(message)),
        actionsPadding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
        actions: [
          FilledButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text(AppStrings.close)),
        ],
      ),
    );
  }
}
