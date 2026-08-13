<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedInteger('code')->nullable()->unique()->after('id');
        });

        $suppliers = DB::table('suppliers')->orderBy('id')->get(['id']);
        $n = 1;
        foreach ($suppliers as $supplier) {
            DB::table('suppliers')->where('id', $supplier->id)->update(['code' => $n]);
            $n++;
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')->updateOrInsert(
                ['key' => 'suppliers.next_code'],
                [
                    'value' => (string) $n,
                    'type' => 'int',
                    'group' => 'suppliers',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });

        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('key', 'suppliers.next_code')->delete();
        }
    }
};
