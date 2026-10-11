<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NEW_PRODUCTS = ['garage', 'repair', 'laundry', 'tailor', 'printing'];

    public function up(): void
    {
        Schema::table('business_workflows', function (Blueprint $table) {
            // Structured job-card fields per niche (vehicle number, IMEI, pieces, measurements, print spec).
            $table->json('details')->nullable();
            // Normalised vehicle number or device serial, for service history lookups.
            $table->string('lookup_key', 64)->nullable();
            $table->index(['tenant_id', 'lookup_key']);
        });
        // The seeded all-products trial keeps covering every business type.
        foreach (DB::table('billing_packages')->where('name', 'Standard trial')->get() as $package) {
            $products = json_decode($package->products, true) ?: [];
            if (in_array('gym', $products, true) && in_array('bookkeeping', $products, true)) {
                DB::table('billing_packages')->where('id', $package->id)->update([
                    'products' => json_encode(array_values(array_unique([...$products, ...self::NEW_PRODUCTS]))),
                    'version' => $package->version + 1, 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('business_workflows', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'lookup_key']);
            $table->dropColumn(['details', 'lookup_key']);
        });
    }
};
