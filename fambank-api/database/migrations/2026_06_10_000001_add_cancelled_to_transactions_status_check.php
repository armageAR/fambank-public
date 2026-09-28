<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agrega 'cancelled' a los estados permitidos de transactions.
     *
     * En Postgres, $table->enum() crea un varchar con un CHECK constraint;
     * para sumar un valor hay que dropearlo y recrearlo. Las instalaciones
     * nuevas (y los tests en SQLite) ya lo incluyen porque la migración
     * original fue actualizada.
     *
     * Además backfillea las cancelaciones históricas del member: antes se
     * guardaban como 'rejected' sin revisor (confirmed_by null).
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_status_check');
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_status_check CHECK (status IN ('pending', 'confirmed', 'rejected', 'cancelled'))");
        }

        DB::table('transactions')
            ->where('status', 'rejected')
            ->whereNull('confirmed_by')
            ->update(['status' => 'cancelled']);
    }

    public function down(): void
    {
        DB::table('transactions')
            ->where('status', 'cancelled')
            ->update(['status' => 'rejected']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_status_check');
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_status_check CHECK (status IN ('pending', 'confirmed', 'rejected'))");
        }
    }
};
