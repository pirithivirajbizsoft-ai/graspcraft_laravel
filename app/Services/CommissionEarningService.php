<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\ComboUserCommission;
use App\Models\StaffCommission;
use App\Models\User;
use App\Support\Enums\OrderStatus;
use App\Support\Enums\UsersType;

/**
 * A staff code that was supplied but could not be resolved.
 *
 * The order is rejected rather than recorded without attribution — an operator
 * who typed an ID expects the commission to land somewhere, so silently dropping
 * it would lose money without anyone noticing.
 */
class StaffCodeError extends \RuntimeException {}

/**
 * Port of graspcraft_backend/src/modules/admin/commission/commission-earning.service.ts.
 *
 * Everything to do with EARNING commission on an order. Kept separate from
 * UsersService because the earning path has to run inside the order-create
 * transaction.
 */
class CommissionEarningService
{
    /** Staff code may resolve to anyone except these. */
    private const STAFF_EXCLUDED_USER_TYPES = [
        UsersType::SUPER_ADMIN->value,
        UsersType::ADMIN->value,
        UsersType::CUSTOMER->value,
    ];

    /**
     * Maps the code typed at the kiosk (users.role_id, e.g. "Fc110") to a user.
     *
     * Supplying the code is optional, but supplying a bad one is an error — see
     * resolveStaffCodeOrThrow. This lower-level call just reports what it found.
     *
     * @return array{staff_id: ?string, staff_name: ?string, note: ?string}
     *                                                                      note is null when resolved cleanly, else 'not_found'|'duplicate_code'
     */
    public function resolveStaffCode(?string $staffCode): array
    {
        $code = trim($staffCode ?? '');

        if ($code === '') {
            return ['staff_id' => null, 'staff_name' => null, 'note' => 'not_found'];
        }

        $matches = User::query()
            ->where('role_id', $code)
            ->where('status', true)
            ->whereNotIn('user_type', self::STAFF_EXCLUDED_USER_TYPES)
            ->get();

        if ($matches->isEmpty()) {
            return ['staff_id' => null, 'staff_name' => null, 'note' => 'not_found'];
        }

        if ($matches->count() > 1) {
            // Ambiguous — crediting the wrong person is worse than not crediting.
            return ['staff_id' => null, 'staff_name' => null, 'note' => 'duplicate_code'];
        }

        $user = $matches->first();

        return [
            'staff_id' => $user->id,
            'staff_name' => $user->displayName(),
            'note' => null,
        ];
    }

    /**
     * Same lookup, but rejects instead of returning an unresolved result.
     *
     * Used by order creation: an order may be placed with no staff ID at all, but
     * if one is given it must identify exactly one active, commission-eligible
     * account. A typo or a deactivated account fails the order so the operator can
     * correct it, rather than completing an order whose commission goes nowhere.
     *
     * @return array{staff_id: ?string, staff_name: ?string, note: ?string}
     *
     * @throws StaffCodeError
     */
    public function resolveStaffCodeOrThrow(?string $staffCode): array
    {
        $resolution = $this->resolveStaffCode($staffCode);
        $code = trim($staffCode ?? '');

        if ($resolution['note'] === 'not_found') {
            throw new StaffCodeError(
                "Staff ID \"{$code}\" is not valid or the account is inactive."
            );
        }

        if ($resolution['note'] === 'duplicate_code') {
            throw new StaffCodeError(
                "Staff ID \"{$code}\" matches more than one active account. Please contact the administrator."
            );
        }

        return $resolution;
    }

    /**
     * Writes ledger rows for one order.
     *
     * Only runs for completed orders with a resolved staff member. Returns the
     * rows created (empty when nothing was owed).
     *
     * @param  array{order_ref_id: string, order_id: ?string, customer_id: ?string, order_status: ?string, staff_id: ?string, staff_code: ?string, staff_name: ?string}  $params
     * @return array<int, array<string, mixed>>
     */
    public function accrueForOrder(array $params): array
    {
        if (($params['order_status'] ?? null) !== OrderStatus::COMPLETED->value) {
            return [];
        }

        $staffId = $params['staff_id'] ?? null;
        $customerId = $params['customer_id'] ?? null;

        if (! $staffId || ! $customerId) {
            return [];
        }

        // Callers that only have the id (e.g. an order completed after creation)
        // leave the name out; resolve it here so the ledger snapshot is complete.
        $resolvedStaffName = $params['staff_name'] ?? null;
        if (! $resolvedStaffName) {
            $staff = User::withTrashed()->where('id', $staffId)->first();
            $resolvedStaffName = $staff?->displayName();
        }

        // `orders` carries no line items; the basket lives on cart, joined only by
        // customer_id. Load the combo so price/name can be snapshot.
        $cartRows = Cart::with('combo')->where('customer_id', $customerId)->get();

        if ($cartRows->isEmpty()) {
            return [];
        }

        /*
         * A customer can place several orders while old cart rows are still around
         * (cart has no order_id and is only cleared explicitly). Any cart row that
         * already produced commission must never produce it again.
         */
        $cartIds = $cartRows->pluck('id')->all();
        $usedCartIds = StaffCommission::withTrashed()
            ->whereIn('cart_id', $cartIds)
            ->pluck('cart_id')
            ->flip();

        $freshCarts = $cartRows->reject(fn ($c) => $usedCartIds->has($c->id));

        if ($freshCarts->isEmpty()) {
            return [];
        }

        $comboIds = $freshCarts->pluck('combo_id')->filter()->unique()->values()->all();

        if ($comboIds === []) {
            return [];
        }

        $configs = ComboUserCommission::query()
            ->whereIn('combo_id', $comboIds)
            ->where('user_id', $staffId)
            ->where('status', true)
            ->get();

        if ($configs->isEmpty()) {
            return [];
        }

        $configByCombo = $configs->keyBy('combo_id');

        $ledgerRows = [];
        foreach ($freshCarts as $cart) {
            $config = $configByCombo->get($cart->combo_id);

            if (! $config) {
                continue;
            }

            $combo = $cart->combo;
            $price = (float) ($combo->price ?? 0);
            $qty = (int) ($cart->qty ?: 1) ?: 1;
            $value = (float) ($config->commission_value ?? 0);

            $amount = $config->commission_type === 'percent'
                ? ($price * $qty * $value) / 100
                : $value * $qty;

            if (! ($amount > 0)) {
                continue;
            }

            $ledgerRows[] = [
                'order_ref_id' => $params['order_ref_id'],
                'order_id' => $params['order_id'] ?? null,
                'cart_id' => $cart->id,
                'staff_id' => $staffId,
                'staff_code' => $params['staff_code'] ?? null,
                'staff_name' => $resolvedStaffName,
                'combo_id' => $cart->combo_id,
                'combo_name' => $combo->combo_name ?? null,
                'combo_price' => $price,
                'qty' => $qty,
                'commission_type' => $config->commission_type,
                'commission_value' => $value,
                'commission_amount' => (float) number_format($amount, 2, '.', ''),
                'payout_status' => 'pending',
                'order_state' => 'active',
                'order_status_at_entry' => $params['order_status'],
            ];
        }

        if ($ledgerRows === []) {
            return [];
        }

        foreach ($ledgerRows as $row) {
            StaffCommission::create($row);
        }

        return $ledgerRows;
    }

    /**
     * Marks an order's ledger rows as cancelled or deleted.
     *
     * A pending row is reversed. A row that has already been paid out keeps its
     * `paid` status — the money is gone — and is only flagged via order_state so
     * the report can show it.
     *
     * @param  string[]  $orderRefIds
     * @param  'cancelled'|'deleted'  $state
     * @return array{reversed: int, flagged: int}
     */
    public function markOrderState(
        array $orderRefIds,
        string $state,
        ?string $changedBy = null,
    ): array {
        if ($orderRefIds === []) {
            return ['reversed' => 0, 'flagged' => 0];
        }

        $stamp = [
            'order_state' => $state,
            'order_state_changed_at' => now(),
            'order_state_changed_by' => $changedBy,
        ];

        $reversed = StaffCommission::query()
            ->whereIn('order_ref_id', $orderRefIds)
            ->where('payout_status', 'pending')
            ->update($stamp + ['payout_status' => 'reversed']);

        $flagged = StaffCommission::query()
            ->whereIn('order_ref_id', $orderRefIds)
            ->where('payout_status', 'paid')
            ->update($stamp);

        return ['reversed' => $reversed, 'flagged' => $flagged];
    }
}
