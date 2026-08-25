<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table): void {
            $table->string('business_phone_normalized', 20)->nullable()->after('instance')->index();
            $table->string('embedded_signup_status', 30)->default('not_configured')->after('status');
            $table->string('coexistence_status', 30)->default('inconclusive')->after('embedded_signup_status');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table): void {
            $table->dropIndex(['business_phone_normalized']);
            $table->dropColumn(['business_phone_normalized', 'embedded_signup_status', 'coexistence_status']);
        });
    }
};
