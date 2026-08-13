<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FRESH INSTALL ONLY — do not run this against dev or production.
 *
 * The PhotoCraft schema is owned by graspcraft_backend/db/schema.sql, which was
 * produced from the live database and is the reference both back ends are
 * written against. This migration exists so a brand-new environment can be
 * stood up with `php artisan migrate` instead of piping psql by hand; it does
 * not attempt to describe the schema in Blueprint calls, because a hand
 * translation of 23 tables, 37 constraints and 7 indexes would inevitably drift
 * from the file that is actually authoritative.
 *
 * Instead it executes schema.sql verbatim, and refuses to run if the tables are
 * already there — so pointing it at an existing database is a no-op rather than
 * a disaster.
 *
 * On dev and production the correct procedure is unchanged: restore schema.sql
 * and the appropriate seed with psql (see graspcraft_backend/db/README.md), and
 * never let a framework alter the live schema. That is the behaviour the Node
 * app's `sync({ alter: true })` had, and dropping it is one of the points of
 * this conversion.
 */
return new class extends Migration
{
    /** Relative to this repository, the schema file lives in the Node project. */
    private const SCHEMA_PATH = __DIR__.'/../../../graspcraft_backend/db/schema.sql';

    public function up(): void
    {
        if (Schema::hasTable('users')) {
            throw new RuntimeException(
                'Refusing to run: the PhotoCraft tables already exist. This '
                .'migration builds a schema from scratch and must only be used on '
                .'an empty database. See the class docblock.'
            );
        }

        $path = realpath(self::SCHEMA_PATH);

        if ($path === false || ! is_readable($path)) {
            throw new RuntimeException(
                'Cannot read '.self::SCHEMA_PATH.'. This migration reads the schema '
                .'from the graspcraft_backend checkout; clone it alongside this '
                .'project, or load db/schema.sql with psql instead.'
            );
        }

        $sql = file_get_contents($path);

        /*
         * pg_dump 18 wraps its output in \restrict / \unrestrict, which are psql
         * meta-commands and not SQL — PDO chokes on them.
         */
        $sql = preg_replace('/^\\\\(un)?restrict.*$/m', '', $sql);

        DB::unprepared($sql);
    }

    public function down(): void
    {
        /*
         * Deliberately not implemented. `down()` here would mean dropping every
         * table in the application, and there is no situation in which running
         * that against a PhotoCraft database is the right move.
         */
        throw new RuntimeException(
            'This migration is not reversible: rolling it back would drop the '
            .'entire PhotoCraft schema. Drop and recreate the database instead.'
        );
    }
};
