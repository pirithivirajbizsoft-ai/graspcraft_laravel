# PhotoCraft AI — Laravel API

A port of `graspcraft_backend` (NestJS 11 + Sequelize) to Laravel, serving the
same Angular panel and kiosk app (`graspcraft_frontend`) from the same
PostgreSQL database.

This is a **behaviour-preserving re-implementation, not a redesign**. The
frontend is unchanged, so every route, every response shape and every stored
value has to match what the Nest API produced. Where the original does something
odd, this port does the same odd thing and says why in a comment.

- 97 routes across 13 controllers — verified identical to the Nest route table
- 23 Eloquent models mapped onto the existing schema, no migration required
- Verified against the running Nest API: see [parity/](parity/README.md)

---

## Running it

Requires PHP 8.4 with `pdo_pgsql`, `openssl`, `zip`, `curl`, `mbstring`,
`fileinfo`, and Composer 2.

```bash
composer install
# copy graspcraft_backend/.env across — the key names are identical
php artisan serve         # http://localhost:8000/api
```

The Angular dev proxy (`graspcraft_frontend/proxy.conf.json`) points at
`localhost:3000`. Change it to `8000` to run the panel against this API.

### Configuration

`config/photocraft.php` gathers everything the Node app read straight off
`process.env`. **The env key names are deliberately unchanged**, so a deployed
server's `.env` can be copied across without a rename pass — including
`NODE_ENV`, which still selects the database and the dev/prod AWS credentials.

Two Laravel-specific notes:

- `CACHE_STORE`, `SESSION_DRIVER` and `QUEUE_CONNECTION` are set to `file`/`sync`
  on purpose. The database drivers would create `cache`, `sessions` and `jobs`
  tables in the PhotoCraft schema, and this application owns no tables.
- `AWS_CA_BUNDLE` is only needed on Windows, where PHP ships no CA bundle and
  every AWS call fails TLS verification. Leave it unset on a Linux server.

### Database

The schema is owned by `graspcraft_backend/db/schema.sql` and is **not** managed
from here. There is one migration, and it exists solely so a brand-new
environment can be built with `php artisan migrate`; it executes `schema.sql`
verbatim and refuses to run if the tables already exist.

This is a deliberate change from the Node app, which ran Sequelize
`sync({ alter: true })` on every boot — diffing models against the live schema
and issuing `ALTER TABLE` with no review step and no rollback. Nothing here ever
alters the schema.

---

## How it maps

| NestJS | Laravel |
|---|---|
| `src/modules/**/*.controller.ts` | `app/Http/Controllers/` |
| `src/modules/**/*.service.ts` | `app/Services/` |
| `src/modules/**/dto/*.dto.ts` | `app/Http/Requests/` |
| `src/database/models/*.ts` | `app/Models/` |
| `src/utils/*` | `app/Support/` |
| `src/main.ts` | `bootstrap/app.php`, `config/cors.php`, `AppServiceProvider` |
| `src/middleware/middleware.ts` | `app/Http/Middleware/JwtMiddleware.php` |

Each ported file names its source in the class docblock.

---

## Things that will break the frontend if changed

These are not style choices. Each one was found by diffing against the live Nest
API, and each has a comment at the site explaining it.

**A failure is not an HTTP error.** Every Nest controller catches and *returns*
`errorResponse(...)`, so a failure goes out with a success status and
`status: false` in the body. The panel branches on the body, never the status.
`App\Support\ApiResponse` reproduces this.

**POST returns 201.** Nest answers `@Post` with 201 and everything else with
200. Hence `ApiResponse::created()` on every POST route.

**Timestamps are camelCase.** Sequelize's `underscored: true` renames the
*columns* but leaves the *attributes* as `createdAt`/`updatedAt`/`deletedAt`.
`common-table.config.ts` in the panel reads `createdAt`. The raw-SQL report
endpoints correctly return snake_case, and `reports.interface.ts` reads
`created_at` — both are right, and `BaseModel` keeps them apart.

**Relation keys keep their case.** `roleKioskMap`, `prodCombMap`,
`comboUserCommission`. Eloquent would snake_case them;
`BaseModel::relationsToArray()` puts them back. Note that setting
`$snakeAttributes = false` instead *looks* like the fix but silently stops the
`email_id`/`ph_number` accessors firing, leaking ciphertext into responses.

**Numbers that are strings.** `COUNT()` is bigint and `SUM(numeric)` is numeric;
node-postgres hands both to JavaScript as strings. The dashboard counters, the
report totals and the photographer upload count are all cast with `::text` so
the JSON types match.

**Constrained eager loads.** Sequelize resolves `attributes: ['name']` with a
SQL join and returns that one column. Eloquent matches in PHP and needs the join
key in the SELECT, so it is selected and then hidden —
`App\Services\Concerns\HidesJoinKeys`.

**Validation is load-bearing.** Nest's global `ValidationPipe({ whitelist: true })`
*strips* undeclared properties and rejects invalid ones with HTTP 400 and its own
body shape. `ClassValidatorRequest` reproduces the semantics, the messages and
their order. Dropping it would let fields through that the Nest API silently
discarded — a combo `size` posted by the panel is stripped today, and would
start being persisted.

**`Model.update()` returns an array.** Sequelize resolves it to
`[affectedCount]`, and controllers return it verbatim, so the panel receives
`data: [1]`.

**The session timezone is UTC.** The Node app connects with
`useUTC: true, timezone: 'UTC'`. `DATE(created_at) = CURRENT_DATE` is evaluated
in the session timezone, so without this the "today" counters disagree either
side of local midnight.

---

## Encryption

`users.name`, `email_id`, `ph_number` and `password` are encrypted at rest with
deterministic AES-256-CBC — a fixed key **and a fixed IV**, hex encoded
(`App\Support\Crypto`, ported from `CryptoUtil.ts`).

The fixed IV is not an oversight to correct: login works by encrypting the
submitted password and looking the row up **by equality**. A random IV would
make every login fail, and rotating the key would make every existing row
unreadable.

The key and IV are hardcoded, exactly as in the Node source. The
`ALGORITHM`/`SECRET_KEY`/`IV` entries in `graspcraft_backend/.env` are dead —
nothing reads them, and their values are not even well-formed (they carry
trailing `;` and `// 32 chars` comments). They are deliberately not carried over.

Reads are forgiving (`tryDecrypt` falls back to the raw value) because rows
written before encryption was introduced are still plaintext.

`customers` is **not** encrypted at the model level — `customerRegistor`
encrypts only `password`, explicitly. Adding accessors there would corrupt every
existing customer record.

## Authentication

Tokens are HS256 JWTs issued by `App\Support\Jwt` with the same `JWT_SECRET`,
the same claims and the same 1-day expiry as the Node app — **not** Sanctum — so
a token already in a browser keeps working across the cutover.

`JwtMiddleware` is ported but **not registered**, because the equivalent line in
`app.module.ts` is commented out and every endpoint is currently unauthenticated.
Turning it on is one line in `bootstrap/app.php`, but audit the Angular app
first: any call it makes without a token will start returning 401.

---

## Known deviations

Places where this API deliberately behaves differently:

**Searching `discount` and `pc-amt-master` works here and 500s in Nest.**
`discount_amount`, `photo_count` and `price` are numeric columns, and Sequelize
emits a bare `ILIKE` against them, which Postgres rejects with
`operator does not exist: integer ~~* unknown` — so searching those two lists
returns the error envelope today. Laravel's Postgres grammar casts LIKE operands
to text automatically, so the query simply succeeds. Reproducing the failure
would mean dropping to `whereRaw` purely to make a query throw.

**`today_orders` is timezone-sensitive.** Node derives it from the host
process's local midnight; this port uses `config('app.timezone')` (UTC).
Identical on the UTC deployment hosts. If a host runs on another clock, set
`APP_TIMEZONE` to match.

## Known pre-existing bugs, reproduced

Faithfully carried over, with a comment at each site. Fix them as their own
change — in both back ends — once the cutover is verified.

- `ProductsService::findAllProductSize` searches a `weight` column that does not
  exist (the column is `width`), so product-size search always fails.
- `createOrder` returns `customer.role_name` as the **encrypted** staff name,
  because the Node line reads `dataValues.name` and bypasses the decrypting
  getter.
- `ReportsService::getCashOrders` flattens the kiosk user into the order row
  using raw `dataValues`, so `email_id`, `ph_number` and `password` appear as
  ciphertext at the top level and decrypted inside the nested `user` object.
- The report date windows relabel a UTC instant as `+05:30` without shifting it,
  moving every window by 5h30m.
- `OrderPaginationDto` requires **both** `from_date` and `to_date`, or neither
  validates — a request with neither is a 400.
- `userLogin` contains a role check whose condition excludes every known
  `user_type`, so the branch is unreachable.

Two things were fixed, because neither is behavioural or visible: the report SQL
now uses bound parameters instead of interpolating request data (the Node
version is injectable through every filter), and the schema is no longer altered
at boot.

## Not ported

Dead code in the Node project, left out on purpose: the `photocart` module
(commented out in `app.module.ts`), `facecompre.ts`, `s3upload.ts` (points at a
hardcoded `gramosoft-face-detection` bucket), `AwsService.uploads3`, and the
`convertoJpeg`/HEIC path. `sharp`, `heic-convert` and `archiver` are declared in
`package.json` but never called, which is why this port needs no image-processing
library. The `PhotoCart` model is kept because the table exists and
`Customer::photoCart` references it.

Swagger is not ported. The Node app served it at `/api-docs` on non-production
only; `darkaonline/l5-swagger` is the equivalent if it is wanted here.

---

## Verifying a change

```bash
node parity/route-parity.mjs     # route tables match
node parity/read-parity.mjs      # 69 read fixtures
node parity/write-parity.mjs     # 12 write scenarios
```

See [parity/README.md](parity/README.md) for the fixture data and the two
fixtures that are compared by status code only.
