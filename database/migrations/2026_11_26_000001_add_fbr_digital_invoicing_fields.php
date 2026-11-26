<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->boolean('fbr_enabled')->default(false);
            $table->string('fbr_environment', 20)->default('sandbox'); // sandbox | production
            $table->text('fbr_token')->nullable();                     // stored encrypted
            $table->string('seller_province', 50)->nullable();
            $table->string('fbr_scenario_id', 10)->nullable();         // sandbox only, e.g. SN001
            $table->boolean('fbr_prices_include_tax')->default(true);
            $table->boolean('fbr_auto_post')->default(false);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('hs_code', 20)->nullable();
            $table->string('fbr_uom', 100)->nullable();
            $table->string('fbr_sale_type', 150)->nullable();
            $table->decimal('fbr_tax_rate', 5, 2)->nullable();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('ntn', 20)->nullable();
            $table->string('province', 50)->nullable();
            $table->string('registration_type', 20)->default('Unregistered'); // Registered | Unregistered
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('fbr_status', 20)->nullable();        // posted | failed
            $table->string('fbr_invoice_number', 100)->nullable();
            $table->string('fbr_environment', 20)->nullable();
            $table->timestamp('fbr_posted_at')->nullable();
            $table->text('fbr_error')->nullable();
            $table->json('fbr_response')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['fbr_status', 'fbr_invoice_number', 'fbr_environment', 'fbr_posted_at', 'fbr_error', 'fbr_response']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['ntn', 'province', 'registration_type']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['hs_code', 'fbr_uom', 'fbr_sale_type', 'fbr_tax_rate']);
        });
        Schema::table('business_settings', function (Blueprint $table) {
            $table->dropColumn(['fbr_enabled', 'fbr_environment', 'fbr_token', 'seller_province', 'fbr_scenario_id', 'fbr_prices_include_tax', 'fbr_auto_post']);
        });
    }
};
