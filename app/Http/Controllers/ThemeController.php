<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ThemeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['required', Rule::in([User::THEME_LIGHT, User::THEME_DARK])],
        ]);

        /** @var User $user */
        $user = $request->user();
        $old = ['theme' => $user->theme];
        $user->update(['theme' => $data['theme']]);

        $this->audit->log('theme_changed', $user, $old, ['theme' => $user->theme], 'Tema actualizado');

        return back()->with('status', 'Tema actualizado.');
    }
}
