<?php

use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Clients\ClientController;
use App\Http\Controllers\Clients\ClientCurrentAccountController;
use App\Http\Controllers\Clients\ClientLedgerController;
use App\Http\Controllers\Commercial\CommercialChargeController;
use App\Http\Controllers\Commercial\DocumentalControlController;
use App\Http\Controllers\Commercial\ReceiptController;
use App\Http\Controllers\Equipment\EquipmentController;
use App\Http\Controllers\Equipment\EquipmentTypeController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Purchases\PurchaseController;
use App\Http\Controllers\Dashboard\ManagementDashboardController;
use App\Http\Controllers\Dashboard\OperationsDashboardController;
use App\Http\Controllers\Imports\HistoricalImportController;
use App\Http\Controllers\Imports\ImportController;
use App\Http\Controllers\Quotations\QuotationController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Sales\SaleController;
use App\Http\Controllers\Search\GlobalSearchController;
use App\Http\Controllers\Subscriptions\SubscriptionController;
use App\Http\Controllers\Suppliers\SupplierController;
use App\Http\Controllers\Suppliers\SupplierLedgerController;
use App\Http\Controllers\WorkOrders\WorkOrderController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Finance\CategoryController;
use App\Http\Controllers\Finance\ChartAccountController;
use App\Http\Controllers\Finance\DashboardController;
use App\Http\Controllers\Finance\ExchangeRateController;
use App\Http\Controllers\Finance\FinancialAccountController;
use App\Http\Controllers\Finance\ImputationRuleController;
use App\Http\Controllers\Finance\MovementController;
use App\Http\Controllers\Finance\QuickMovementController;
use App\Http\Controllers\Finance\RememberedClassificationController;
use App\Http\Controllers\Finance\UnclassifiedMovementController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('movements.quick')
        : redirect()->route('login');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', DashboardController::class)
        ->middleware('permission:dashboard.view')
        ->name('dashboard');

    Route::get('/dashboard/operativo', OperationsDashboardController::class)
        ->middleware('permission:dashboard.view')
        ->name('dashboard.operations');
    Route::get('/dashboard/gestion', ManagementDashboardController::class)
        ->middleware('permission:dashboard.view')
        ->name('dashboard.management');
    Route::post('/dashboard/cotizacion', [OperationsDashboardController::class, 'refreshRate'])
        ->middleware('permission:exchange_rates.create')
        ->name('dashboard.refresh-rate');

    Route::get('/buscar', GlobalSearchController::class)
        ->name('search');

    Route::get('/reportes', [ReportController::class, 'index'])
        ->middleware('permission:reports.view')
        ->name('reports.index');
    Route::get('/reportes/{type}', [ReportController::class, 'show'])
        ->middleware('permission:reports.view')
        ->name('reports.show');

    Route::get('/importaciones', [ImportController::class, 'index'])
        ->middleware('permission:imports.view')
        ->name('imports.index');
    Route::middleware('permission:imports.execute')->group(function () {
        Route::get('/importaciones/crear', [ImportController::class, 'create'])->name('imports.create');
        Route::post('/importaciones', [ImportController::class, 'store'])->name('imports.store');
        Route::get('/importaciones/historico', [HistoricalImportController::class, 'create'])->name('imports.historical.create');
        Route::post('/importaciones/historico/catalogo', [HistoricalImportController::class, 'storeCatalog'])->name('imports.historical.catalog');
        Route::post('/importaciones/historico/movimientos', [HistoricalImportController::class, 'storeMovements'])->name('imports.historical.movements');
        Route::post('/importaciones/{import}/confirmar', [ImportController::class, 'confirm'])->name('imports.confirm');
        Route::post('/importaciones/{import}/confirmar-catalogo', [HistoricalImportController::class, 'confirmCatalog'])->name('imports.historical.confirm-catalog');
        Route::post('/importaciones/{import}/confirmar-historico', [HistoricalImportController::class, 'confirmMovements'])->name('imports.historical.confirm-movements');
        Route::post('/importaciones/{import}/reprocesar-historico', [HistoricalImportController::class, 'reprocess'])->name('imports.historical.reprocess');
        Route::post('/importaciones/{import}/regla-cuenta', [HistoricalImportController::class, 'approveAccountRule'])->name('imports.historical.approve-account');
        Route::post('/importaciones/{import}/resolver/fechas', [HistoricalImportController::class, 'decideDates'])->name('imports.historical.decide-dates');
        Route::post('/importaciones/{import}/resolver/venta-compleja', [HistoricalImportController::class, 'decideComplex'])->name('imports.historical.decide-complex');
        Route::post('/importaciones/{import}/resolver/tarjetas', [HistoricalImportController::class, 'decideCards'])->name('imports.historical.decide-cards');
        Route::post('/importaciones/{import}/resolver/ambito', [HistoricalImportController::class, 'decideScope'])->name('imports.historical.decide-scope');
        Route::post('/importaciones/{import}/revertir', [ImportController::class, 'rollback'])->name('imports.rollback');
    });
    Route::get('/importaciones/{import}', [ImportController::class, 'show'])
        ->middleware('permission:imports.view')
        ->name('imports.show');
    Route::get('/importaciones/{import}/resolver-historico', [HistoricalImportController::class, 'resolve'])
        ->middleware('permission:imports.view')
        ->name('imports.historical.resolve');


    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::post('/theme', [ThemeController::class, 'update'])->name('theme.update');

    // Carga rápida (pantalla principal)
    Route::get('/movimientos/cargar', [QuickMovementController::class, 'create'])
        ->middleware('permission:movements.create')
        ->name('movements.quick');
    Route::post('/movimientos/cargar', [QuickMovementController::class, 'store'])
        ->middleware('permission:movements.create')
        ->name('movements.quick.store');

    Route::get('/movimientos', [MovementController::class, 'index'])
        ->middleware('permission:movements.view')
        ->name('movements.index');
    Route::get('/movimientos/{movement}', [MovementController::class, 'show'])
        ->middleware('permission:movements.view')
        ->name('movements.show');
    Route::post('/movimientos/{movement}/anular', [MovementController::class, 'void'])
        ->middleware('permission:movements.void')
        ->name('movements.void');

    Route::middleware('permission:accounts.view')->group(function () {
        Route::get('/cuentas', [FinancialAccountController::class, 'index'])->name('accounts.index');
    });
    Route::middleware('permission:accounts.create')->group(function () {
        Route::get('/cuentas/crear', [FinancialAccountController::class, 'create'])->name('accounts.create');
        Route::post('/cuentas', [FinancialAccountController::class, 'store'])->name('accounts.store');
    });
    Route::middleware('permission:accounts.edit')->group(function () {
        Route::get('/cuentas/{financial_account}/editar', [FinancialAccountController::class, 'edit'])->name('accounts.edit');
        Route::put('/cuentas/{financial_account}', [FinancialAccountController::class, 'update'])->name('accounts.update');
    });

    Route::get('/cotizaciones', [ExchangeRateController::class, 'index'])
        ->middleware('permission:exchange_rates.view')
        ->name('exchange-rates.index');
    Route::post('/cotizaciones/manual', [ExchangeRateController::class, 'storeManual'])
        ->middleware('permission:exchange_rates.create')
        ->name('exchange-rates.manual');
    Route::post('/cotizaciones/sincronizar', [ExchangeRateController::class, 'sync'])
        ->middleware('permission:exchange_rates.create')
        ->name('exchange-rates.sync');
    Route::middleware('permission:exchange_rates.create')->group(function () {
        Route::get('/cotizaciones/importar', [ExchangeRateController::class, 'importForm'])->name('exchange-rates.import');
        Route::post('/cotizaciones/importar/preview', [ExchangeRateController::class, 'importPreview'])->name('exchange-rates.import.preview');
        Route::post('/cotizaciones/importar/confirmar', [ExchangeRateController::class, 'importConfirm'])->name('exchange-rates.import.confirm');
        Route::post('/cotizaciones/backfill/preview', [ExchangeRateController::class, 'backfillPreview'])->name('exchange-rates.backfill.preview');
        Route::post('/cotizaciones/backfill/confirmar', [ExchangeRateController::class, 'backfillConfirm'])->name('exchange-rates.backfill.confirm');
    });

    // Clientes + CC
    Route::get('/clientes', [ClientController::class, 'index'])
        ->middleware('permission:clients.view')
        ->name('clients.index');
    Route::get('/clientes/cuentas-corrientes', [ClientCurrentAccountController::class, 'index'])
        ->middleware('permission:clients.view')
        ->name('clients.current-accounts');
    Route::middleware('permission:clients.create')->group(function () {
        Route::get('/clientes/crear', [ClientController::class, 'create'])->name('clients.create');
        Route::post('/clientes', [ClientController::class, 'store'])->name('clients.store');
    });
    Route::get('/clientes/{client}', [ClientController::class, 'show'])
        ->middleware('permission:clients.view')
        ->name('clients.show');
    Route::middleware('permission:clients.create')->group(function () {
        Route::get('/clientes/{client}/cargo', [ClientLedgerController::class, 'createCharge'])->name('clients.ledger.charge.create');
        Route::post('/clientes/{client}/cargo', [ClientLedgerController::class, 'storeCharge'])->name('clients.ledger.charge.store');
        Route::get('/clientes/{client}/pago', [ClientLedgerController::class, 'createPayment'])->name('clients.ledger.payment.create');
        Route::post('/clientes/{client}/pago', [ClientLedgerController::class, 'storePayment'])->name('clients.ledger.payment.store');
        Route::get('/clientes/{client}/credito', [ClientLedgerController::class, 'createCredit'])->name('clients.ledger.credit.create');
        Route::post('/clientes/{client}/credito', [ClientLedgerController::class, 'storeCredit'])->name('clients.ledger.credit.store');
        Route::get('/clientes/{client}/ajuste', [ClientLedgerController::class, 'createAdjustment'])->name('clients.ledger.adjustment.create');
        Route::post('/clientes/{client}/ajuste', [ClientLedgerController::class, 'storeAdjustment'])->name('clients.ledger.adjustment.store');
        Route::post('/clientes/{client}/aplicar-credito', [ClientLedgerController::class, 'applyCredit'])->name('clients.ledger.credit.apply');
    });
    Route::middleware('permission:clients.regularize')->group(function () {
        Route::get('/clientes/{client}/apertura-cc', [ClientLedgerController::class, 'createOpening'])->name('clients.ledger.opening.create');
        Route::post('/clientes/{client}/apertura-cc', [ClientLedgerController::class, 'storeOpening'])->name('clients.ledger.opening.store');
    });
    Route::middleware('permission:clients.edit')->group(function () {
        Route::get('/clientes/{client}/editar', [ClientController::class, 'edit'])->name('clients.edit');
        Route::put('/clientes/{client}', [ClientController::class, 'update'])->name('clients.update');
    });
    Route::post('/clientes/{client}/documentos', [ClientController::class, 'storeDocument'])
        ->middleware('permission:documents.create')
        ->name('clients.documents.store');
    Route::post('/clientes/{client}/cc/{entry}/anular', [ClientLedgerController::class, 'void'])
        ->middleware('permission:clients.void')
        ->name('clients.ledger.void');
    Route::post('/clientes/{client}/regularizar', [ClientController::class, 'regularize'])
        ->middleware('permission:clients.regularize')
        ->name('clients.regularize');

    // Proveedores + CC
    Route::get('/proveedores', [SupplierController::class, 'index'])
        ->middleware('permission:suppliers.view')
        ->name('suppliers.index');
    Route::middleware('permission:suppliers.create')->group(function () {
        Route::get('/proveedores/crear', [SupplierController::class, 'create'])->name('suppliers.create');
        Route::post('/proveedores', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::get('/proveedores/{supplier}/pago', [SupplierLedgerController::class, 'createPayment'])->name('suppliers.ledger.payment.create');
        Route::post('/proveedores/{supplier}/pago', [SupplierLedgerController::class, 'storePayment'])->name('suppliers.ledger.payment.store');
        Route::get('/proveedores/{supplier}/ajuste', [SupplierLedgerController::class, 'createAdjustment'])->name('suppliers.ledger.adjustment.create');
        Route::post('/proveedores/{supplier}/ajuste', [SupplierLedgerController::class, 'storeAdjustment'])->name('suppliers.ledger.adjustment.store');
    });
    Route::get('/proveedores/{supplier}', [SupplierController::class, 'show'])
        ->middleware('permission:suppliers.view')
        ->name('suppliers.show');
    Route::middleware('permission:suppliers.edit')->group(function () {
        Route::get('/proveedores/{supplier}/editar', [SupplierController::class, 'edit'])->name('suppliers.edit');
        Route::put('/proveedores/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
    });
    Route::post('/proveedores/{supplier}/documentos', [SupplierController::class, 'storeDocument'])
        ->middleware('permission:documents.create')
        ->name('suppliers.documents.store');
    Route::post('/proveedores/{supplier}/cc/{entry}/anular', [SupplierLedgerController::class, 'void'])
        ->middleware('permission:suppliers.void')
        ->name('suppliers.ledger.void');

    // Compras
    Route::get('/compras', [PurchaseController::class, 'index'])
        ->middleware('permission:purchases.view')
        ->name('purchases.index');
    Route::middleware('permission:purchases.create')->group(function () {
        Route::get('/compras/crear', [PurchaseController::class, 'create'])->name('purchases.create');
        Route::post('/compras', [PurchaseController::class, 'store'])->name('purchases.store');
    });
    Route::get('/compras/{purchase}', [PurchaseController::class, 'show'])
        ->middleware('permission:purchases.view')
        ->name('purchases.show');
    Route::post('/compras/{purchase}/anular', [PurchaseController::class, 'void'])
        ->middleware('permission:purchases.void')
        ->name('purchases.void');
    Route::post('/compras/{purchase}/documentos', [PurchaseController::class, 'storeDocument'])
        ->middleware('permission:documents.create')
        ->name('purchases.documents.store');

    // Productos
    Route::get('/productos', [ProductController::class, 'index'])
        ->middleware('permission:products.view')
        ->name('products.index');
    Route::post('/productos/bulk-destroy', [ProductController::class, 'bulkDestroy'])
        ->middleware('permission:products.void')
        ->name('products.bulk-destroy');
    Route::middleware('permission:products.create')->group(function () {
        Route::get('/productos/crear', [ProductController::class, 'create'])->name('products.create');
        Route::post('/productos', [ProductController::class, 'store'])->name('products.store');
    });
    Route::get('/productos/{product}', [ProductController::class, 'show'])
        ->middleware('permission:products.view')
        ->name('products.show');
    Route::middleware('permission:products.edit')->group(function () {
        Route::get('/productos/{product}/editar', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/productos/{product}', [ProductController::class, 'update'])->name('products.update');
    });

    // Stock
    Route::get('/stock', [StockController::class, 'index'])
        ->middleware('permission:stock.view')
        ->name('stock.index');
    Route::get('/stock/unidades', [StockController::class, 'units'])
        ->middleware('permission:stock.view')
        ->name('stock.units');
    Route::get('/stock/movimientos', [StockController::class, 'movements'])
        ->middleware('permission:stock.view')
        ->name('stock.movements');
    Route::middleware('permission:stock.adjust')->group(function () {
        Route::get('/stock/{product}/ajuste', [StockController::class, 'createAdjust'])->name('stock.adjust.create');
        Route::post('/stock/{product}/ajuste', [StockController::class, 'storeAdjust'])->name('stock.adjust.store');
    });
    Route::middleware('permission:stock.consume')->group(function () {
        Route::get('/stock/{product}/consumo', [StockController::class, 'createConsume'])->name('stock.consume.create');
        Route::post('/stock/{product}/consumo', [StockController::class, 'storeConsume'])->name('stock.consume.store');
    });
    Route::middleware('permission:stock.create')->group(function () {
        Route::get('/stock/{product}/reserva', [StockController::class, 'createReserve'])->name('stock.reserve.create');
        Route::post('/stock/{product}/reserva', [StockController::class, 'storeReserve'])->name('stock.reserve.store');
    });
    Route::middleware('permission:stock.transfer')->group(function () {
        Route::get('/stock/{product}/transferencia', [StockController::class, 'createTransfer'])->name('stock.transfer.create');
        Route::post('/stock/{product}/transferencia', [StockController::class, 'storeTransfer'])->name('stock.transfer.store');
    });
    Route::get('/stock/reconstruir', [StockController::class, 'rebuildForm'])
        ->middleware('permission:stock.rebuild')
        ->name('stock.rebuild.form');
    Route::post('/stock/reconstruir', [StockController::class, 'rebuild'])
        ->middleware('permission:stock.rebuild')
        ->name('stock.rebuild');

    // Equipos armados
    Route::get('/equipos', [EquipmentController::class, 'index'])
        ->middleware('permission:equipment.view')
        ->name('equipment.index');
    Route::middleware('permission:equipment.assemble')->group(function () {
        Route::get('/equipos/armar', [EquipmentController::class, 'create'])->name('equipment.create');
        Route::post('/equipos/armar', [EquipmentController::class, 'store'])->name('equipment.store');
    });
    Route::get('/equipos/tipos', [EquipmentTypeController::class, 'index'])
        ->middleware('permission:equipment.view')
        ->name('equipment.types.index');
    Route::post('/equipos/tipos', [EquipmentTypeController::class, 'store'])
        ->middleware('permission:equipment.create')
        ->name('equipment.types.store');
    Route::post('/equipos/tipos/{equipmentType}/plantilla', [EquipmentTypeController::class, 'storeTemplateItem'])
        ->middleware('permission:equipment.edit')
        ->name('equipment.types.template.store');
    Route::get('/equipos/tipos/{equipmentType}/plantilla.json', [EquipmentController::class, 'template'])
        ->middleware('permission:equipment.view')
        ->name('equipment.types.template');
    Route::get('/equipos/{equipment}', [EquipmentController::class, 'show'])
        ->middleware('permission:equipment.view')
        ->name('equipment.show');
    Route::post('/equipos/{equipment}/estado', [EquipmentController::class, 'changeStatus'])
        ->middleware('permission:equipment.change_status')
        ->name('equipment.status');
    Route::post('/equipos/{equipment}/desarmar', [EquipmentController::class, 'disassemble'])
        ->middleware('permission:equipment.disassemble')
        ->name('equipment.disassemble');
    Route::post('/equipos/{equipment}/componentes/{component}/reemplazar', [EquipmentController::class, 'replaceComponent'])
        ->middleware('permission:equipment.change_component')
        ->name('equipment.component.replace');

    // Órdenes de trabajo
    Route::get('/ot', [WorkOrderController::class, 'index'])
        ->middleware('permission:work_orders.view')
        ->name('work-orders.index');
    Route::middleware('permission:work_orders.create')->group(function () {
        Route::get('/ot/crear', [WorkOrderController::class, 'create'])->name('work-orders.create');
        Route::post('/ot', [WorkOrderController::class, 'store'])->name('work-orders.store');
    });
    Route::get('/ot/{workOrder}', [WorkOrderController::class, 'show'])
        ->middleware('permission:work_orders.view')
        ->name('work-orders.show');
    Route::post('/ot/{workOrder}/diagnostico', [WorkOrderController::class, 'storeDiagnosis'])
        ->middleware('permission:work_orders.edit')
        ->name('work-orders.diagnosis.store');
    Route::post('/ot/{workOrder}/tareas', [WorkOrderController::class, 'storeTask'])
        ->middleware('permission:work_orders.edit')
        ->name('work-orders.tasks.store');
    Route::post('/ot/{workOrder}/materiales', [WorkOrderController::class, 'storeMaterial'])
        ->middleware('permission:work_orders.consume_stock')
        ->name('work-orders.materials.store');
    Route::post('/ot/{workOrder}/cerrar', [WorkOrderController::class, 'close'])
        ->middleware('permission:work_orders.close')
        ->name('work-orders.close');
    Route::post('/ot/{workOrder}/cancelar', [WorkOrderController::class, 'cancel'])
        ->middleware('permission:work_orders.cancel')
        ->name('work-orders.cancel');
    Route::post('/ot/{workOrder}/documentos', [WorkOrderController::class, 'storeDocument'])
        ->middleware('permission:documents.create')
        ->name('work-orders.documents.store');

    // Abonos
    Route::get('/abonos', [SubscriptionController::class, 'index'])
        ->middleware('permission:subscriptions.view')
        ->name('subscriptions.index');
    Route::middleware('permission:subscriptions.create')->group(function () {
        Route::get('/abonos/crear', [SubscriptionController::class, 'create'])->name('subscriptions.create');
        Route::post('/abonos', [SubscriptionController::class, 'store'])->name('subscriptions.store');
    });
    Route::post('/abonos/generar-vencidos', [SubscriptionController::class, 'generateDue'])
        ->middleware('permission:subscriptions.generate')
        ->name('subscriptions.generate-due');
    Route::get('/abonos/{subscription}', [SubscriptionController::class, 'show'])
        ->middleware('permission:subscriptions.view')
        ->name('subscriptions.show');
    Route::post('/abonos/{subscription}/generar', [SubscriptionController::class, 'generate'])
        ->middleware('permission:subscriptions.generate')
        ->name('subscriptions.generate');
    Route::post('/abonos/{subscription}/estado', [SubscriptionController::class, 'changeStatus'])
        ->middleware('permission:subscriptions.edit')
        ->name('subscriptions.status');
    Route::post('/abonos/{subscription}/documentos', [SubscriptionController::class, 'storeDocument'])
        ->middleware('permission:documents.create')
        ->name('subscriptions.documents.store');

    // Presupuestos
    Route::get('/presupuestos', [QuotationController::class, 'index'])
        ->middleware('permission:quotations.view')
        ->name('quotations.index');
    Route::middleware('permission:quotations.create')->group(function () {
        Route::get('/presupuestos/crear', [QuotationController::class, 'create'])->name('quotations.create');
        Route::post('/presupuestos', [QuotationController::class, 'store'])->name('quotations.store');
    });
    Route::get('/presupuestos/{quotation}', [QuotationController::class, 'show'])
        ->middleware('permission:quotations.view')
        ->name('quotations.show');
    Route::post('/presupuestos/{quotation}/enviar', [QuotationController::class, 'send'])
        ->middleware('permission:quotations.send')
        ->name('quotations.send');
    Route::post('/presupuestos/{quotation}/aceptar', [QuotationController::class, 'accept'])
        ->middleware('permission:quotations.accept')
        ->name('quotations.accept');
    Route::post('/presupuestos/{quotation}/convertir', [QuotationController::class, 'convert'])
        ->middleware('permission:quotations.convert')
        ->name('quotations.convert');
    Route::post('/presupuestos/{quotation}/renovar', [QuotationController::class, 'renew'])
        ->middleware('permission:quotations.edit')
        ->name('quotations.renew');
    Route::post('/presupuestos/{quotation}/cancelar', [QuotationController::class, 'cancel'])
        ->middleware('permission:quotations.cancel')
        ->name('quotations.cancel');
    Route::post('/presupuestos/{quotation}/documentos', [QuotationController::class, 'storeDocument'])
        ->middleware('permission:documents.create')
        ->name('quotations.documents.store');

    // Ventas
    Route::get('/ventas', [SaleController::class, 'index'])
        ->middleware('permission:sales.view')
        ->name('sales.index');
    Route::middleware('permission:sales.create')->group(function () {
        Route::get('/ventas/crear', [SaleController::class, 'create'])->name('sales.create');
        Route::post('/ventas', [SaleController::class, 'store'])->name('sales.store');
    });
    Route::get('/ventas/{sale}', [SaleController::class, 'show'])
        ->middleware('permission:sales.view')
        ->name('sales.show');
    Route::post('/ventas/{sale}/confirmar', [SaleController::class, 'confirm'])
        ->middleware('permission:sales.confirm')
        ->name('sales.confirm');
    Route::post('/ventas/{sale}/anular', [SaleController::class, 'void'])
        ->middleware('permission:sales.void')
        ->name('sales.void');
    Route::post('/ventas/{sale}/documentos', [SaleController::class, 'storeDocument'])
        ->middleware('permission:documents.create')
        ->name('sales.documents.store');

    // Cargos comerciales
    Route::get('/cargos', [CommercialChargeController::class, 'index'])
        ->middleware('permission:charges.view')
        ->name('charges.index');
    Route::middleware('permission:charges.create')->group(function () {
        Route::get('/cargos/crear', [CommercialChargeController::class, 'create'])->name('charges.create');
        Route::post('/cargos', [CommercialChargeController::class, 'store'])->name('charges.store');
    });
    Route::get('/cargos/{charge}', [CommercialChargeController::class, 'show'])
        ->middleware('permission:charges.view')
        ->name('charges.show');
    Route::post('/cargos/{charge}/anular', [CommercialChargeController::class, 'void'])
        ->middleware('permission:charges.void')
        ->name('charges.void');
    Route::post('/cargos/{charge}/comprobante', [CommercialChargeController::class, 'storeVoucher'])
        ->middleware('permission:documents.create')
        ->name('charges.vouchers.store');

    // Cobros
    Route::get('/cobros', [ReceiptController::class, 'index'])
        ->middleware('permission:receipts.view')
        ->name('receipts.index');
    Route::middleware('permission:receipts.create')->group(function () {
        Route::get('/cobros/crear', [ReceiptController::class, 'create'])->name('receipts.create');
        Route::post('/cobros', [ReceiptController::class, 'store'])->name('receipts.store');
    });
    Route::get('/cobros/{receipt}', [ReceiptController::class, 'show'])
        ->middleware('permission:receipts.view')
        ->name('receipts.show');
    Route::post('/cobros/{receipt}/anular', [ReceiptController::class, 'void'])
        ->middleware('permission:receipts.void')
        ->name('receipts.void');
    Route::post('/cobros/{receipt}/comprobante', [ReceiptController::class, 'storeVoucher'])
        ->middleware('permission:documents.create')
        ->name('receipts.vouchers.store');

    Route::get('/operaciones-sin-comprobante', [DocumentalControlController::class, 'index'])
        ->middleware('permission:charges.view')
        ->name('documental.pending');

    Route::get('/categorias', [CategoryController::class, 'index'])
        ->middleware('permission:categories.view')
        ->name('categories.index');
    Route::get('/categorias/{category}', [CategoryController::class, 'show'])
        ->middleware('permission:categories.view')
        ->name('categories.show');
    Route::get('/subcategorias/{subcategory}', [CategoryController::class, 'showSubcategory'])
        ->middleware('permission:categories.view')
        ->name('subcategories.show');
    Route::post('/categorias', [CategoryController::class, 'store'])
        ->middleware('permission:categories.create')
        ->name('categories.store');
    Route::post('/subcategorias', [CategoryController::class, 'storeSubcategory'])
        ->middleware('permission:categories.create')
        ->name('subcategories.store');
    Route::middleware('permission:categories.edit')->group(function () {
        Route::post('/categorias/{category}/renombrar', [CategoryController::class, 'rename'])->name('categories.rename');
        Route::post('/subcategorias/{subcategory}/renombrar', [CategoryController::class, 'renameSubcategory'])->name('subcategories.rename');
        Route::post('/categorias/fusion/preview', [CategoryController::class, 'previewMerge'])->name('categories.merge.preview');
        Route::post('/categorias/fusion/aplicar', [CategoryController::class, 'merge'])->name('categories.merge.apply');
        Route::post('/categorias/{category}/convertir-subcategoria', [CategoryController::class, 'convertToSubcategory'])->name('categories.convert-sub');
    });

    Route::middleware('permission:categories.view')->group(function () {
        Route::get('/plan-de-cuentas', [ChartAccountController::class, 'index'])->name('chart-accounts.index');
        Route::get('/plan-de-cuentas/buscar', [ChartAccountController::class, 'search'])->name('chart-accounts.search');
        Route::get('/plan-de-cuentas/sugerir-codigo', [ChartAccountController::class, 'suggestCode'])->name('chart-accounts.suggest-code');
        Route::get('/plan-de-cuentas/sugerir-ambito', [ChartAccountController::class, 'suggestScope'])->name('chart-accounts.suggest-scope');
        Route::get('/plan-de-cuentas/mapa', [ChartAccountController::class, 'map'])->name('chart-accounts.map');
        Route::get('/plan-de-cuentas/configuracion-avanzada', [ChartAccountController::class, 'advanced'])->name('chart-accounts.advanced');
        Route::get('/plan-de-cuentas/clasificaciones-recordadas', [RememberedClassificationController::class, 'index'])->name('remembered-classifications.index');
        Route::get('/plan-de-cuentas/clasificaciones-recordadas/lookup', [RememberedClassificationController::class, 'lookup'])->name('remembered-classifications.lookup');
        Route::get('/plan-de-cuentas/mapeo', [ChartAccountController::class, 'mappingTool'])->name('chart-accounts.mapping');
        Route::get('/plan-de-cuentas/movimientos-sin-clasificar', [UnclassifiedMovementController::class, 'index'])->name('chart-accounts.unclassified');
        Route::get('/plan-de-cuentas/clasificar-movimientos', [UnclassifiedMovementController::class, 'index'])->name('chart-accounts.classify');
        Route::get('/plan-de-cuentas/analisis-semantico', [UnclassifiedMovementController::class, 'semanticsReport'])->name('chart-accounts.semantics');
        Route::get('/plan-de-cuentas/export-ambiguos', [UnclassifiedMovementController::class, 'exportAmbiguous'])->name('chart-accounts.export-ambiguous');
        Route::get('/plan-de-cuentas/reglas-imputacion', [ImputationRuleController::class, 'index'])->name('imputation-rules.index');
    });
    Route::middleware('permission:categories.create')->group(function () {
        Route::get('/plan-de-cuentas/crear', [ChartAccountController::class, 'create'])->name('chart-accounts.create');
        Route::post('/plan-de-cuentas', [ChartAccountController::class, 'store'])->name('chart-accounts.store');
    });
    Route::middleware('permission:categories.edit')->group(function () {
        Route::post('/plan-de-cuentas/mapeo', [ChartAccountController::class, 'saveMapping'])->name('chart-accounts.mapping.save');
        Route::post('/plan-de-cuentas/mapeo/defaults', [ChartAccountController::class, 'saveTypeDefaults'])->name('chart-accounts.mapping.type-defaults');
        Route::post('/plan-de-cuentas/mapeo/preview', [ChartAccountController::class, 'previewApply'])->name('chart-accounts.mapping.preview');
        Route::post('/plan-de-cuentas/mapeo/aplicar', [ChartAccountController::class, 'applyMapping'])->name('chart-accounts.mapping.apply');
        Route::get('/plan-de-cuentas/{chart_account}/editar', [ChartAccountController::class, 'edit'])->name('chart-accounts.edit');
        Route::put('/plan-de-cuentas/{chart_account}', [ChartAccountController::class, 'update'])->name('chart-accounts.update');
        Route::delete('/plan-de-cuentas/{chart_account}', [ChartAccountController::class, 'destroy'])->name('chart-accounts.destroy');

        Route::post('/plan-de-cuentas/movimientos-sin-clasificar/bulk/preview', [UnclassifiedMovementController::class, 'previewBulk'])->name('chart-accounts.unclassified.bulk.preview');
        Route::post('/plan-de-cuentas/movimientos-sin-clasificar/bulk/aplicar', [UnclassifiedMovementController::class, 'applyBulk'])->name('chart-accounts.unclassified.bulk.apply');
        Route::post('/plan-de-cuentas/movimientos-sin-clasificar/patron/preview', [UnclassifiedMovementController::class, 'previewPattern'])->name('chart-accounts.unclassified.pattern.preview');
        Route::post('/plan-de-cuentas/movimientos-sin-clasificar/patron/aplicar', [UnclassifiedMovementController::class, 'applyPattern'])->name('chart-accounts.unclassified.pattern.apply');
        Route::post('/plan-de-cuentas/movimientos-sin-clasificar/{movement}', [UnclassifiedMovementController::class, 'classify'])->name('chart-accounts.unclassified.classify');
        Route::post('/plan-de-cuentas/normalizar-super', [UnclassifiedMovementController::class, 'normalizeSuper'])->name('chart-accounts.normalize-super');
        Route::post('/plan-de-cuentas/ingresos-profesionales', [UnclassifiedMovementController::class, 'ensureProfessionalIncomes'])->name('chart-accounts.professional-incomes');
        Route::post('/plan-de-cuentas/dry-run-estructural', [UnclassifiedMovementController::class, 'dryRunStructural'])->name('chart-accounts.dry-run');
        Route::post('/plan-de-cuentas/taxonomia-canonica', [UnclassifiedMovementController::class, 'ensureTaxonomy'])->name('chart-accounts.ensure-taxonomy');

        Route::post('/plan-de-cuentas/reglas-imputacion', [ImputationRuleController::class, 'store'])->name('imputation-rules.store');
        Route::put('/plan-de-cuentas/reglas-imputacion/{imputation_rule}', [ImputationRuleController::class, 'update'])->name('imputation-rules.update');
        Route::delete('/plan-de-cuentas/reglas-imputacion/{imputation_rule}', [ImputationRuleController::class, 'destroy'])->name('imputation-rules.destroy');
        Route::post('/plan-de-cuentas/reglas-imputacion/{imputation_rule}/preview', [ImputationRuleController::class, 'preview'])->name('imputation-rules.preview');
        Route::post('/plan-de-cuentas/reglas-imputacion/{imputation_rule}/aplicar', [ImputationRuleController::class, 'apply'])->name('imputation-rules.apply');

        Route::post('/plan-de-cuentas/clasificaciones-recordadas', [RememberedClassificationController::class, 'store'])->name('remembered-classifications.store');
        Route::put('/plan-de-cuentas/clasificaciones-recordadas/{remembered_classification}', [RememberedClassificationController::class, 'update'])->name('remembered-classifications.update');
        Route::post('/plan-de-cuentas/clasificaciones-recordadas/{remembered_classification}/desactivar', [RememberedClassificationController::class, 'deactivate'])->name('remembered-classifications.deactivate');
        Route::delete('/plan-de-cuentas/clasificaciones-recordadas/{remembered_classification}', [RememberedClassificationController::class, 'destroy'])->name('remembered-classifications.destroy');
        Route::post('/plan-de-cuentas/clasificaciones-recordadas/olvidar', [RememberedClassificationController::class, 'forget'])->name('remembered-classifications.forget');
    });

    Route::middleware('permission:users.create')->group(function () {
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
    });

    Route::middleware('permission:users.view')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
    });

    Route::middleware('permission:users.edit')->group(function () {
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/enviar-reset', [UserController::class, 'sendPasswordReset'])->name('users.send-reset');
        Route::post('/users/{user}/enviar-verificacion', [UserController::class, 'sendVerification'])->name('users.send-verification');
    });

    Route::delete('/users/{user}', [UserController::class, 'destroy'])
        ->middleware('permission:users.delete')
        ->name('users.destroy');

    Route::get('/permissions', [RolePermissionController::class, 'index'])
        ->middleware('permission:permissions.view')
        ->name('permissions.index');

    Route::put('/permissions/roles/{role}', [RolePermissionController::class, 'update'])
        ->middleware('permission:permissions.edit')
        ->name('permissions.update');

    Route::get('/settings', [SettingController::class, 'edit'])
        ->middleware('permission:settings.view')
        ->name('settings.edit');

    Route::put('/settings', [SettingController::class, 'update'])
        ->middleware('permission:settings.edit')
        ->name('settings.update');

    Route::get('/audit', [AuditLogController::class, 'index'])
        ->middleware('permission:audit.view')
        ->name('audit.index');
});

require __DIR__.'/auth.php';
