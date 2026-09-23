/**
 * Préparation d'une image prise ou choisie par l'utilisateur, avant envoi au serveur.
 *
 * Deux usages partagent ce module : la photo d'une recette et la photo d'assiette analysée par
 * le coach. Un seul algorithme, donc un seul comportement à corriger le jour où il évolue.
 */

export const MAX_IMAGE_BYTES = 350 * 1024;
export const MAX_IMAGE_SIDE = 1200;
export const IMAGE_QUALITY = 0.75;

/** Poids réel du contenu binaire porté par un data URI, en octets. */
export function dataUriBytes(dataUri: string): number {
  const base64 = dataUri.split(",")[1] ?? "";
  return Math.ceil((base64.length * 3) / 4);
}

export type ResizeOptions = {
  /** Côté maximum en pixels. Défaut : 1200. */
  maxSide?: number;
  /** Qualité JPEG entre 0 et 1. Défaut : 0,75. */
  quality?: number;
};

/**
 * Redimensionne et recompresse en JPEG, en respectant l'orientation EXIF — sans quoi une photo
 * prise en portrait avec un téléphone arrive couchée.
 */
export async function resizeImage(file: File, options: ResizeOptions = {}): Promise<string> {
  const maxSide = options.maxSide ?? MAX_IMAGE_SIDE;
  const quality = options.quality ?? IMAGE_QUALITY;

  const bitmap = await createImageBitmap(file, { imageOrientation: "from-image" });
  const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
  const width = Math.max(1, Math.round(bitmap.width * scale));
  const height = Math.max(1, Math.round(bitmap.height * scale));

  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = height;

  const context = canvas.getContext("2d");
  if (!context) throw new Error("Impossible de préparer l’image.");

  context.drawImage(bitmap, 0, 0, width, height);
  bitmap.close();

  return canvas.toDataURL("image/jpeg", quality);
}
