<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(): View
    {
        $logs = AuditLog::query()
            ->with('user')
            ->latest()
            ->paginate(25);

        return view('audit.index', compact('logs'));
    }
}
