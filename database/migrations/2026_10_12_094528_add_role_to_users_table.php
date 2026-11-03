<?php

use App\Enums\AccountRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * Defaults to the least-privileged role so that accounts created by
             * any path — registration, a seeder, a factory, an import — can
             * never be born privileged. Elevation is always an explicit act.
             */
            $table->string('role', 20)
                ->default(AccountRole::User->value)
                ->after('password');

            /* Role filtering drives the admin user list. */
            $table->index('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
