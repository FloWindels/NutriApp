import { ModulePayant } from "@/components/ui/offre-requise";
import StockPage from "@/components/stock/stock-page";

export default function StockRoute() {
  return (
    <ModulePayant capacite="stock">
      <StockPage />
    </ModulePayant>
  );
}
