<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Appearance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ThemeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'theme' => ['required', Rule::in(Appearance::modes())],
            'palette' => ['nullable', Rule::in(Appearance::palettes())],
        ]);

        /** @var User $user */
        $user = $request->user();
        $old = [
            'theme' => $user->theme,
            'palette' => $user->palette ?? Appearance::PALETTE_ACTUAL,
        ];

        $payload = ['theme' => Appearance::normalizeMode($data['theme'])];
        if (array_key_exists('palette', $data) && $data['palette'] !== null) {
            $payload['palette'] = Appearance::normalizePalette($data['palette']);
        }

        $user->update($payload);

        $this->audit->log(
            'theme_changed',
            $user,
            $old,
            [
                'theme' => $user->theme,
                'palette' => $user->appearancePalette(),
            ],
            'Apariencia actualizada'
        );

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'status' => 'ok',
                'theme' => $user->theme,
                'palette' => $user->appearancePalette(),
            ]);
        }

        return back()->with('status', 'Apariencia actualizada.');
    }
}
