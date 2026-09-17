import 'package:flutter/material.dart';

/// Brand palette (light only in v1).
class MaviohColors {
  MaviohColors._();

  static const Color primary = Color(0xFF0F5B43);
  static const Color accent = Color(0xFF95C11F);
  static const Color seed = Color(0xFF0EA5A4);
  static const Color background = Color(0xFFF4F8F1);
  static const Color surface = Color(0xFFF8FAFC);
  static const Color white = Colors.white;

  static const Color text = Color(0xFF0F172A);
  static const Color textSecondary = Color(0xFF334155);
  static const Color textTertiary = Color(0xFF475569);
  static const Color muted = Color(0xFF64748B);
  static const Color border = Color(0xFFE2E8F0);
  static const Color borderSoft = Color(0xFFEDF2F7);

  static const Color success = Color(0xFF047857);
  static const Color successBg = Color(0xFFECFDF5);
  static const Color error = Color(0xFFBE123C);
  static const Color errorBg = Color(0xFFFFF1F2);
  static const Color warning = Color(0xFF92400E);
  static const Color warningBg = Color(0xFFFFFBEB);
  static const Color info = Color(0xFF075985);
  static const Color infoBg = Color(0xFFF0F9FF);

  // Tones used by section icons, pills and charts.
  static const Color emerald = Color(0xFF059669);
  static const Color amber = Color(0xFFF59E0B);
  static const Color sky = Color(0xFF0EA5E9);
  static const Color rose = Color(0xFFF43F5E);
  static const Color lime = Color(0xFF84CC16);
  static const Color violet = Color(0xFF8B5CF6);
  static const Color cyan = Color(0xFF06B6D4);
  static const Color indigo = Color(0xFF6366F1);
  static const Color teal = Color(0xFF14B8A6);
  static const Color orange = Color(0xFFF97316);
  static const Color slate = Color(0xFF64748B);
  static const Color fuchsia = Color(0xFFD946EF);

  /// Macro colours: protéines / glucides / lipides.
  static const Color proteins = Color(0xFF10B981);
  static const Color carbs = Color(0xFF84CC16);
  static const Color fat = Color(0xFFF59E0B);

  /// Light tint of a tone for chips / backgrounds.
  static Color tint(Color tone, [double alpha = 0.12]) => tone.withValues(alpha: alpha);
}

/// Material 3 light theme.
class AppTheme {
  AppTheme._();

  static const double radiusInput = 14;
  static const double radiusButton = 14;
  static const double radiusCard = 24;

  static ThemeData get light {
    final scheme = ColorScheme.fromSeed(
      seedColor: MaviohColors.seed,
      brightness: Brightness.light,
      primary: MaviohColors.primary,
      onPrimary: Colors.white,
      secondary: MaviohColors.accent,
      onSecondary: MaviohColors.text,
      error: MaviohColors.error,
      surface: Colors.white,
      onSurface: MaviohColors.text,
    );

    OutlineInputBorder border(Color color, [double width = 1]) => OutlineInputBorder(
          borderRadius: BorderRadius.circular(radiusInput),
          borderSide: BorderSide(color: color, width: width),
        );

    final buttonShape = RoundedRectangleBorder(borderRadius: BorderRadius.circular(radiusButton));

    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: MaviohColors.background,
      canvasColor: MaviohColors.background,
      splashFactory: InkSparkle.splashFactory,
      visualDensity: VisualDensity.standard,
      appBarTheme: const AppBarTheme(
        backgroundColor: MaviohColors.background,
        surfaceTintColor: Colors.transparent,
        foregroundColor: MaviohColors.text,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: MaviohColors.text),
      ),
      textTheme: const TextTheme(
        headlineMedium: TextStyle(fontSize: 26, fontWeight: FontWeight.w800, color: MaviohColors.text, height: 1.15),
        headlineSmall: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: MaviohColors.text, height: 1.2),
        titleLarge: TextStyle(fontSize: 18, fontWeight: FontWeight.w700, color: MaviohColors.text),
        titleMedium: TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: MaviohColors.text),
        titleSmall: TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: MaviohColors.textSecondary),
        bodyLarge: TextStyle(fontSize: 16, color: MaviohColors.textSecondary, height: 1.4),
        bodyMedium: TextStyle(fontSize: 14, color: MaviohColors.textTertiary, height: 1.45),
        bodySmall: TextStyle(fontSize: 12, color: MaviohColors.muted, height: 1.4),
        labelLarge: TextStyle(fontSize: 14, fontWeight: FontWeight.w700),
        labelMedium: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: MaviohColors.muted),
        labelSmall: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, letterSpacing: 1.1, color: MaviohColors.muted),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        border: border(const Color(0xFFD7DEE7)),
        enabledBorder: border(const Color(0xFFD7DEE7)),
        focusedBorder: border(MaviohColors.seed, 1.3),
        errorBorder: border(MaviohColors.error),
        focusedErrorBorder: border(MaviohColors.error, 1.3),
        labelStyle: const TextStyle(color: MaviohColors.muted),
        hintStyle: const TextStyle(color: Color(0xFF94A3B8)),
        errorStyle: const TextStyle(color: MaviohColors.error),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          elevation: 0,
          backgroundColor: MaviohColors.primary,
          foregroundColor: Colors.white,
          minimumSize: const Size(48, 48),
          padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
          shape: buttonShape,
          textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: MaviohColors.primary,
          foregroundColor: Colors.white,
          minimumSize: const Size(48, 48),
          padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
          shape: buttonShape,
          textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: MaviohColors.primary,
          minimumSize: const Size(48, 48),
          padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
          side: const BorderSide(color: MaviohColors.border),
          shape: buttonShape,
          textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: MaviohColors.primary,
          minimumSize: const Size(48, 44),
          shape: buttonShape,
          textStyle: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700),
        ),
      ),
      iconButtonTheme: IconButtonThemeData(
        style: IconButton.styleFrom(
          minimumSize: const Size(48, 48),
          foregroundColor: MaviohColors.textSecondary,
        ),
      ),
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 0,
        surfaceTintColor: Colors.transparent,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(radiusCard),
          side: const BorderSide(color: MaviohColors.border),
        ),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: Colors.white,
        selectedColor: MaviohColors.tint(MaviohColors.primary, 0.12),
        side: const BorderSide(color: MaviohColors.border),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        labelStyle: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: MaviohColors.textSecondary),
        secondaryLabelStyle: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: MaviohColors.primary),
        checkmarkColor: MaviohColors.primary,
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        showCheckmark: false,
      ),
      segmentedButtonTheme: SegmentedButtonThemeData(
        style: SegmentedButton.styleFrom(
          selectedBackgroundColor: MaviohColors.primary,
          selectedForegroundColor: Colors.white,
          foregroundColor: MaviohColors.textSecondary,
          backgroundColor: Colors.white,
          side: const BorderSide(color: MaviohColors.border),
          minimumSize: const Size(48, 44),
          textStyle: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(radiusButton)),
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: Colors.white,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        height: 68,
        indicatorColor: MaviohColors.accent.withValues(alpha: 0.25),
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        iconTheme: WidgetStateProperty.resolveWith(
          (states) => IconThemeData(
            color: states.contains(WidgetState.selected) ? MaviohColors.primary : MaviohColors.muted,
            size: 24,
          ),
        ),
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => TextStyle(
            fontSize: 12,
            fontWeight: states.contains(WidgetState.selected) ? FontWeight.w800 : FontWeight.w600,
            color: states.contains(WidgetState.selected) ? MaviohColors.primary : MaviohColors.muted,
          ),
        ),
      ),
      floatingActionButtonTheme: const FloatingActionButtonThemeData(
        backgroundColor: MaviohColors.primary,
        foregroundColor: Colors.white,
        elevation: 2,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.all(Radius.circular(18))),
      ),
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: MaviohColors.background,
        surfaceTintColor: Colors.transparent,
        showDragHandle: true,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(28))),
        clipBehavior: Clip.antiAlias,
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: Colors.white,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
        titleTextStyle: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: MaviohColors.text),
        contentTextStyle: const TextStyle(fontSize: 14, color: MaviohColors.textTertiary, height: 1.45),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: MaviohColors.text,
        contentTextStyle: const TextStyle(color: Colors.white, fontSize: 14),
        actionTextColor: MaviohColors.accent,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
      dividerTheme: const DividerThemeData(color: MaviohColors.border, thickness: 1, space: 1),
      listTileTheme: const ListTileThemeData(
        iconColor: MaviohColors.textSecondary,
        textColor: MaviohColors.text,
        minVerticalPadding: 12,
      ),
      switchTheme: SwitchThemeData(
        thumbColor: WidgetStateProperty.resolveWith(
          (states) => states.contains(WidgetState.selected) ? Colors.white : MaviohColors.muted,
        ),
        trackColor: WidgetStateProperty.resolveWith(
          (states) => states.contains(WidgetState.selected) ? MaviohColors.primary : MaviohColors.border,
        ),
        trackOutlineColor: WidgetStateProperty.resolveWith(
          (states) => states.contains(WidgetState.selected) ? MaviohColors.primary : MaviohColors.border,
        ),
      ),
      checkboxTheme: CheckboxThemeData(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
        side: const BorderSide(color: MaviohColors.muted, width: 1.5),
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: MaviohColors.primary,
        linearTrackColor: MaviohColors.border,
        circularTrackColor: MaviohColors.border,
      ),
      tabBarTheme: const TabBarThemeData(
        labelColor: MaviohColors.primary,
        unselectedLabelColor: MaviohColors.muted,
        indicatorColor: MaviohColors.primary,
        dividerColor: MaviohColors.border,
      ),
      materialTapTargetSize: MaterialTapTargetSize.padded,
    );
  }
}
