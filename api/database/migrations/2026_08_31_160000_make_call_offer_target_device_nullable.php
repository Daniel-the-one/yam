<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An offer routed to a user has no single target device. Keep the legacy
     * device route nullable so both routing modes can be stored.
     */
    public function up(): void
    {
        Schema::table('call_offers', function (Blueprint $table) {
            $table->string('to_device_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('call_offers', function (Blueprint $table) {
            $table->string('to_device_id')->nullable(false)->change();
        });
    }
};
