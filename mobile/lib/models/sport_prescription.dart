import '../core/api_client.dart';
import '../core/formatters.dart';

/// Prescription structurée d'un exercice (Sport v2, § 3) : cibles chiffrées, phrase lisible
/// (`resume`) et étapes minutées (`etapes`), toutes deux calculées par le serveur.
///
/// Le contrat est additif : une séance enregistrée avant la v2 n'a ni `prescription`, ni
/// `etapes`, parfois pas de `resume`. Les replis ci-dessous les reconstruisent à partir de
/// séries / répétitions / durée / repos, en phrases lisibles (« 10 séries de 30 s, 1 min 52 de
/// repos », jamais « 10×30 1 min 52 »).

/// Intervalle numérique `[min, max]` (une valeur seule donne min = max).
class NumRange {
  final double min;
  final double max;

  const NumRange(this.min, this.max);

  /// `[100, 110]`, `100`, `"100"`, `[7]` ; null quand rien d'exploitable.
  static NumRange? parse(dynamic value) {
    if (value is List) {
      final nums = value.map(parseNum).whereType<double>().toList();
      if (nums.isEmpty) return null;
      if (nums.length == 1) return NumRange(nums.first, nums.first);
      final a = nums[0];
      final b = nums[1];
      return a <= b ? NumRange(a, b) : NumRange(b, a);
    }
    final n = parseNum(value);
    return n == null ? null : NumRange(n, n);
  }

  bool get isSingle => min == max;

  /// « 100–110 » / « 7 » / « 6,5 ».
  String format({int decimals = 1}) {
    final a = _num(min, decimals);
    if (isSingle) return a;
    return '$a–${_num(max, decimals)}';
  }

  /// Allures en minutes décimales : 5.5 → « 5:30 » (« 5:30–6:00 »).
  String formatPace() {
    final a = fmtPace(min);
    if (isSingle) return a;
    return '$a–${fmtPace(max)}';
  }

  /// Temps en secondes affichés en m:ss : 125 → « 2:05 ».
  String formatClock() {
    final a = fmtClock(min.round());
    if (isSingle) return a;
    return '$a–${fmtClock(max.round())}';
  }

  List<num> toJson() => isSingle ? [_jsonNum(min)] : [_jsonNum(min), _jsonNum(max)];
}

num _jsonNum(double v) => v == v.roundToDouble() ? v.round() : v;

String _num(double v, int decimals) {
  if (v == v.roundToDouble()) return v.round().toString();
  return v.toStringAsFixed(decimals).replaceAll('.', ',');
}

/// 5.5 → « 5:30 » (minutes décimales vers m:ss).
String fmtPace(double minutes) {
  final totalSec = (minutes * 60).round();
  return fmtClock(totalSec);
}

/// 125 → « 2:05 ».
String fmtClock(int seconds) {
  final s = seconds < 0 ? 0 : seconds;
  final m = s ~/ 60;
  final r = s % 60;
  return '$m:${r.toString().padLeft(2, '0')}';
}

/// 30 → « 30 s », 480 → « 8 min », 112 → « 1 min 52 », 3600 → « 1 h ».
String fmtSecondes(int seconds) {
  if (seconds < 60) return '$seconds${nbsp}s';
  if (seconds >= 3600 && seconds % 60 == 0) {
    final h = seconds ~/ 3600;
    final m = (seconds % 3600) ~/ 60;
    return m == 0 ? '$h${nbsp}h' : '$h${nbsp}h$nbsp${m.toString().padLeft(2, '0')}';
  }
  final m = seconds ~/ 60;
  final r = seconds % 60;
  if (r == 0) return '$m${nbsp}min';
  return '$m${nbsp}min$nbsp${r.toString().padLeft(2, '0')}';
}

/// Genre visuel d'une pastille de cible (couleur et icône choisies par l'écran).
enum CibleKind { cadence, resistance, puissance, vitesse, allure, inclinaison, coups, allure500, zone, fc, rpe, rir, tempo }

/// Une pastille prête à afficher : « 100–110 tr/min », « zone 4 · 150–167 bpm »…
class CiblePastille {
  final CibleKind kind;
  final String label;

  const CiblePastille(this.kind, this.label);
}

/// Cible pendant l'effort (ou la récupération) ; chaque clé est facultative.
class Cible {
  final NumRange? cadenceRpm;
  final NumRange? resistance;
  final NumRange? puissanceW;
  final NumRange? vitesseKmh;
  final NumRange? allureMinKm;
  final NumRange? inclinaisonPct;
  final NumRange? cadenceCoupsMin;
  final NumRange? allure500mSec;
  final int? zoneFc;
  final NumRange? fcBpm;
  final NumRange? rpe;

  const Cible({
    this.cadenceRpm,
    this.resistance,
    this.puissanceW,
    this.vitesseKmh,
    this.allureMinKm,
    this.inclinaisonPct,
    this.cadenceCoupsMin,
    this.allure500mSec,
    this.zoneFc,
    this.fcBpm,
    this.rpe,
  });

  static Cible? fromJson(dynamic value) {
    if (value is! Map) return null;
    final json = ApiClient.asMap(value);
    final cible = Cible(
      cadenceRpm: NumRange.parse(json['cadence_rpm']),
      resistance: NumRange.parse(json['resistance']),
      puissanceW: NumRange.parse(json['puissance_w']),
      vitesseKmh: NumRange.parse(json['vitesse_kmh']),
      allureMinKm: NumRange.parse(json['allure_min_km']),
      inclinaisonPct: NumRange.parse(json['inclinaison_pct']),
      cadenceCoupsMin: NumRange.parse(json['cadence_coups_min']),
      allure500mSec: NumRange.parse(json['allure_500m_sec']),
      zoneFc: parseInt(json['zone_fc']),
      fcBpm: NumRange.parse(json['fc_bpm']),
      rpe: NumRange.parse(json['rpe']),
    );
    return cible.isEmpty ? null : cible;
  }

  bool get isEmpty => pastilles.isEmpty;

  /// Pastilles dans l'ordre de lecture : machine (cadence, résistance…), puis cœur, puis ressenti.
  List<CiblePastille> get pastilles {
    final list = <CiblePastille>[];
    if (cadenceRpm != null) list.add(CiblePastille(CibleKind.cadence, '${cadenceRpm!.format()} tr/min'));
    if (resistance != null) list.add(CiblePastille(CibleKind.resistance, 'résistance ${resistance!.format()}/10'));
    if (puissanceW != null) list.add(CiblePastille(CibleKind.puissance, '${puissanceW!.format()} W'));
    if (vitesseKmh != null) list.add(CiblePastille(CibleKind.vitesse, '${vitesseKmh!.format()} km/h'));
    if (allureMinKm != null) list.add(CiblePastille(CibleKind.allure, '${allureMinKm!.formatPace()} /km'));
    if (inclinaisonPct != null) {
      list.add(CiblePastille(CibleKind.inclinaison, 'inclinaison ${inclinaisonPct!.format()}$nbsp%'));
    }
    if (cadenceCoupsMin != null) list.add(CiblePastille(CibleKind.coups, '${cadenceCoupsMin!.format()} coups/min'));
    if (allure500mSec != null) list.add(CiblePastille(CibleKind.allure500, '${allure500mSec!.formatClock()} /500 m'));
    if (zoneFc != null) {
      final bpm = fcBpm == null ? '' : ' · ${fcBpm!.format()} bpm';
      list.add(CiblePastille(CibleKind.zone, 'zone $zoneFc$bpm'));
    } else if (fcBpm != null) {
      list.add(CiblePastille(CibleKind.fc, '${fcBpm!.format()} bpm'));
    }
    if (rpe != null) list.add(CiblePastille(CibleKind.rpe, 'RPE ${rpe!.format()}'));
    return list;
  }

  /// « 100–110 tr/min · résistance 7–8/10 · zone 4 · 150–167 bpm ».
  String get texte => pastilles.map((p) => p.label).join(' · ');
}

/// `prescription` d'un exercice (Sport v2, § 3).
class Prescription {
  final String type; // force | cardio_continu | intervalles | mobilite
  final int? repetitions;
  final int? effortSec;
  final int? recupSec;
  final Cible? cible;
  final Cible? recupCible;
  final int? rir;
  final String? tempo;
  final String? chargeConseil;
  final String? consigne;

  /// Le JSON reçu, renvoyé tel quel à l'enregistrement d'une proposition.
  final Map<String, dynamic> raw;

  const Prescription({
    this.type = 'force',
    this.repetitions,
    this.effortSec,
    this.recupSec,
    this.cible,
    this.recupCible,
    this.rir,
    this.tempo,
    this.chargeConseil,
    this.consigne,
    this.raw = const {},
  });

  static Prescription? fromJson(dynamic value) {
    if (value is! Map) return null;
    final json = ApiClient.asMap(value);
    if (json.isEmpty) return null;
    return Prescription(
      type: parseString(json['type']) ?? 'force',
      repetitions: parseInt(json['repetitions']),
      effortSec: parseInt(json['effort_sec']),
      recupSec: parseInt(json['recup_sec']),
      cible: Cible.fromJson(json['cible']),
      recupCible: Cible.fromJson(json['recup_cible']),
      rir: parseInt(json['rir']),
      tempo: parseString(json['tempo']),
      chargeConseil: parseString(json['charge_conseil']),
      consigne: parseString(json['consigne']),
      raw: json,
    );
  }

  /// Toutes les pastilles : cibles d'effort, puis RIR et tempo (force).
  List<CiblePastille> get pastilles => [
        ...?cible?.pastilles,
        if (rir != null) CiblePastille(CibleKind.rir, 'RIR $rir'),
        if (tempo != null) CiblePastille(CibleKind.tempo, 'tempo $tempo'),
      ];
}

/// Une étape minutée (`etapes[]`) : `effort` | `recup` | `travail` (série de force) | `repos`.
class Etape {
  final String label;
  final String type;

  /// Null pour une série de force sans durée : le minuteur attend « Série terminée ».
  final int? dureeSec;

  /// Cible lisible pendant l'étape (« 100–110 tr/min · 150–167 bpm »).
  final String? cible;

  const Etape({required this.label, this.type = 'travail', this.dureeSec, this.cible});

  factory Etape.fromJson(Map<String, dynamic> json) {
    final duree = parseInt(json['duree_sec']);
    return Etape(
      label: parseString(json['label']) ?? 'Étape',
      type: parseString(json['type']) ?? 'travail',
      dureeSec: duree != null && duree > 0 ? duree : null,
      cible: parseString(json['cible']),
    );
  }

  bool get isTimed => dureeSec != null;

  /// Récupération ou repos : on souffle.
  bool get isRest => type == 'recup' || type == 'repos';

  static List<Etape> listFrom(dynamic value) => ApiClient.asList(value).map(Etape.fromJson).toList();
}

/// Phrase lisible pour un exercice sans `resume` serveur (séances d'avant la v2).
///
/// « 3 séries de 12 répétitions, 1 min 30 de repos », « 10 séries de 30 s, 1 min 52 de repos »,
/// « 20 min », « 5 km ». Chaîne vide si rien n'est connu.
String resumeLegacy({int? sets, int? reps, int? durationSec, int? restSec, double? distanceKm}) {
  final parts = <String>[];
  final n = sets;
  String series(int count) => count > 1 ? '$count séries' : '1 série';
  String repetitions(int count) => count > 1 ? '$count répétitions' : '1 répétition';
  if (n != null && n > 0 && reps != null && reps > 0) {
    parts.add('${series(n)} de ${repetitions(reps)}');
  } else if (n != null && n > 0 && durationSec != null && durationSec > 0) {
    parts.add('${series(n)} de ${fmtSecondes(durationSec)}');
  } else if (reps != null && reps > 0) {
    parts.add(repetitions(reps));
  } else if (durationSec != null && durationSec > 0) {
    parts.add(fmtSecondes(durationSec));
  } else if (n != null && n > 0) {
    parts.add(series(n));
  }
  if (distanceKm != null && distanceKm > 0) {
    final km = distanceKm == distanceKm.roundToDouble()
        ? distanceKm.round().toString()
        : distanceKm.toStringAsFixed(1).replaceAll('.', ',');
    parts.add('$km${nbsp}km');
  }
  var text = parts.join(' · ');
  if (restSec != null && restSec > 0 && text.isNotEmpty) {
    text = '$text, ${fmtSecondes(restSec)} de repos';
  }
  return text;
}

/// Étapes reconstruites pour un exercice sans `etapes` serveur : une étape par série, un repos
/// entre deux séries. Une série sans durée attend la validation (« Série terminée »).
List<Etape> etapesLegacy({int? sets, int? reps, int? durationSec, int? restSec}) {
  final count = (sets == null || sets < 1) ? 1 : sets;
  final cible = reps != null && reps > 0 ? '$reps répétition${reps > 1 ? 's' : ''}' : null;
  final duree = durationSec != null && durationSec > 0 ? durationSec : null;
  final list = <Etape>[];
  for (var i = 1; i <= count; i++) {
    list.add(Etape(
      label: count > 1 ? 'Série $i/$count' : 'Exercice',
      type: duree != null && reps == null ? 'effort' : 'travail',
      dureeSec: duree,
      cible: cible,
    ));
    if (i < count && restSec != null && restSec > 0) {
      list.add(Etape(label: 'Repos', type: 'repos', dureeSec: restSec));
    }
  }
  return list;
}
