<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Services\Clients\ClientCurrentAccountRankingService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClientCurrentAccountController extends Controller
{
    public function __construct(
        private readonly ClientCurrentAccountRankingService $ranking,
    ) {}

    public function index(Request $request): View
    {
        $data = $this->ranking->build([
            'filter' => (string) $request->get('filter', ClientCurrentAccountRankingService::FILTER_OWING),
            'q' => (string) $request->get('q', ''),
        ]);

        return view('clients.current-accounts.index', ['data' => $data]);
    }
}
