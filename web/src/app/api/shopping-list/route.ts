import { proxyRoute } from "@/lib/laravel-proxy";

// `magasin_id` et `tri` sont des paramètres de lecture : la liste est la même, seul le regard
// change. Sans recopie de la requête, l'écran recevrait toujours la liste sans prix ni rayon.
export const GET = proxyRoute("/shopping-list", {
  label: "de la liste de courses",
  forwardQuery: true,
});
