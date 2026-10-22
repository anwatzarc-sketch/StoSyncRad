<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Database functions every later migration may rely on.
 *
 * None are needed on MySQL 8.4: application models generate UUID v7 ids in PHP, and the queue tables
 * use auto-increment ids because the MySQL queue driver reads them back with lastInsertId(). The
 * migration is kept (as a no-op) so migration histories stay identical across environments, which the
 * PostgreSQL data transfer checks before copying.
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
