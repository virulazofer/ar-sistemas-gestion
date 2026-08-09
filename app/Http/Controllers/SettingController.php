<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(): View
    {
        $settings = Setting::query()->orderBy('group')->orderBy('key')->get()->groupBy('group');

        return view('settings.edit', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable', 'string', 'max:2000'],
        ]);

        $old = Setting::query()->pluck('value', 'key')->all();

        foreach ($data['settings'] as $key => $value) {
            $setting = Setting::query()->where('key', $key)->first();
            if (! $setting) {
                continue;
            }

            $setting->update(['value' => $value]);
            Cache::forget("setting.{$key}");
        }

        $new = Setting::query()->pluck('value', 'key')->all();
        $this->audit->log('settings_updated', null, $old, $new, 'Configuración actualizada');

        return redirect()->route('settings.edit')->with('status', 'Configuración guardada.');
    }
}
