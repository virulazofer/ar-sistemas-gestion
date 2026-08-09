<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\User;
use App\Services\Imports\Historical\SupplierCatalogPreviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConfirmSupplierCatalogCommand extends Command
{
    protected $signature = 'imports:confirm-supplier-catalog
                            {path : Ruta absoluta al Excel Lis*.xlsx}
                            {--list-date=2026-08-07 : Fecha de lista}
                            {--user= : ID usuario}';

    protected $description = 'Importa definitivamente el catálogo proveedor con stock 0 (sin compras ni lotes).';

    public function handle(SupplierCatalogPreviewService $catalog): int
    {
        $path = (string) $this->argument('path');
        $userId = (int) ($this->option('user') ?: User::query()->orderBy('id')->value('id'));
        if ($userId < 1) {
            $this->error('Sin usuario.');

            return self::FAILURE;
        }

        $this->info('Analizando…');
        $batch = $catalog->analyzePath($path, basename($path), $userId, $this->option('list-date'));
        $this->info('Preview: válidos='.$batch->rows_valid.' a crear='.($batch->classification_summary['products_to_create'] ?? '?'));

        $this->info('Confirmando catálogo (stock 0)…');
        $confirmed = $catalog->confirm($batch->fresh());

        $products = Product::query()->where('import_batch_id', $confirmed->id)->count();
        $stockSum = (float) Product::query()->where('import_batch_id', $confirmed->id)->sum('qty_on_hand');
        $lots = (int) DB::table('inventory_lots')->count();
        $invMov = (int) DB::table('inventory_movements')->count();

        $this->line("batch={$confirmed->id} imported={$confirmed->rows_imported} products={$products} stock_sum={$stockSum}");
        $this->line("inventory_lots_total={$lots} inventory_movements_total={$invMov} (no deben crecer por este import)");
        $this->warn('Movimientos históricos NO fueron importados.');

        return self::SUCCESS;
    }
}
