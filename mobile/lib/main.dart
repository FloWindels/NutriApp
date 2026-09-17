import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:intl/intl.dart';

import 'core/session.dart';
import 'screens/splash_screen.dart';
import 'theme/app_theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initializeDateFormatting('fr_FR');
  Intl.defaultLocale = 'fr_FR';
  runApp(const MaviohApp());
}

class MaviohApp extends StatelessWidget {
  const MaviohApp({super.key, this.home});

  /// Overridable first screen (tests).
  final Widget? home;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: "Mavi'oh",
      debugShowCheckedModeBanner: false,
      navigatorKey: AppNavigator.key,
      locale: const Locale('fr', 'FR'),
      supportedLocales: const [Locale('fr', 'FR')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      theme: AppTheme.light,
      home: home ?? const SplashScreen(),
    );
  }
}
