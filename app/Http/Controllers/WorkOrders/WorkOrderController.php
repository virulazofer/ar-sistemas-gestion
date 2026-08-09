<?php

namespace App\Http\Controllers\WorkOrders;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderType;
use App\Services\WorkOrders\WorkOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkOrderController extends Controller
{
    public function __construct(
        private readonly WorkOrderService $workOrders,
    ) {}

    public function index(): View
    {
        $orders = WorkOrder::query()->with(['client', 'type', 'assignee'])->latest('id')->paginate(25);

        return view('work-orders.index', compact('orders'));
    }

    public function create(): View
    {
        return view('work-orders.create', [
            'clients' => Client::query()->where('status', 'active')->orderBy('name')->get(),
            'types' => WorkOrderType::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'users' => User::query()->orderBy('name')->get(),
            'locations' => InventoryLocation::query()->where('is_active', true)->orderBy('name')->get(),
            'equipments' => Equipment::query()->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'work_order_type_id' => ['required', 'exists:work_order_types,id'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assigned_user_id' => ['nullable', 'exists:users,id'],
            'inventory_location_id' => ['nullable', 'exists:inventory_locations,id'],
            'currency_code' => ['nullable', Rule::in(['ARS', 'USD'])],
            'equipment_id' => ['nullable', 'exists:equipments,id'],
            'external_manufacturer' => ['nullable', 'string', 'max:120'],
            'external_model' => ['nullable', 'string', 'max:120'],
            'external_serial' => ['nullable', 'string', 'max:120'],
            'external_label' => ['nullable', 'string', 'max:120'],
        ]);

        $assets = [];
        if (! empty($data['equipment_id'])) {
            $assets[] = ['equipment_id' => $data['equipment_id']];
        }
        if (! empty($data['external_label']) || ! empty($data['external_serial']) || ! empty($data['external_model'])) {
            $assets[] = [
                'external_manufacturer' => $data['external_manufacturer'] ?? null,
                'external_model' => $data['external_model'] ?? null,
                'external_serial' => $data['external_serial'] ?? null,
                'external_label' => $data['external_label'] ?? null,
            ];
        }
        $data['assets'] = $assets;

        try {
            $wo = $this->workOrders->create($data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['title' => $e->getMessage()]);
        }

        return redirect()->route('work-orders.show', $wo)->with('status', 'OT '.$wo->number.' creada.');
    }

    public function show(WorkOrder $workOrder): View
    {
        $workOrder->load([
            'client', 'type', 'assignee', 'location', 'user', 'ledgerEntry',
            'assets.equipment.components.product', 'assets.equipment.components.serial',
            'diagnoses.user', 'tasks', 'materials.product', 'materials.lot', 'materials.serial', 'documents',
        ]);

        return view('work-orders.show', [
            'workOrder' => $workOrder,
            'products' => Product::query()->where('type', 'physical')->where('status', 'active')->orderBy('name')->get(),
            'users' => User::query()->orderBy('name')->get(),
        ]);
    }

    public function storeDiagnosis(Request $request, WorkOrder $workOrder): RedirectResponse
    {
        $data = $request->validate([
            'client_reported_issue' => ['nullable', 'string'],
            'technical_diagnosis' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
        ]);
        try {
            $this->workOrders->addDiagnosis($workOrder, $data);
        } catch (\Throwable $e) {
            return back()->withErrors(['technical_diagnosis' => $e->getMessage()]);
        }

        return back()->with('status', 'Diagnóstico registrado.');
    }

    public function storeTask(Request $request, WorkOrder $workOrder): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'hours' => ['nullable', 'numeric', 'min:0'],
            'cost_amount' => ['nullable', 'numeric', 'min:0'],
            'price_amount' => ['required', 'numeric', 'min:0'],
            'currency_code' => ['required', Rule::in(['ARS', 'USD'])],
            'assigned_user_id' => ['nullable', 'exists:users,id'],
        ]);
        try {
            $this->workOrders->addTask($workOrder, $data);
        } catch (\Throwable $e) {
            return back()->withErrors(['description' => $e->getMessage()]);
        }

        return back()->with('status', 'Tarea agregada.');
    }

    public function storeMaterial(Request $request, WorkOrder $workOrder): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'price_unit' => ['required', 'numeric', 'min:0'],
            'currency_code' => ['required', Rule::in(['ARS', 'USD'])],
            'inventory_serial_id' => ['nullable', 'exists:inventory_serials,id'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        try {
            $this->workOrders->addMaterial($workOrder, $data);
        } catch (\Throwable $e) {
            return back()->withErrors(['product_id' => $e->getMessage()]);
        }

        return back()->with('status', 'Material agregado (pendiente de consumo al cerrar).');
    }

    public function close(Request $request, WorkOrder $workOrder): RedirectResponse
    {
        $data = $request->validate([
            'solution' => ['nullable', 'string'],
        ]);
        try {
            $this->workOrders->close($workOrder, $data);
        } catch (\Throwable $e) {
            return back()->withErrors(['solution' => $e->getMessage()]);
        }

        return back()->with('status', 'OT cerrada: stock consumido + cargo CC (sin movimiento bancario).');
    }

    public function cancel(Request $request, WorkOrder $workOrder): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->workOrders->cancel($workOrder, $data['reason']);
        } catch (\Throwable $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'OT cancelada.');
    }

    public function storeDocument(Request $request, WorkOrder $workOrder): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $file = $data['file'];
        $path = $file->store('documents/work-orders/'.$workOrder->id, 'local');
        Document::query()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'documentable_type' => WorkOrder::class,
            'documentable_id' => $workOrder->id,
            'uploaded_by' => $request->user()->id,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('status', 'Documento adjuntado.');
    }
}
