# Mavi'oh — site web client

Interface web de Mavi'oh, pensée pour l'ordinateur. Next.js 16 (App Router), React 19, TypeScript,
Tailwind CSS v4, TanStack Query, React Hook Form et Zod.

Voir le [README du dépôt](../README.md) pour la vue d'ensemble et `docs/API.md` pour l'API.

## Lancer le site

```bash
cp .env.example .env.local   # puis ajuster API_URL si besoin
npm ci
npm run dev                  # http://localhost:3000
```

Le backend Laravel doit tourner en parallèle (`cd ../backend && php artisan serve`).

Compte de démonstration : `demo@mavioh.app` / `Demo1234!`

## Variables d'environnement

| Variable | Rôle |
|---|---|
| `API_URL` | URL de l'API lue côté serveur par les route handlers `src/app/api/**` |
| `NEXT_PUBLIC_API_URL` | même URL, utilisée comme repli |
| `NEXT_ALLOWED_DEV_ORIGINS` | origines supplémentaires acceptées par le serveur de développement |

## Architecture

Le navigateur n'appelle jamais Laravel directement : chaque requête passe par un route handler Next
sous `src/app/api/**` qui relaie l'appel avec le jeton porteur, applique un délai d'attente et
normalise les erreurs. Cela évite toute configuration CORS et garde une seule origine.

| Dossier | Contenu |
|---|---|
| `src/app/api/` | relais vers l'API Laravel (un fichier par endpoint) |
| `src/app/dashboard/` | pages du produit, une par section |
| `src/components/ui/` | bibliothèque de composants (cartes, champs, modales, sélecteurs) |
| `src/lib/` | client HTTP navigateur, session, types, formats, libellés, clés de cache |
| `src/hooks/` | hooks de données partagés |

## Qualité

```bash
npm run lint
npx tsc --noEmit
npm run build
```
