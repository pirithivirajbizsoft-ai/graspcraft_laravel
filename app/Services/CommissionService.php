<?php

namespace App\Services;

use App\Models\CommissionPayout;
use App\Models\StaffCommission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** A rejection the admin can act on; the controller surfaces its message. */
class PayoutValidationError extends \RuntimeException {}

/** Port of graspcraft_backend/src/modules/admin/commission/commission.service.ts. */
class CommissionService
{
    /**
     * Report listing. Follows the getCashOrders shape: { count, rows, summary }.
     *
     * @return array{count: int, rows: mixed, summary: array<string, float>}
     */
    public function getCommissions(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limits = $req['limit'] ?? null ?: 10;
        $offsets = ($pageNo - 1) * $limits;

        $build = function () use ($req) {
            $query = StaffCommission::query();

            if (! empty($req['staff_id'])) {
                $query->whereIn('staff_id', $req['staff_id']);
            }

            if (! empty($req['payout_status'])) {
                $query->whereIn('payout_status', $req['payout_status']);
            }

            if (! empty($req['order_state'])) {
                $query->whereIn('order_state', $req['order_state']);
            }

            if (! empty($req['from_date']) && ! empty($req['to_date'])) {
                $from = Carbon::parse($req['from_date']);
                $to = Carbon::parse($req['to_date'])->setTime(23, 59, 59, 999999);
                $query->whereBetween('created_at', [$from, $to]);
            }

            if (! empty($req['search_text'])) {
                $s = $req['search_text'];
                $query->where(function ($q) use ($s) {
                    $q->where('order_id', 'ILIKE', "%{$s}%")
                        ->orWhere('staff_code', 'ILIKE', "%{$s}%")
                        ->orWhere('combo_name', 'ILIKE', "%{$s}%")
                        // staff_name is stored decrypted in the ledger, so it is
                        // searchable here even though users.name is not.
                        ->orWhere('staff_name', 'ILIKE', "%{$s}%");
                });
            }

            return $query;
        };

        $count = $build()->count();

        $rows = $build()
            ->orderByDesc('created_at')
            ->offset($offsets)
            ->limit($limits)
            ->get();

        // Totals across the whole filtered set, not just the current page.
        $totals = $build()
            ->select('payout_status')
            ->selectRaw('SUM(commission_amount) AS total')
            ->selectRaw('COUNT(id) AS entries')
            ->groupBy('payout_status')
            ->get();

        $summary = [
            'total_pending' => 0,
            'total_paid' => 0,
            'total_reversed' => 0,
            'total_all' => 0,
        ];

        foreach ($totals as $row) {
            $amount = (float) ($row->total ?? 0);

            match ($row->payout_status) {
                'pending' => $summary['total_pending'] = $amount,
                'paid' => $summary['total_paid'] = $amount,
                'reversed' => $summary['total_reversed'] = $amount,
                default => null,
            };
        }

        // Reversed commission is not owed, so it stays out of the headline total.
        $summary['total_all'] = $summary['total_pending'] + $summary['total_paid'];

        return ['count' => $count, 'rows' => $rows, 'summary' => $summary];
    }

    /**
     * Settles a set of pending entries as one payout.
     *
     * Two rules are enforced here rather than in the UI, because the UI cannot be
     * trusted: every entry must belong to the SAME staff member, and every entry
     * must still be pending.
     *
     * @return array{payout_id: string, staff_id: ?string, total_amount: float, entry_count: int}
     *
     * @throws PayoutValidationError
     */
    public function payout(array $req): array
    {
        $commissionIds = $req['commission_ids'] ?? [];

        return DB::transaction(function () use ($req, $commissionIds) {
            $entries = StaffCommission::query()->whereIn('id', $commissionIds)->get();

            if ($entries->count() !== count($commissionIds)) {
                throw new PayoutValidationError(
                    'One or more selected commission entries no longer exist.'
                );
            }

            $staffIds = $entries->pluck('staff_id')->unique()->values();

            if ($staffIds->count() > 1) {
                throw new PayoutValidationError(
                    'A payout can only cover one staff member at a time.'
                );
            }

            $notPending = $entries->filter(fn ($e) => $e->payout_status !== 'pending');

            if ($notPending->isNotEmpty()) {
                $states = $notPending->pluck('payout_status')->unique()->implode(', ');

                throw new PayoutValidationError(
                    "Only pending commission can be paid out (found: {$states})."
                );
            }

            $staffId = $staffIds->first();
            $total = $entries->sum(fn ($e) => (float) ($e->commission_amount ?? 0));

            $paidByName = null;
            if (! empty($req['paid_by'])) {
                $actor = User::withTrashed()->where('id', $req['paid_by'])->first();
                $paidByName = $actor?->displayName();
            }

            /*
             * Reference details only belong to a transfer or a cheque; drop them if
             * the mode is cash so a stale value cannot linger from the form.
             */
            $paymentMode = $req['payment_mode'] ?? 'cash';
            $keepReference = in_array($paymentMode, ['online', 'cheque'], true);

            $payout = CommissionPayout::create([
                'staff_id' => $staffId,
                'staff_name' => $entries->first()->staff_name,
                'total_amount' => (float) number_format($total, 2, '.', ''),
                'entry_count' => $entries->count(),
                'paid_by' => $req['paid_by'] ?? null,
                'paid_by_name' => $paidByName,
                'paid_at' => now(),
                'payout_date' => $req['payout_date'] ?: now()->format('Y-m-d'),
                'payment_mode' => $paymentMode,
                'released_by' => $req['released_by'] ?? null,
                'reference_no' => $keepReference ? ($req['reference_no'] ?? null) : null,
                'reference_date' => $keepReference ? ($req['reference_date'] ?? null) : null,
                'remarks' => $req['remarks'] ?? null,
            ]);

            StaffCommission::query()
                ->whereIn('id', $commissionIds)
                ->update(['payout_status' => 'paid', 'payout_id' => $payout->id]);

            return [
                'payout_id' => $payout->id,
                'staff_id' => $staffId,
                'total_amount' => (float) number_format($total, 2, '.', ''),
                'entry_count' => $entries->count(),
            ];
        });
    }

    /**
     * Payout history.
     *
     * @return array{count: int, rows: Collection}
     */
    public function getPayouts(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limits = $req['limit'] ?? null ?: 10;
        $offsets = ($pageNo - 1) * $limits;

        $build = function () use ($req) {
            $query = CommissionPayout::query();

            if (! empty($req['staff_id'])) {
                $query->whereIn('staff_id', $req['staff_id']);
            }

            if (! empty($req['from_date']) && ! empty($req['to_date'])) {
                $from = Carbon::parse($req['from_date']);
                $to = Carbon::parse($req['to_date'])->setTime(23, 59, 59, 999999);
                $query->whereBetween('paid_at', [$from, $to]);
            }

            return $query;
        };

        $count = $build()->count();

        $rows = $build()
            ->orderByDesc('paid_at')
            ->offset($offsets)
            ->limit($limits)
            ->get();

        return ['count' => $count, 'rows' => $rows];
    }
}
