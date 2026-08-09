<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('party_type', 20)->default('particular')->after('name');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('party_type', 20)->default('particular')->after('name');
        });

        // Normalizar condiciones fiscales libres conocidas a valores de catálogo.
        $map = [
            'Responsable Inscripto' => 'responsable_inscripto',
            'responsable inscripto' => 'responsable_inscripto',
            'Monotributo' => 'monotributista',
            'Monotributista' => 'monotributista',
            'Exento' => 'exento',
            'Consumidor Final' => 'consumidor_final',
            'No Responsable' => 'no_responsable',
        ];

        foreach (['clients', 'suppliers'] as $table) {
            foreach ($map as $from => $to) {
                DB::table($table)->where('tax_condition', $from)->update(['tax_condition' => $to]);
            }

            DB::table($table)
                ->whereNotNull('business_name')
                ->where('business_name', '!=', '')
                ->where(function ($q) {
                    $q->whereNull('dni')->orWhere('dni', '');
                })
                ->update(['party_type' => 'empresa']);
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('party_type');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('party_type');
        });
    }
};
