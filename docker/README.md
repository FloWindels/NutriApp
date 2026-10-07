# Mavi'oh en Docker, derrière un tunnel Cloudflare

Tout tourne en conteneurs sur le serveur. Seuls deux ports sont publiés, et seulement sur
`127.0.0.1` : le `cloudflared` de l'hôte les expose sous des noms publics en HTTPS. Rien n'est
joignable depuis le réseau sans passer par Cloudflare.

| Conteneur | Rôle | Joignable |
|---|---|---|
| `web` | site Next.js, qui relaie vers l'API côté serveur | `127.0.0.1:3100` → `https://mavioh.florentwindels.com` |
| `api-nginx` | Nginx devant l'API Laravel | `127.0.0.1:8100` → `https://mavioh-api.florentwindels.com` (application mobile) |
| `api` | PHP-FPM : migrations et catalogue au démarrage | interne |
| `scheduler` | tâches planifiées (`artisan schedule:work`) | interne |
| `db` | PostgreSQL 16, volume `mavioh_db` | interne |
| `ollama` | modèle local sur le GPU, volume `mavioh_ollama` | interne |
| `ollama-init` | télécharge et charge le modèle, puis s'arrête | — |

## Installation

```bash
cp docker/.env.example docker/.env      # puis remplir APP_KEY, DB_PASSWORD et les URL
docker compose -f docker/compose.yml up -d --build
```

Le premier démarrage télécharge le modèle (3,3 Go). Les conteneurs redémarrent seuls avec Docker,
donc au démarrage du serveur aussi.

## Routes du tunnel Cloudflare

Le tunnel est piloté depuis le tableau de bord (jeton dans `/etc/cloudflared/token`). Dans
**Zero Trust → Networks → Tunnels → le tunnel → Public Hostname → Add a public hostname** :

| Sous-domaine | Domaine | Service |
|---|---|---|
| `mavioh` | `florentwindels.com` | `HTTP` · `localhost:3100` |
| `mavioh-api` | `florentwindels.com` | `HTTP` · `localhost:8100` |

Le nom de l'API est `mavioh-api` et pas `api.mavioh` : le certificat gratuit de Cloudflare couvre
un seul niveau de sous-domaine. Si une règle Cloudflare Access protège tout le domaine, il faut en
exclure ces deux noms, sinon le site et l'application mobile reçoivent la page de connexion
d'Access au lieu de Mavi'oh.

## Premier compte administrateur

La base démarre vide, sans compte de démonstration. Inscris-toi sur le site, puis :

```bash
docker compose -f docker/compose.yml exec api php artisan mavioh:promouvoir ton@email
```

Ensuite, dans `/admin` → **Comptes**, pose-toi l'offre **Foyer** : sur l'offre gratuite, l'IA et
plusieurs modules restent fermés.

## IA locale : un seul modèle, une file d'attente

Le GPU (GTX 1050 Ti, 4 Go) ne peut garder qu'un seul modèle chargé. `gemma3:4b` sert à tout :
coach sportif, recettes, planificateur et photo d'assiette. Il reste chargé en permanence et n'est
jamais échangé contre un autre. Sur cette carte, mesures du 7 octobre 2026 :

- photo d'assiette : environ 45 s seule, environ 52 s à deux en même temps ;
- texte : environ 16 tokens par seconde.

L'API régule l'accès au modèle (`App\Services\Llm\OllamaGate`) :

- au plus `OLLAMA_SLOTS` générations simultanées, 2 par défaut ;
- jusqu'à `OLLAMA_QUEUE` demandes de plus attendent leur tour, 6 par défaut ;
- elles attendent `OLLAMA_QUEUE_WAIT` secondes au maximum, 20 par défaut.

Au-delà, la personne reçoit « L'IA est très demandée en ce moment. Réessaie dans une minute. ».
Le coach sportif et le planificateur passent alors sur les règles Mavi'oh et le signalent. La
photo laisse la saisie manuelle. Le reste de l'application n'est jamais ralenti, parce que l'attente
n'occupe que des processus PHP réservés pour ça (`docker/api/www.conf`).

Cloudflare coupe toute réponse qui dépasse 100 s (erreur 524). L'attente dans la file plus le délai
de génération (`OLLAMA_TIMEOUT`, `OLLAMA_VISION_TIMEOUT`) doivent donc rester en dessous : 20 + 70
aujourd'hui.

## Exploitation

```bash
cd /home/florent/NutriApp
docker compose -f docker/compose.yml ps                 # état
docker compose -f docker/compose.yml logs -f api        # journaux (aussi : web, ollama…)
git pull && docker compose -f docker/compose.yml up -d --build   # mise à jour
```

Les migrations et le catalogue s'appliquent au démarrage du conteneur `api`. Ils sont additifs et
idempotents.

Pour une sauvegarde de la base :

```bash
docker compose -f docker/compose.yml exec -T db pg_dump -U mavioh mavioh | gzip > mavioh-$(date +%F).sql.gz
```

Ce fichier contient des données de santé : ne le versionne jamais et range-le hors du dépôt.

## Sécurité : ce qui est ouvert, ce qui ne l'est pas

Depuis Internet, seuls deux noms répondent, tous deux derrière Cloudflare :

- **`mavioh.florentwindels.com`** : le site. Sans session, seules les pages de connexion,
  d'inscription, de mot de passe oublié et les pages légales s'affichent. `/dashboard/**`
  renvoie vers la connexion, avant tout rendu. `/admin/**` répond 404 à quiconque n'est pas
  administrateur, connecté ou non (garde serveur `web/src/proxy.ts`).
- **`mavioh-api.florentwindels.com`** : l'API, pour l'application mobile. Elle ne sert que
  `/api/`. Hors connexion et inscription, tout exige un jeton. L'administration n'y existe pas :
  `/api/admin` répond 404 sur cette porte, et seul le site l'atteint, par le réseau interne
  (`api-nginx:8080`).

Le reste n'est pas joignable : la base est sur un réseau Docker sans sortie, l'IA et PHP n'ont
aucun port publié, et les deux ports publiés écoutent sur `127.0.0.1` seulement.

Côté site, le jeton de session vit dans un cookie `httpOnly`, `Secure`, `SameSite=Lax`, que le
JavaScript de la page ne peut pas lire. Toute écriture venue d'un autre site est refusée (CSRF),
et les pages portent une CSP stricte, HSTS et l'interdiction d'être affichées dans un cadre.

## Application mobile

La version publiée n'accepte que HTTPS. Elle vise par défaut
`https://mavioh-api.florentwindels.com/api`, et le système Android ne la sauvegarde pas.

Elle doit être signée avec une clé propre à Mavi'oh, à créer une seule fois et à **conserver
précieusement** : sans elle, plus aucune mise à jour de l'application n'est possible.

```bash
keytool -genkey -v -keystore ~/mavioh-release.jks -keyalg RSA -keysize 4096 -validity 10000 -alias mavioh
cat > mobile/android/key.properties <<'PROPS'
storePassword=<mot de passe du magasin>
keyPassword=<mot de passe de la clé>
keyAlias=mavioh
storeFile=/home/florent/mavioh-release.jks
PROPS
cd mobile && flutter build apk --release
```

`key.properties` et les fichiers `.jks` sont exclus de git. Sans `key.properties`, l'APK de
version publiée sort non signé, donc non installable.
