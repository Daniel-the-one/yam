<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')
            ->constrained('users')
            ->restrictOnDelete();
            $table->foreignId('medecin_id')
            ->constrained('users')
            ->restrictOnDelete();
            $table->enum('initie_par', ['patient', 'medecin']);
            $table->enum('status', [
                'initie',
                'sonne',
                'decroche',
                'non_decroche',
                'termine',
            ])->default('initie');

            $table->decimal('tarif_par_minute', 8, 2)->default(100.00);
            $table->decimal('solde_consomme', 10,2)->default(0.00);
            $table->timestamp('date_sonnerie')->nullable();
            $table->timestamp('date_decroche')->nullable();
            $table->timestamp('date_fin')->nullable();
            $table->string('raison_fin')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appels');
    }
};