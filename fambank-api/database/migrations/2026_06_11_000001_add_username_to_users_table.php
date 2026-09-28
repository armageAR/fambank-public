<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Agrega username (único, obligatorio) para login.
     * Backfill para usuarios existentes: parte local del email en minúsculas,
     * con sufijo del id si colisiona.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->after('name');
        });

        foreach (DB::table('users')->get(['id', 'email']) as $user) {
            $base = Str::of($user->email)->before('@')->lower()->replaceMatches('/[^a-z0-9_-]/', '')->value();
            $username = $base !== '' ? $base : 'user' . $user->id;

            if (DB::table('users')->where('username', $username)->where('id', '!=', $user->id)->exists()) {
                $username .= $user->id;
            }

            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable(false)->change();
            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
