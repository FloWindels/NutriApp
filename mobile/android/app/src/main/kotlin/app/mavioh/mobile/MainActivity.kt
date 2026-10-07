package app.mavioh.mobile

import android.view.WindowManager
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        // Séance guidée : garder l'écran allumé tant que le minuteur tourne (sans plugin).
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "app.mavioh/ecran")
            .setMethodCallHandler { call, result ->
                if (call.method == "garderAllume") {
                    val allume = call.arguments as? Boolean ?: false
                    runOnUiThread {
                        if (allume) {
                            window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
                        } else {
                            window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
                        }
                    }
                    result.success(null)
                } else {
                    result.notImplemented()
                }
            }
    }
}
