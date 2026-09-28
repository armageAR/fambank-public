<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La clave natural de una suscripción push es el endpoint (única por
     * browser/dispositivo). El unique anterior (user_id, auth) permitía
     * filas duplicadas para el mismo endpoint al re-suscribirse.
     */
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'auth']);
        });

        // Deduplicar endpoints conservando la suscripción más reciente
        DB::statement('DELETE FROM push_subscriptions WHERE id NOT IN (SELECT MAX(id) FROM push_subscriptions GROUP BY endpoint)');

        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->unique('endpoint');
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['endpoint']);
            $table->unique(['user_id', 'auth']);
        });
    }
};
