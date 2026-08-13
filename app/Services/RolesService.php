<?php

namespace App\Services;

use App\Models\Role;
use App\Models\RoleKioskMap;
use Illuminate\Support\Collection;

/** Port of graspcraft_backend/src/modules/roles/roles.service.ts. */
class RolesService
{
    public function create(array $dto): Role
    {
        $existing = Role::query()->where('user_id', $dto['user_id'] ?? null)->first();

        if ($existing?->id) {
            throw new \RuntimeException('Role Already exist for this user...!');
        }

        $role = Role::create($dto);

        foreach ($dto['kiosk_ids'] ?? [] as $kioskId) {
            RoleKioskMap::create(['role_id' => $role->id, 'kiosk_id' => $kioskId]);
        }

        return $role;
    }

    /**
     * @return array{count: int, rows: Collection}
     */
    public function findAll(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limit = $req['limit'] ?? null ?: 10;

        /*
         * The offset uses the RAW limit, not the defaulted one — with no `limit`
         * in the body Node produces NaN and Postgres receives OFFSET NaN. Using
         * the defaulted value here would change which page comes back for that
         * (malformed) request; 0 is the closest sane equivalent.
         */
        $offset = ($pageNo - 1) * ($req['limit'] ?? 0);

        $query = Role::query();

        if (! empty($req['search_text'])) {
            $query->where('role_name', 'ILIKE', '%'.$req['search_text'].'%');
        }

        $count = (clone $query)->count();

        $roles = $query
            ->with([
                // Sequelize selects only role_id + kiosk_id here, and the hasMany
                // matches on role_id, so no extra key is needed.
                'roleKioskMap' => fn ($q) => $q->select(['role_id', 'kiosk_id'])
                    ->with(['kiosk' => fn ($u) => $u->select(['id', 'name'])]),
                'users' => fn ($q) => $q->select(['id', 'user_name']),
            ])
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $this->hideBelongsToKeys($roles);

        return ['count' => $count, 'rows' => $roles];
    }

    /**
     * A flattened shape built by hand — NOT the model. The edit screen reads
     * exactly these four keys.
     *
     * For an unknown id the Node version assigns `role?.dataValues?.x` to each
     * key, which is `undefined`, and JSON.stringify DROPS undefined values — so
     * the response body is `{}`, not four nulls. Returning an object here
     * reproduces that; a PHP array of nulls would serialise them all.
     *
     * @return array<string, mixed>|\stdClass
     */
    public function findOne(string $id): array|\stdClass
    {
        $role = Role::query()
            ->with([
                'roleKioskMap' => fn ($q) => $q->select(['role_id', 'kiosk_id'])
                    ->with(['kiosk' => fn ($u) => $u->select(['id', 'user_name'])]),
                'users' => fn ($q) => $q->select(['id', 'user_name']),
            ])
            ->where('id', $id)
            ->first();

        if (! $role) {
            return new \stdClass;
        }

        return [
            'role_name' => $role->role_name,
            'user_id' => $role->user_id,
            'kiosk_ids' => $role->roleKioskMap->pluck('kiosk_id')->all(),
            'is_active' => $role->is_active,
        ];
    }

    /**
     * Drop the `id` that Eloquent forces into a constrained belongsTo select.
     *
     * Sequelize resolves an include with a SQL join, so `attributes: ['name']`
     * returns literally that one column. Eloquent runs a second query and matches
     * in PHP, so the owner key has to be selected even when the caller does not
     * want it. Selecting it and hiding it afterwards is the only way to get the
     * same payload out.
     */
    private function hideBelongsToKeys(Collection $roles): void
    {
        foreach ($roles as $role) {
            $role->users?->makeHidden('id');

            foreach ($role->roleKioskMap ?? [] as $map) {
                $map->kiosk?->makeHidden('id');
            }
        }
    }

    /** Kiosk mappings are replaced wholesale, not diffed. */
    public function update(string $id, array $dto): array
    {
        $updated = [Role::query()->where('id', $id)->update(
            array_intersect_key($dto, array_flip(['role_name', 'is_active', 'user_id']))
        )];

        RoleKioskMap::query()->where('role_id', $id)->forceDelete();

        foreach ($dto['kiosk_ids'] ?? [] as $kioskId) {
            RoleKioskMap::create(['role_id' => $id, 'kiosk_id' => $kioskId]);
        }

        return $updated;
    }

    public function remove(string $id): int
    {
        // The role is soft deleted; its kiosk mappings are removed outright.
        $deleted = Role::query()->where('id', $id)->delete();

        RoleKioskMap::query()->where('role_id', $id)->forceDelete();

        return $deleted;
    }
}
