<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Buat password nullable untuk mendukung user Google
            $table->string('password')->nullable()->change();
            // Tambah kolom foto profil
            $table->string('photo')->nullable()->after('password');
            // Tambah kolom Google OAuth
            $table->string('google_id')->nullable()->unique()->after('photo');
            $table->string('provider')->nullable()->after('google_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['photo', 'google_id', 'provider']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
