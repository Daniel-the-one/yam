<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNotIn('role', ['medecin','patient'])
            ->update(['role' => 'patient']);
        
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['patient', 'medecin'])
                ->default('patient')
                ->change();
            $table->decimal('solde', 10, 2)->default(0.00)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('solde');
            $table->string('role')->default('user')->change();
        });
    }
};