<?php

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Controller;
use App\Services\Search\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GlobalSearchController extends Controller
{
    public function __construct(private readonly GlobalSearchService $search) {}

    public function __invoke(Request $request): View|JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $wantsJson = $request->wantsJson() || $request->ajax();
        $limit = (int) $request->integer('limit', $wantsJson ? 5 : 8);
        $results = $this->search->search($q, $limit, $request->user());

        if ($wantsJson) {
            return response()->json([
                'q' => $q,
                'groups' => $results,
            ]);
        }

        return view('search.index', compact('q', 'results'));
    }
}
