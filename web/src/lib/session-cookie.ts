/**
 * Session du site : le jeton Sanctum vit dans un cookie httpOnly que seul le serveur Next lit.
 * Le navigateur n'en garde qu'un témoin sans valeur (`SESSION_MARKER`) pour savoir qu'une
 * session existe — un script injecté dans la page ne peut donc jamais lire le jeton.
 */

export const SESSION_COOKIE = "mavioh_session";

/** Valeur posée à la place du jeton dans les réponses de connexion et d'inscription. */
export const SESSION_MARKER = "httponly";

/** Durée de vie du cookie, alignée sur SANCTUM_EXPIRATION (14 jours par défaut). */
export const SESSION_MAX_AGE_SECONDS = Number(process.env.SESSION_MAX_AGE_SECONDS ?? 14 * 24 * 3600);
