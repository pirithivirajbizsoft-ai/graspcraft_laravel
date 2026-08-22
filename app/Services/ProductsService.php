<?php

namespace App\Services;

use App\Models\BomItem;
use App\Models\ProdCombMap;
use App\Models\Product;
use App\Models\ProductSize;
use App\Services\Inventory\BomService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Port of graspcraft_backend/src/modules/admin/products/products.service.ts. */
class ProductsService
{
    public function __construct(private readonly BomService $bomService) {}

    public function create(array $dto): Product
    {
        return DB::transaction(function () use ($dto) {
            $product = Product::create($dto);

            // New functionality, not part of the Node port — see BomService.
            if (array_key_exists('bom_items', $dto)) {
                $this->bomService->replaceFor('PRODUCT', $product->id, $dto['bom_items']);
            }

            return $product;
        });
    }

    public function findOne(string $id): ?Product
    {
        return Product::query()->with('bomItems.inventoryProduct.uom')->where('id', $id)->first();
    }

    public function update(string $id, array $dto): array
    {
        return DB::transaction(function () use ($id, $dto) {
            // Sequelize's Model.update() resolves to [affectedCount]; the
            // controller returns it verbatim, so the panel sees `data: [1]`.
            // bom_items is not a products column, so it's excluded here and
            // handled separately below — the raw query builder update()
            // below does not go through $fillable like Product::create() does.
            $updated = [Product::query()->where('id', $id)->update(
                array_intersect_key($dto, array_flip([
                    'product_name', 'price', 'size', 'description', 'photo_limit',
                    'product_type', 'img_url', 'status', 'certificate_name',
                ]))
            )];

            if (array_key_exists('bom_items', $dto)) {
                $this->bomService->replaceFor('PRODUCT', $id, $dto['bom_items']);
            }

            return $updated;
        });
    }

    /**
     * @return int|false false when the product is still mapped to a combo, which
     *                   the controller turns into EM019
     */
    public function remove(string $id): int|false
    {
        $existProd = ProdCombMap::query()->where('product_id', $id)->first();

        if ($existProd) {
            return false;
        }

        $deleted = Product::query()->where('id', $id)->delete();

        if ($deleted) {
            // New functionality — BOM is configuration, not a historical
            // reference, so it's cleaned up rather than blocking delete
            // (mirrors ComboService::remove()'s mapping/commission cleanup).
            BomItem::query()->where('owner_type', 'PRODUCT')->where('owner_id', $id)->delete();
        }

        return $deleted;
    }

    /** @return array{count: int, rows: Collection} */
    public function findAll(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limit = $req['limit'] ?? null ?: 10;
        $offset = ($pageNo - 1) * $limit;

        $query = Product::query();

        if (! empty($req['search_text'])) {
            $s = $req['search_text'];
            $query->where(function ($q) use ($s) {
                $q->where('product_name', 'ILIKE', "%{$s}%")
                    ->orWhere('description', 'ILIKE', "%{$s}%");
            });
        }

        $count = (clone $query)->count();

        $rows = $query->orderByDesc('created_at');

        /*
         * `limit: 0, page_no: 0` means "no pagination" — the kiosk uses it to pull
         * every product in one call. Any other pair paginates normally.
         */
        if (($req['limit'] ?? null) !== 0 && ($req['page_no'] ?? null) !== 0) {
            $rows->offset($offset)->limit($limit);
        }

        return ['count' => $count, 'rows' => $rows->get()];
    }

    // ─── product size ────────────────────────────────────────────────────────

    public function createProductSize(array $dto): ProductSize
    {
        return ProductSize::create($dto);
    }

    public function productSizeById(string $id): ?ProductSize
    {
        return ProductSize::query()->where('id', $id)->first();
    }

    public function updateProductSize(string $id, array $dto): array
    {
        // Sequelize's Model.update() resolves to [affectedCount]; the
        // controller returns it verbatim, so the panel sees `data: [1]`.
        return [ProductSize::query()->where('id', $id)->update($dto)];
    }

    public function removeProductSize(string $id): int
    {
        return ProductSize::query()->where('id', $id)->delete();
    }

    /** @return array{count: int, rows: Collection} */
    public function findAllProductSize(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limit = $req['limit'] ?? null ?: 10;
        $offset = ($pageNo - 1) * $limit;

        $query = ProductSize::query();

        if (! empty($req['search_text'])) {
            $s = $req['search_text'];
            $query->where(function ($q) use ($s) {
                $q->where('height', 'ILIKE', "%{$s}%")
                    /*
                     * KNOWN PRE-EXISTING BUG, reproduced.
                     *
                     * The column is `width`; this searches `weight`, which does not
                     * exist. Postgres rejects the whole statement, so searching the
                     * product-size list returns the ER001/EM008 envelope. Renaming
                     * it here would make search start working — a behaviour change
                     * outside this conversion. See README "Known pre-existing bugs".
                     */
                    ->orWhere('weight', 'ILIKE', "%{$s}%");
            });
        }

        $count = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();

        return ['count' => $count, 'rows' => $rows];
    }
}
