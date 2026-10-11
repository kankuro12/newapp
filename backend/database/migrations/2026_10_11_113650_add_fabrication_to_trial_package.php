<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The seeded all-products trial keeps covering every business type, now including iron works.
     */
    public function up(): void
    {
        foreach (DB::table('billing_packages')->where('name', 'Standard trial')->get() as $package) {
            $products = json_decode($package->products, true) ?: [];
            if (in_array('garage', $products, true) && ! in_array('fabrication', $products, true)) {
                DB::table('billing_packages')->where('id', $package->id)->update([
                    'products' => json_encode([...$products, 'fabrication']), 'version' => $package->version + 1, 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Package terms are versioned history; removing a product is a platform package edit.
    }
};
