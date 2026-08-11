<?php

namespace App\Http\Controllers\Commercial;

use App\Enums\DocumentalStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CommercialCharge;
use App\Models\Receipt;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DocumentalControlController extends Controller
{
    public function index(Request $request): View
    {
        $clientId = (int) $request->get('client_id', 0);
        $from = $request->get('from');
        $to = $request->get('to');
        $opType = (string) $request->get('op_type', 'charges');
        $docStatus = (string) $request->get('documental_status', '');

        $attention = [
            DocumentalStatus::None->value,
            DocumentalStatus::Pending->value,
            DocumentalStatus::Review->value,
        ];

        $rows = collect();
        if ($opType === 'receipts') {
            $q = Receipt::query()->with('client');
            if ($clientId > 0) {
                $q->where('client_id', $clientId);
            }
            if ($from) {
                $q->whereDate('received_on', '>=', $from);
            }
            if ($to) {
                $q->whereDate('received_on', '<=', $to);
            }
            $q->whereIn('documental_status', $docStatus !== '' ? [$docStatus] : $attention);
            $rows = $q->latest('received_on')->limit(200)->get();
        } elseif ($opType === 'sales') {
            $q = Sale::query()->with('client')->where('status', 'confirmed');
            if ($clientId > 0) {
                $q->where('client_id', $clientId);
            }
            if ($from) {
                $q->whereDate('sold_on', '>=', $from);
            }
            if ($to) {
                $q->whereDate('sold_on', '<=', $to);
            }
            $q->whereIn('documental_status', $docStatus !== '' ? [$docStatus] : $attention);
            $rows = $q->latest('sold_on')->limit(200)->get();
        } else {
            $q = CommercialCharge::query()->with('client')->where('status', '!=', 'voided');
            if ($clientId > 0) {
                $q->where('client_id', $clientId);
            }
            if ($from) {
                $q->whereDate('charged_on', '>=', $from);
            }
            if ($to) {
                $q->whereDate('charged_on', '<=', $to);
            }
            $q->whereIn('documental_status', $docStatus !== '' ? [$docStatus] : $attention);
            $rows = $q->latest('charged_on')->limit(200)->get();
            $opType = 'charges';
        }

        $clients = Client::query()->active()->orderBy('code')->orderBy('name')->get();

        return view('commercial.documental.index', [
            'rows' => $rows,
            'clients' => $clients,
            'clientId' => $clientId,
            'from' => $from,
            'to' => $to,
            'opType' => $opType,
            'docStatus' => $docStatus,
            'documentalStatuses' => DocumentalStatus::cases(),
        ]);
    }
}
