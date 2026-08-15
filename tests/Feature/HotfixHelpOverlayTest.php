<?php

test('ayuda contextual inicia cerrada y no persiste open en localStorage', function () {
    $admin = makeAdmin();

    $pages = [
        route('dashboard'),
        route('dashboard.operations'),
        route('dashboard.management'),
        route('movements.index'),
        route('clients.index'),
        route('products.index'),
        route('exchange-rates.index'),
    ];

    foreach ($pages as $url) {
        $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

        if (! str_contains($html, '? Ayuda')) {
            continue;
        }

        expect($html)->toContain('x-data="pageHelp"')
            ->and($html)->toContain('x-teleport="body"')
            ->and($html)->toContain('style="display: none;"')
            ->and($html)->not->toMatch('/pageHelp[\s\S]{0,200}localStorage/')
            ->and($html)->not->toMatch('/pageHelp[^"]*open:\s*true/')
            ->and($html)->toContain('? Ayuda');

        // Overlay de ayuda no debe estar como div suelto sin template (regresión velo gris)
        expect($html)->not->toMatch('/\? Ayuda<\/button>\s*<div[^>]*fixed inset-0[^>]*bg-black\/40/s');
    }
});

test('apariencia inicia cerrada y skin no implica open', function () {
    $admin = makeAdmin();
    $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->toContain('appearancePopover(')
        ->and($html)->toContain('Apariencia')
        ->and($html)->toContain('style="display: none;"')
        ->and($html)->toContain("x-data='appearancePopover(")
        ->and($html)->toContain('[x-cloak] { display: none !important; }');
});

test('drawer overlay inicia oculto en markup', function () {
    $admin = makeAdmin();
    $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->toContain('ar-drawer-overlay')
        ->and($html)->toContain('drawer: false')
        ->and($html)->toMatch('/ar-drawer-overlay[^>]*style="display: none;"/');
});

test('service worker cache version bump recovery help overlay', function () {
    $sw = file_get_contents(public_path('sw.js'));
    expect($sw)->toContain('ar-static-v12a-2-help-overlay')
        ->and($sw)->toContain('skipWaiting')
        ->and($sw)->toContain('clients.claim');
});

test('app js registra pageHelp antes de PWA', function () {
    $js = file_get_contents(resource_path('js/app.js'));
    expect($js)->toContain("Alpine.data('pageHelp'")
        ->and($js)->toContain('Alpine.start()')
        ->and($js)->toContain('registerPwa()');
    expect(strpos($js, 'Alpine.start()'))->toBeLessThan(strpos($js, 'registerPwa()'));
});
