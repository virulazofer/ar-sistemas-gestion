<?php

namespace App\Http\Controllers\Subscriptions;

use App\Enums\SubscriptionPeriodicity;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Document;
use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function index(): View
    {
        $subscriptions = Subscription::query()->with('client')->latest('id')->paginate(25);

        return view('subscriptions.index', compact('subscriptions'));
    }

    public function create(): View
    {
        return view('subscriptions.create', [
            'clients' => Client::query()->where('status', 'active')->orderBy('name')->get(),
            'periodicities' => SubscriptionPeriodicity::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'periodicity' => ['required', Rule::in(array_column(SubscriptionPeriodicity::cases(), 'value'))],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency_code' => ['required', Rule::in(['ARS', 'USD'])],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
            'billing_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'terms' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $sub = $this->subscriptions->create($data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('subscriptions.show', $sub)->with('status', 'Abono creado.');
    }

    public function show(Subscription $subscription): View
    {
        $subscription->load(['client', 'periods.ledgerEntry', 'documents']);

        return view('subscriptions.show', compact('subscription'));
    }

    public function generate(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'period_key' => ['nullable', 'string', 'max:32'],
            'as_of' => ['nullable', 'date'],
        ]);

        try {
            $asOf = ! empty($data['as_of']) ? Carbon::parse($data['as_of']) : null;
            $period = $this->subscriptions->generatePeriod(
                $subscription,
                $asOf,
                $data['period_key'] ?? null,
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['period_key' => $e->getMessage()]);
        }

        if (! $period) {
            return back()->with('status', 'Sin generación (abono inactivo o finalizado).');
        }

        return back()->with('status', 'Período '.$period->period_key.' — cargo CC '.$period->currency_code.' '.$period->amount);
    }

    public function generateDue(Request $request): RedirectResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date']]);
        $count = $this->subscriptions->generateDue(
            ! empty($data['as_of']) ? Carbon::parse($data['as_of']) : now()
        );

        return back()->with('status', "Generación masiva: {$count} cargo(s).");
    }

    public function changeStatus(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_column(SubscriptionStatus::cases(), 'value'))],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $this->subscriptions->changeStatus($subscription, SubscriptionStatus::from($data['status']), $data['reason'] ?? null);

        return back()->with('status', 'Estado actualizado.');
    }

    public function storeDocument(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $file = $data['file'];
        $path = $file->store('documents/subscriptions/'.$subscription->id, 'local');
        Document::query()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'documentable_type' => Subscription::class,
            'documentable_id' => $subscription->id,
            'uploaded_by' => $request->user()->id,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('status', 'Documento adjuntado.');
    }
}
