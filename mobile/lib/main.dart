import 'package:flutter/material.dart';
import 'screens/login_screen.dart';

void main() {
  runApp(const MaviohApp());
}

class MaviohApp extends StatelessWidget {
  const MaviohApp({super.key});

  @override
  Widget build(BuildContext context) {
    const seedColor = Color(0xFF0EA5A4);

    return MaterialApp(
      title: 'Mavioh',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(
          seedColor: seedColor,
          primary: const Color(0xFF0F5B43),
          secondary: const Color(0xFF95C11F),
        ),
        scaffoldBackgroundColor: const Color(0xFFF4F8F1),
        useMaterial3: true,
        inputDecorationTheme: InputDecorationTheme(
          filled: true,
          fillColor: Colors.white,
          contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(color: Color(0xFFD7DEE7)),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(color: Color(0xFFD7DEE7)),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(color: seedColor, width: 1.3),
          ),
        ),
        elevatedButtonTheme: ElevatedButtonThemeData(
          style: ElevatedButton.styleFrom(
            elevation: 0,
            backgroundColor: const Color(0xFF0F5B43),
            foregroundColor: Colors.white,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(14),
            ),
          ),
        ),
      ),
      home: const LoginScreen(),
    );
  }
}