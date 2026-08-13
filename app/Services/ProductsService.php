<?php

namespace App\Services;

use App\Models\ProdCombMap;
use App\Models\Product;
use App\Models\ProductSize;
use Illuminate\Support\Collection;

/** Port of graspcraft_backend/src/modules/admin/products/products.service.ts. */
class ProductsService
{
    public function create(array $dto): Product
    {
        return Product::create($dto);
    }

    public function findOne(string $id): ?Product
    {
        return Product::query()->where('id', $id)->first();
    }

    public function update(string $id, array $dto): array
    {
        // Sequelize's Model.update() resolves to [affectedCount]; the
        // controller returns it verbatim, so the panel sees `data: [1]`.
        return [Product::query()->where('id', $id)->update($dto)];
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

        return Product::query()->where('id', $id)->delete();
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
