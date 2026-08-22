<?php

namespace App\Services;

use App\Models\BomItem;
use App\Models\Combo;
use App\Models\ComboUserCommission;
use App\Models\ProdCombMap;
use App\Models\User;
use App\Services\Inventory\BomService;
use App\Support\Enums\UsersType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A rejection the user can act on, as opposed to an unexpected failure.
 * The controller surfaces its message instead of the generic EM008.
 */
class CommissionValidationError extends \RuntimeException {}

/** Port of graspcraft_backend/src/modules/admin/combo/combo.service.ts. */
class ComboService
{
    public function __construct(private readonly BomService $bomService) {}

    /** Commission may be configured for anyone except these. */
    private const COMMISSION_EXCLUDED_USER_TYPES = [
        UsersType::SUPER_ADMIN->value,
        UsersType::ADMIN->value,
        UsersType::CUSTOMER->value,
    ];

    /**
     * Validates the incoming commission rows and shapes them for insert.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws CommissionValidationError with a message the admin can act on
     */
    private function buildCommissionRows(string $comboId, ?array $userCommissions): array
    {
        if (empty($userCommissions)) {
            return [];
        }

        $seen = [];
        foreach ($userCommissions as $row) {
            $userId = $row['user_id'] ?? null;

            if (in_array($userId, $seen, true)) {
                throw new CommissionValidationError('The same user is listed more than once.');
            }

            $seen[] = $userId;

            if (($row['commission_type'] ?? null) === 'percent'
                && (float) ($row['commission_value'] ?? 0) > 100) {
                throw new CommissionValidationError('Percent commission cannot be more than 100.');
            }
        }

        // One query for the whole set rather than per row.
        $byId = User::query()
            ->select(['id', 'user_type'])
            ->whereIn('id', $seen)
            ->get()
            ->keyBy('id');

        foreach ($seen as $userId) {
            $user = $byId->get($userId);

            if (! $user) {
                throw new CommissionValidationError('One of the selected users no longer exists.');
            }

            if (in_array($user->user_type, self::COMMISSION_EXCLUDED_USER_TYPES, true)) {
                throw new CommissionValidationError(
                    "Commission cannot be set for a {$user->user_type} account."
                );
            }
        }

        return array_map(fn ($row) => [
            'combo_id' => $comboId,
            'user_id' => $row['user_id'],
            'commission_type' => $row['commission_type'],
            'commission_value' => $row['commission_value'],
            'status' => true,
        ], $userCommissions);
    }

    public function create(array $dto): Combo
    {
        return DB::transaction(function () use ($dto) {
            $combo = Combo::create($dto);

            foreach ($dto['product_ids'] ?? [] as $productId) {
                ProdCombMap::create(['combo_id' => $combo->id, 'product_id' => $productId]);
            }

            foreach ($this->buildCommissionRows($combo->id, $dto['user_commissions'] ?? null) as $row) {
                ComboUserCommission::create($row);
            }

            // New functionality, not part of the Node port — see BomService.
            if (array_key_exists('bom_items', $dto)) {
                $this->bomService->replaceFor('COMBO', $combo->id, $dto['bom_items']);
            }

            return $combo;
        });
    }

    public function findOne(string $id): ?Combo
    {
        $combo = Combo::query()
            ->with([
                // Sequelize asks for ['combo_id', 'product_id'] here — no `id`.
                'prodCombMap' => fn ($q) => $q->select(['id', 'combo_id', 'product_id'])->with('products'),
                // user_type and status are needed so the edit screen can still show
                // a user who has since been deactivated, instead of a blank row.
                'comboUserCommission.user' => fn ($q) => $q->select([
                    'id', 'user_name', 'name', 'user_type', 'status',
                ]),
                'bomItems.inventoryProduct.uom',
            ])
            ->where('id', $id)
            ->first();

        // `id` is selected only so Eloquent can hydrate the nested products.
        $combo?->prodCombMap->makeHidden('id');

        return $combo;
    }

    public function update(string $id, array $dto): array
    {
        return DB::transaction(function () use ($id, $dto) {
            $updateCombo = [Combo::query()->where('id', $id)->update(
                array_intersect_key($dto, array_flip([
                    'combo_name', 'price', 'description', 'img_url', 'status',
                ]))
            )];

            if (! empty($dto['product_ids'])) {
                ProdCombMap::query()->where('combo_id', $id)->forceDelete();

                foreach ($dto['product_ids'] as $productId) {
                    ProdCombMap::create(['combo_id' => $id, 'product_id' => $productId]);
                }
            }

            /*
             * Replaced wholesale, same as product_ids. A MISSING key means the
             * client did not send the field at all, so leave existing rows alone;
             * an empty array means "remove every commission".
             */
            if (array_key_exists('user_commissions', $dto)) {
                ComboUserCommission::query()->where('combo_id', $id)->forceDelete();

                foreach ($this->buildCommissionRows($id, $dto['user_commissions']) as $row) {
                    ComboUserCommission::create($row);
                }
            }

            // New functionality — see BomService. array_key_exists (not
            // !empty) so a submitted [] clears every row, same as
            // user_commissions above.
            if (array_key_exists('bom_items', $dto)) {
                $this->bomService->replaceFor('COMBO', $id, $dto['bom_items']);
            }

            return $updateCombo;
        });
    }

    public function remove(string $id): int
    {
        $deleteCombo = Combo::query()->where('id', $id)->delete();

        if ($deleteCombo) {
            ProdCombMap::query()->where('combo_id', $id)->forceDelete();

            // Config only — the earned ledger keeps its own snapshot and is untouched.
            ComboUserCommission::query()->where('combo_id', $id)->forceDelete();

            // New functionality — BOM is configuration, not a historical
            // reference, so it's cleaned up rather than blocking delete.
            BomItem::query()->where('owner_type', 'COMBO')->where('owner_id', $id)->delete();
        }

        return $deleteCombo;
    }

    /** @return array{count: int, rows: Collection} */
    public function findAll(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limit = $req['limit'] ?? null ?: 10;
        $offset = ($pageNo - 1) * $limit;

        $query = Combo::query();

        if (! empty($req['search_text'])) {
            $s = $req['search_text'];
            $query->where(function ($q) use ($s) {
                $q->where('combo_name', 'ILIKE', "%{$s}%")
                    ->orWhere('description', 'ILIKE', "%{$s}%");
            });
        }

        $count = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();

        return ['count' => $count, 'rows' => $rows];
    }
}
