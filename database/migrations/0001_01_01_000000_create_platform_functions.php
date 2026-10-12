<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database functions every later migration may rely on.
 *
 * platform_uuid_v7(): RFC 9562 UUID v7 generator usable as a column default on PostgreSQL 16+
 * (PostgreSQL 18 ships uuidv7(); this keeps 16/17 supported). Used only where the framework
 * inserts rows itself (queue tables); application models generate v7 ids in PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION platform_uuid_v7() RETURNS uuid
LANGUAGE plpgsql VOLATILE AS $$
DECLARE
    unix_ms bigint := floor(extract(epoch FROM clock_timestamp()) * 1000);
    bytes bytea := gen_random_bytes(16);
BEGIN
    bytes := set_byte(bytes, 0, ((unix_ms >> 40) & 255)::int);
    bytes := set_byte(bytes, 1, ((unix_ms >> 32) & 255)::int);
    bytes := set_byte(bytes, 2, ((unix_ms >> 24) & 255)::int);
    bytes := set_byte(bytes, 3, ((unix_ms >> 16) & 255)::int);
    bytes := set_byte(bytes, 4, ((unix_ms >> 8) & 255)::int);
    bytes := set_byte(bytes, 5, (unix_ms & 255)::int);
    bytes := set_byte(bytes, 6, ((get_byte(bytes, 6) & 15) | 112));   -- version 7
    bytes := set_byte(bytes, 8, ((get_byte(bytes, 8) & 63) | 128));   -- RFC 4122 variant
    RETURN encode(bytes, 'hex')::uuid;
END;
$$;
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS platform_uuid_v7()');
    }
};
