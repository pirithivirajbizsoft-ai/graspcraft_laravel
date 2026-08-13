# Parity harness

These scripts compare this Laravel API against the NestJS API in
`../../graspcraft_backend`, endpoint by endpoint. They are the evidence that the
conversion is behaviour-preserving, and they are the first thing to run after
touching a service.

Both servers must point at the **same database**.

```bash
# terminal 1
cd graspcraft_backend && npm run start:dev      # :3000

# terminal 2
cd graspcraft_laravel && php artisan serve      # :8000
```

## 1. Route table

Extracts every `@Get/@Post/@Patch/@Delete` from the Nest controllers and diffs
it against `php artisan route:list`. Catches a missing or misspelled path.

```bash
node parity/route-parity.mjs
# nest routes: 97 / laravel routes: 97 / ROUTE TABLES MATCH
```

## 2. Read parity

Replays 69 requests against both APIs and diffs status codes and JSON bodies.
Only `id`, `token`, `iat` and `exp` are masked — timestamps are compared to the
millisecond, so a date-format regression fails the run.

```bash
node parity/read-parity.mjs            # all fixtures
node parity/read-parity.mjs reports    # only fixtures whose name contains "reports"
```

## 3. Write parity

Runs 12 write scenarios (order creation with and without a staff code, combo
creation with commission, master CRUD, and the validation-rejection paths)
through each backend in turn and diffs the results.

```bash
node parity/write-parity.mjs
```

## Fixtures

An empty database only proves the two agree on empty results. `seed.sql` loads a
realistic dataset — kiosk/photographer/staff/manager users with genuinely
encrypted columns, a customer with framed photos, a basket, orders in three
states, a commission ledger entry and an archived order.

```bash
# values are the AES-256-CBC ciphertexts App\Support\Crypto produces; regenerate
# with:  php artisan tinker --execute="echo App\Support\Crypto::encrypt('Seed Kiosk');"
psql -h localhost -U postgres -d facecraft \
  -v kiosk_name=d036fa287e34ba1ccc1767ec26b19d0f \
  -v kiosk_email=dc65d5fc8ad381ad14c5692bb7bc2589f3a67394ef54b0b021dc4c28c09fa038 \
  -v photog_name=eb3209edafa2341a5e949cf81cd92ac8d7c5b25f0931d88c6fcc700d135cb00c \
  -v photog_email=ae26bf7989157042bf9022e3af0a117c66dc0f3976d9294bbbca4c0c96b2b8a3 \
  -v staff_name=6a89abf95bbf5c8a4c31830044b3cd3b \
  -v staff_email=f8a5292f9eab7f39171added8cf79bc112bbca5aedcfa3c7790e7ea33e246a0c \
  -v mgr_name=1722dfb8914b550190894c89379f0bef \
  -v mgr_email=52e7e8583fc0dd55a662354e489995d525fd6629703e73721d20263b100999b1 \
  -v phone=1d093684d411b3b28adf37779209072e \
  -v pass=a049665d0753abfd6e58f31d72034848 \
  -f parity/seed.sql

# ... run the harnesses ...

psql -h localhost -U postgres -d facecraft -f parity/cleanup.sql
```

Every seeded id is prefixed `seed-`, and `cleanup.sql` deletes exactly those
rows plus the `wdiff%` records the write harness creates. Run it when done —
leaving fixtures behind will skew the report totals.

**Only run this against a scratch or dev database.**

## Known non-comparable fixtures

Two fixtures are compared by status code only, and both are flagged in
`read-parity.mjs`:

- `discount/getall-search` — the Nest endpoint throws
  `operator does not exist: integer ~~* unknown`; Laravel's grammar casts the
  operand and the query succeeds. See "Known deviations" in the main README.
- `app/dashboard` — `today_orders` is derived from the host process's local
  midnight in Node and from `config('app.timezone')` here. Identical on a UTC
  host; different on a developer machine in another zone.
