import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)

    // Séance guidée : garder l'écran allumé tant que le minuteur tourne (sans plugin).
    if let registrar = engineBridge.pluginRegistry.registrar(forPlugin: "MaviohEcranAllume") {
      let canal = FlutterMethodChannel(name: "app.mavioh/ecran", binaryMessenger: registrar.messenger())
      canal.setMethodCallHandler { call, result in
        if call.method == "garderAllume" {
          UIApplication.shared.isIdleTimerDisabled = (call.arguments as? Bool) ?? false
          result(nil)
        } else {
          result(FlutterMethodNotImplemented)
        }
      }
    }
  }
}
