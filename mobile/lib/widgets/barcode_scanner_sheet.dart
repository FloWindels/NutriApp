import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../theme/app_theme.dart';

/// The single barcode scanner of the app.
///
/// `await BarcodeScannerSheet.show(context)` returns the 8–14 digit code, or
/// null when dismissed. Includes a torch toggle, a French error state with a
/// manual-entry fallback and a scan window matching the visible frame.
class BarcodeScannerSheet extends StatefulWidget {
  const BarcodeScannerSheet({super.key, this.title = 'Scanner un code-barres'});

  final String title;

  static Future<String?> show(BuildContext context, {String title = 'Scanner un code-barres'}) {
    final height = (MediaQuery.sizeOf(context).height * 0.78).clamp(420.0, 680.0);
    return showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      useRootNavigator: true,
      useSafeArea: true,
      backgroundColor: const Color(0xFF020617),
      showDragHandle: false,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (_) => SizedBox(height: height, child: BarcodeScannerSheet(title: title)),
    );
  }

  /// Extracts the first 8–14 digit run from a raw scanner value.
  static String? extractCode(String? raw) {
    if (raw == null) return null;
    final match = RegExp(r'\d{8,14}').firstMatch(raw.trim());
    return match?.group(0);
  }

  @override
  State<BarcodeScannerSheet> createState() => _BarcodeScannerSheetState();
}

class _BarcodeScannerSheetState extends State<BarcodeScannerSheet> {
  final MobileScannerController _controller = MobileScannerController(
    facing: CameraFacing.back,
    detectionSpeed: DetectionSpeed.noDuplicates,
    formats: const [
      BarcodeFormat.ean13,
      BarcodeFormat.ean8,
      BarcodeFormat.upcA,
      BarcodeFormat.upcE,
      BarcodeFormat.code128,
    ],
  );

  bool _handled = false;
  bool _torchOn = false;
  String? _preview;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _onDetect(BarcodeCapture capture) {
    if (_handled) return;
    for (final barcode in capture.barcodes) {
      final raw = barcode.rawValue?.trim();
      if (raw == null || raw.isEmpty) continue;
      if (mounted) setState(() => _preview = raw);
      final code = BarcodeScannerSheet.extractCode(raw);
      if (code != null) {
        _handled = true;
        HapticFeedback.mediumImpact();
        Navigator.of(context).pop(code);
        return;
      }
    }
  }

  Future<void> _toggleTorch() async {
    try {
      await _controller.toggleTorch();
      if (mounted) setState(() => _torchOn = !_torchOn);
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.maybeOf(context)?.showSnackBar(
          const SnackBar(content: Text('La lampe n’est pas disponible sur cet appareil.')),
        );
      }
    }
  }

  Future<void> _manualEntry() async {
    final code = await showDialog<String>(
      context: context,
      builder: (ctx) => const _ManualCodeDialog(),
    );
    if (code != null && mounted) {
      _handled = true;
      Navigator.of(context).pop(code);
    }
  }

  String _errorMessage(MobileScannerException error) {
    switch (error.errorCode) {
      case MobileScannerErrorCode.permissionDenied:
        return 'Accès à la caméra refusé. Autorise la caméra dans les réglages ou saisis le code.';
      case MobileScannerErrorCode.unsupported:
        return 'Le scan n’est pas pris en charge sur cet appareil. Saisis le code manuellement.';
      default:
        return 'La caméra n’a pas pu démarrer. Réessaie ou saisis le code.';
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 6, 6),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  widget.title,
                  style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w800),
                ),
              ),
              IconButton(
                tooltip: _torchOn ? 'Éteindre la lampe' : 'Allumer la lampe',
                onPressed: _toggleTorch,
                icon: Icon(_torchOn ? Icons.flashlight_on_rounded : Icons.flashlight_off_rounded, color: Colors.white),
              ),
              IconButton(
                tooltip: 'Fermer',
                onPressed: () => Navigator.of(context).pop(),
                icon: const Icon(Icons.close_rounded, color: Colors.white),
              ),
            ],
          ),
        ),
        Expanded(
          child: LayoutBuilder(
            builder: (context, constraints) {
              final frameWidth = (constraints.maxWidth * 0.72).clamp(220.0, 340.0);
              final frameHeight = (constraints.maxHeight * 0.22).clamp(96.0, 160.0);
              final center = Offset(constraints.maxWidth / 2, constraints.maxHeight / 2);
              final window = Rect.fromCenter(center: center, width: frameWidth, height: frameHeight);

              return Stack(
                fit: StackFit.expand,
                children: [
                  MobileScanner(
                    controller: _controller,
                    onDetect: _onDetect,
                    scanWindow: window,
                    errorBuilder: (context, error) => _ScannerError(
                      message: _errorMessage(error),
                      onManual: _manualEntry,
                    ),
                  ),
                  IgnorePointer(
                    child: Center(
                      child: Container(
                        width: frameWidth,
                        height: frameHeight,
                        decoration: BoxDecoration(
                          border: Border.all(color: MaviohColors.accent, width: 3),
                          borderRadius: BorderRadius.circular(16),
                        ),
                      ),
                    ),
                  ),
                ],
              );
            },
          ),
        ),
        Container(
          width: double.infinity,
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
          color: const Color(0xFF020617),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'Place le code-barres dans le cadre : la recherche se lance toute seule.',
                style: TextStyle(color: Colors.white70, height: 1.4),
              ),
              if (_preview != null) ...[
                const SizedBox(height: 6),
                Text('Détection : $_preview', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600)),
              ],
              const SizedBox(height: 10),
              Align(
                alignment: Alignment.centerRight,
                child: TextButton.icon(
                  onPressed: _manualEntry,
                  style: TextButton.styleFrom(foregroundColor: MaviohColors.accent),
                  icon: const Icon(Icons.keyboard_rounded, size: 18),
                  label: const Text('Saisir le code'),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _ScannerError extends StatelessWidget {
  final String message;
  final VoidCallback onManual;

  const _ScannerError({required this.message, required this.onManual});

  @override
  Widget build(BuildContext context) {
    return Container(
      color: const Color(0xFF0F172A),
      padding: const EdgeInsets.all(24),
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.no_photography_outlined, color: Colors.white70, size: 40),
            const SizedBox(height: 14),
            Text(
              message,
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.white, height: 1.45, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 16),
            FilledButton.icon(
              onPressed: onManual,
              style: FilledButton.styleFrom(backgroundColor: MaviohColors.accent, foregroundColor: MaviohColors.text),
              icon: const Icon(Icons.keyboard_rounded),
              label: const Text('Saisir le code'),
            ),
          ],
        ),
      ),
    );
  }
}

class _ManualCodeDialog extends StatefulWidget {
  const _ManualCodeDialog();

  @override
  State<_ManualCodeDialog> createState() => _ManualCodeDialogState();
}

class _ManualCodeDialogState extends State<_ManualCodeDialog> {
  final _controller = TextEditingController();
  String? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _submit() {
    final code = BarcodeScannerSheet.extractCode(_controller.text);
    if (code == null || !RegExp(r'^\d{8,14}$').hasMatch(_controller.text.trim())) {
      setState(() => _error = 'Saisis un code de 8 à 14 chiffres.');
      return;
    }
    Navigator.of(context).pop(code);
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Saisir le code-barres'),
      content: TextField(
        controller: _controller,
        autofocus: true,
        keyboardType: TextInputType.number,
        inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(14)],
        decoration: InputDecoration(labelText: 'Code EAN', hintText: '3017620422003', errorText: _error),
        onSubmitted: (_) => _submit(),
      ),
      actionsPadding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Annuler')),
        FilledButton(onPressed: _submit, child: const Text('Rechercher')),
      ],
    );
  }
}
