<?php

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
        Schema::table('medicine_requisitions', function (Blueprint $table) {
            $table->text('recipient_email')->nullable()->after('remarks');
            $table->boolean('email_sent')->default(false)->after('recipient_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medicine_requisitions', function (Blueprint $table) {
            $table->dropColumn(['recipient_email', 'email_sent']);
        });
    }
};
