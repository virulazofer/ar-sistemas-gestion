<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $actions = config('permissions.actions');
        $areas = config('permissions.areas');
        $areaActions = config('permissions.area_actions');

        foreach ($areas as $area => $label) {
            $allowed = $areaActions[$area] ?? array_keys($actions);

            foreach ($allowed as $action) {
                Permission::findOrCreate("{$area}.{$action}", 'web');
            }
        }

        $admin = Role::findOrCreate('Administrador', 'web');
        $operador = Role::findOrCreate('Operador', 'web');
        $consulta = Role::findOrCreate('Consulta', 'web');

        $admin->syncPermissions(Permission::all());

        $operadorNames = [
            'dashboard.view',
            'audit.view',
            // 11F grilla: Operador crea/consulta; edición/anulación solo Administrador (policy + perms).
            'movements.view', 'movements.create', 'movements.export',
            'accounts.view', 'accounts.create', 'accounts.edit',
            'exchange_rates.view', 'exchange_rates.create',
            'categories.view', 'categories.create', 'categories.edit',
            // clients.regularize: solo Administrador (11F-7)
            'clients.view', 'clients.create', 'clients.edit', 'clients.void', 'clients.export',
            'documents.view', 'documents.create', 'documents.edit', 'documents.delete',
            'suppliers.view', 'suppliers.create', 'suppliers.edit', 'suppliers.void', 'suppliers.export',
            'products.view', 'products.create', 'products.edit', 'products.void', 'products.export',
            'stock.view', 'stock.create', 'stock.edit', 'stock.void', 'stock.adjust', 'stock.transfer', 'stock.consume', 'stock.rebuild', 'stock.export',
            'purchases.view', 'purchases.create', 'purchases.edit', 'purchases.void', 'purchases.export',
            'equipment.view', 'equipment.create', 'equipment.edit', 'equipment.void', 'equipment.assemble', 'equipment.disassemble', 'equipment.change_component', 'equipment.change_status', 'equipment.export',
            'work_orders.view', 'work_orders.create', 'work_orders.edit', 'work_orders.void', 'work_orders.close', 'work_orders.cancel', 'work_orders.consume_stock', 'work_orders.charge', 'work_orders.export',
            'subscriptions.view', 'subscriptions.create', 'subscriptions.edit', 'subscriptions.void', 'subscriptions.generate', 'subscriptions.cancel', 'subscriptions.export',
            'quotations.view', 'quotations.create', 'quotations.edit', 'quotations.send', 'quotations.accept', 'quotations.convert', 'quotations.cancel', 'quotations.export',
            'sales.view', 'sales.create', 'sales.edit', 'sales.confirm', 'sales.void', 'sales.export',
            'charges.view', 'charges.create', 'charges.void', 'charges.export',
            'receipts.view', 'receipts.create', 'receipts.apply', 'receipts.void', 'receipts.export',
            'events.view', 'events.create', 'events.edit', 'events.void', 'events.export',
            'invoices.view', 'invoices.create', 'invoices.edit', 'invoices.void', 'invoices.export',
            'reports.view', 'reports.export', 'reports.finance', 'reports.clients', 'reports.suppliers', 'reports.stock', 'reports.sales', 'reports.profitability',
            'imports.view', 'imports.execute',
            'exports.execute',
        ];
        $operador->syncPermissions($operadorNames);

        $consultaNames = [
            'dashboard.view',
            'movements.view', 'movements.export',
            'accounts.view',
            'exchange_rates.view',
            'categories.view',
            'clients.view', 'clients.export',
            'suppliers.view', 'suppliers.export',
            'products.view', 'products.export',
            'stock.view', 'stock.export',
            'purchases.view', 'purchases.export',
            'equipment.view', 'equipment.export',
            'work_orders.view', 'work_orders.export',
            'subscriptions.view', 'subscriptions.export',
            'quotations.view', 'quotations.export',
            'sales.view', 'sales.export',
            'charges.view', 'charges.export',
            'receipts.view', 'receipts.export',
            'events.view', 'events.export',
            'invoices.view', 'invoices.export',
            'documents.view',
            'reports.view', 'reports.export', 'reports.finance', 'reports.clients', 'reports.suppliers', 'reports.stock', 'reports.sales', 'reports.profitability',
            'exports.execute',
        ];
        $consulta->syncPermissions($consultaNames);
    }
}
