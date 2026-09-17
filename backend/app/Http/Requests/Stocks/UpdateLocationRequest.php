<?php

namespace App\Http\Requests\Stocks;

class UpdateLocationRequest extends StoreLocationRequest
{
    protected function ignoredStockId(): ?int
    {
        $id = $this->route('stock');

        return is_numeric($id) ? (int) $id : null;
    }
}
