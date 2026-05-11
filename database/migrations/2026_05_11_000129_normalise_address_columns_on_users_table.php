<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalises the users table address columns.
 *
 * Context:
 *  - 2026_04_27 decomposed the old `address` text column into 5 split columns
 *    using names: address_house_no, address_street, address_barangay,
 *    address_municipality, address_province.
 *  - 2026_04_30 re-added a single `address` column, creating a conflict.
 *  - Profile.vue and ProfileUpdateRequest use: address_house_lot_unit,
 *    address_street_name, address_barangay, address_municipality_city,
 *    address_province.
 *
 * This migration:
 *  1. Drops the re-added `address` column if it still exists.
 *  2. Renames the 4 mismatched split columns to align with the form field names.
 *     (address_barangay and address_province already match — left as-is.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop the stale single-string address column re-added by 2026_04_30.
            if (Schema::hasColumn('users', 'address')) {
                $table->dropColumn('address');
            }

            // Rename split columns to match form field names exactly,
            // so no mapping layer is needed in the controller.
            if (Schema::hasColumn('users', 'address_house_no')) {
                $table->renameColumn('address_house_no', 'address_house_lot_unit');
            }

            if (Schema::hasColumn('users', 'address_street')) {
                $table->renameColumn('address_street', 'address_street_name');
            }

            if (Schema::hasColumn('users', 'address_municipality')) {
                $table->renameColumn('address_municipality', 'address_municipality_city');
            }

            // address_barangay  → already matches form field name, no rename needed.
            // address_province  → already matches form field name, no rename needed.
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Restore the old column names from 2026_04_27.
            if (Schema::hasColumn('users', 'address_house_lot_unit')) {
                $table->renameColumn('address_house_lot_unit', 'address_house_no');
            }

            if (Schema::hasColumn('users', 'address_street_name')) {
                $table->renameColumn('address_street_name', 'address_street');
            }

            if (Schema::hasColumn('users', 'address_municipality_city')) {
                $table->renameColumn('address_municipality_city', 'address_municipality');
            }

            // Re-add the single address column.
            if (! Schema::hasColumn('users', 'address')) {
                $table->string('address')->nullable()->after('course');
            }
        });
    }
};