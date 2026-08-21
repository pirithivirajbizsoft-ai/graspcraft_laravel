<?php

namespace App\Services\Inventory;

use App\Models\InventoryProduct;
use App\Models\Uom;
use App\Support\Messages;
use Illuminate\Support\Collection;

/** New functionality, not a port of anything in graspcraft_backend. */
class ProductService
{
    public function create(array $dto): InventoryProduct
    {
        $dto['product_code'] = $this->generateCode();

        return InventoryProduct::create($dto);
    }

    /** @return array{count: int, rows: Collection} */
    public function findAll(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limit = $req['limit'] ?? null ?: 10;
        $offset = ($pageNo - 1) * $limit;

        $query = InventoryProduct::query()->with('uom');

        if (! empty($req['search_text'])) {
            $s = $req['search_text'];
            $query->where(function ($q) use ($s) {
                $q->where('name', 'ILIKE', "%{$s}%")
                    ->orWhere('product_code', 'ILIKE', "%{$s}%")
                    ->orWhere('description', 'ILIKE', "%{$s}%");
            });
        }

        if (isset($req['is_active']) && $req['is_active'] !== '') {
            $query->where('is_active', filter_var($req['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($req['is_stockable']) && $req['is_stockable'] !== '') {
            $query->where('is_stockable', filter_var($req['is_stockable'], FILTER_VALIDATE_BOOLEAN));
        }

        // low_stock_alert is the primary threshold; min_stock is the fallback
        // used only for a product that has no alert threshold set.
        if (! empty($req['low_stock'])) {
            $query->where('is_stockable', true)->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('low_stock_alert')->where('low_stock_alert', '>', 0)
                        ->whereColumn('current_stock', '<=', 'low_stock_alert');
                })->orWhere(function ($q2) {
                    $q2->where(function ($q3) {
                        $q3->whereNull('low_stock_alert')->orWhere('low_stock_alert', '<=', 0);
                    })->whereNotNull('min_stock')->where('min_stock', '>', 0)
                        ->whereColumn('current_stock', '<=', 'min_stock');
                });
            });
        }

        $count = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();

        return ['count' => $count, 'rows' => $rows];
    }

    public function findOne(string $id): ?InventoryProduct
    {
        return InventoryProduct::query()->with('uom')->where('id', $id)->first();
    }

    /**
     * product_code and current_stock are immutable outside their own
     * dedicated flow (auto-generated at create time / stock-movement-only,
     * respectively) — neither is ever a declared field on the update
     * request, so whitelisted() already excludes them before $dto reaches
     * here.
     */
    public function update(string $id, array $dto): InventoryProduct
    {
        $product = InventoryProduct::query()->findOrFail($id);
        $product->update($dto);

        return $product->fresh('uom');
    }

    public function remove(string $id): int
    {
        $product = InventoryProduct::query()->findOrFail($id);

        if ((float) $product->current_stock > 0) {
            throw new \RuntimeException(Messages::EM023);
        }

        return $product->delete();
    }

    /**
     * The product's own UOM resolved to its base unit, plus every sibling
     * derived unit — base first, then by conversion_factor ascending. Feeds
     * the unit dropdown on the Stock In/Out screens.
     */
    public function uomFamily(string $id): Collection
    {
        $product = InventoryProduct::query()->with('uom')->findOrFail($id);

        if (! $product->uom) {
            return collect();
        }

        $baseUnitId = $product->uom->resolveBaseUnitId();

        return Uom::query()
            ->where(function ($q) use ($baseUnitId) {
                $q->where('id', $baseUnitId)->orWhere('base_unit', $baseUnitId);
            })
            ->where('is_active', true)
            ->orderByRaw('base_unit IS NOT NULL, conversion_factor ASC')
            ->get();
    }

    /** PRD + an 8-digit zero-padded sequence, never reused (checked including soft-deleted rows). */
    private function generateCode(): string
    {
        $last = InventoryProduct::query()
            ->withTrashed()
            ->where('product_code', 'like', 'PRD%')
            ->lockForUpdate()
            ->orderByDesc('product_code')
            ->value('product_code');

        $seq = $last ? ((int) substr($last, 3)) + 1 : 1;

        return 'PRD'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT);
    }
}
