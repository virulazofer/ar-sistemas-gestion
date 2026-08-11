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

        if ($wantsJson) {
            $limit = (int) $request->integer('limit', 10);
            $results = $this->search->search($q, $limit, $request->user());
            $meta = $results['meta'] ?? ['has_more' => [], 'totals' => [], 'total' => 0];
            unset($results['meta']);

            return response()->json([
                'q' => $q,
                'groups' => $results,
                'meta' => $meta,
            ]);
        }

        $type = (string) $request->query('type', 'all');
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = max(5, min(50, (int) $request->integer('per_page', 25)));

        $pageResult = $this->search->searchPage($q, $type, $page, $perPage, $request->user());

        return view('search.index', [
            'q' => $pageResult['q'],
            'type' => $pageResult['type'],
            'totals' => $pageResult['totals'],
            'total' => $pageResult['total'],
            'items' => $pageResult['items'],
            'groups' => $pageResult['groups'],
            'paginator' => $pageResult['paginator'],
            'groupLabels' => GlobalSearchService::GROUP_LABELS,
            'groupOrder' => GlobalSearchService::GROUP_ORDER,
        ]);
    }
}
