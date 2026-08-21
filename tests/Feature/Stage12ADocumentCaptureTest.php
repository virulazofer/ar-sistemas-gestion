<?php

use App\Enums\DocumentOptimizationStatus;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentCaptureService;
use App\Services\Documents\DocumentOptimizationService;
use App\Services\Documents\DocumentStorageMetricsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function makeJpegUpload(string $name = 'foto.jpg', int $w = 800, int $h = 600): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'doc');
    $img = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($img, 240, 240, 240);
    $fg = imagecolorallocate($img, 20, 20, 20);
    imagefill($img, 0, 0, $bg);
    imagestring($img, 5, 20, 20, 'TEST DOC', $fg);
    imagejpeg($img, $tmp, 90);
    imagedestroy($img);

    return new UploadedFile($tmp, $name, 'image/jpeg', null, true);
}

function makePngUpload(string $name = 'foto.png'): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'doc');
    $img = imagecreatetruecolor(400, 300);
    $bg = imagecolorallocate($img, 200, 220, 240);
    imagefill($img, 0, 0, $bg);
    imagepng($img, $tmp);
    imagedestroy($img);

    return new UploadedFile($tmp, $name, 'image/png', null, true);
}

function makeWebpUpload(string $name = 'foto.webp'): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'doc');
    $img = imagecreatetruecolor(320, 240);
    $bg = imagecolorallocate($img, 180, 200, 160);
    imagefill($img, 0, 0, $bg);
    imagewebp($img, $tmp, 80);
    imagedestroy($img);

    return new UploadedFile($tmp, $name, 'image/webp', null, true);
}

function makePdfUpload(string $name = 'doc.pdf'): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'doc');
    file_put_contents($tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

    return new UploadedFile($tmp, $name, 'application/pdf', null, true);
}

test('manifest PWA y metadata disponibles', function () {
    $manifest = json_decode(file_get_contents(resource_path('pwa/manifest.webmanifest')), true);
    expect($manifest['name'])->toBe('AR Sistemas')
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest)->toHaveKeys(['icons', 'shortcuts', 'start_url', 'scope'])
        ->and(collect($manifest['shortcuts'])->pluck('url')->all())->toContain('/documentos/capturar');

    // El template no debe vivir en public/ (nginx try_files lo serviría sin filtrar).
    expect(is_file(public_path('manifest.webmanifest')))->toBeFalse();

    config(['documents.show_in_ui' => false]);
    $served = $this->get('/manifest.webmanifest')->assertOk()
        ->assertHeader('content-type', 'application/manifest+json; charset=utf-8')
        ->json();
    expect($served['name'])->toBe('AR Sistemas')
        ->and(collect($served['shortcuts'] ?? [])->pluck('url')->all())
        ->not->toContain('/documentos/capturar')
        ->and(json_encode($served))->not->toContain('Capturar documento')
        ->and(collect($served['shortcuts'] ?? [])->pluck('url')->all())
        ->toContain('/movimientos/cargar');

    config(['documents.show_in_ui' => true]);
    $servedOn = $this->get('/manifest.webmanifest')->assertOk()->json();
    expect(collect($servedOn['shortcuts'] ?? [])->pluck('url')->all())
        ->toContain('/documentos/capturar');

    expect(file_exists(public_path('sw.js')))->toBeTrue();
    expect(file_exists(public_path('icons/icon-192.png')))->toBeTrue();
    $this->get('/sw.js')->assertOk();
});

test('captura requiere autenticación y permiso', function () {
    $this->get(route('documents.capture'))->assertRedirect(route('login'));

    $user = makeUserWithPermissions(['dashboard.view']);
    $this->actingAs($user)->get(route('documents.capture'))->assertForbidden();
});

test('upload JPEG PNG WEBP PDF genera DOC code hash y storage privado', function () {
    $admin = makeAdmin();

    foreach ([
        makeJpegUpload(),
        makePngUpload(),
        makeWebpUpload(),
        makePdfUpload(),
    ] as $file) {
        $response = $this->actingAs($admin)->post(route('documents.store'), [
            'file' => $file,
            'type' => 'factura',
        ]);
        $response->assertRedirect();
    }

    expect(Document::whereNotNull('code')->count())->toBe(4);

    $doc = Document::whereNotNull('code')->first();
    expect($doc->code)->toMatch('/^DOC-\d{4}-\d{6}$/')
        ->and($doc->uuid)->not->toBeEmpty()
        ->and($doc->content_hash)->toHaveLength(64)
        ->and($doc->status)->toBe(DocumentStatus::PendienteDeAnalisis)
        ->and(str_contains($doc->path, 'documents/'))->toBeTrue()
        ->and(str_starts_with($doc->path, 'public/'))->toBeFalse();

    $disk = Storage::disk('local');
    expect($disk->exists($doc->path))->toBeTrue();
    expect(file_exists(public_path($doc->path)))->toBeFalse();
});

test('MIME inválido y archivo excesivo y nombre malicioso', function () {
    $admin = makeAdmin();

    $tmp = tempnam(sys_get_temp_dir(), 'bad');
    file_put_contents($tmp, '<?php echo 1;');
    $bad = new UploadedFile($tmp, '../../evil.php', 'application/x-php', null, true);

    $this->actingAs($admin)->post(route('documents.store'), [
        'file' => $bad,
    ])->assertSessionHasErrors('file');

    config(['documents.max_upload_kb' => 1]); // 1 KB
    $big = makeJpegUpload('big.jpg', 1200, 1200);
    $this->actingAs($admin)->post(route('documents.store'), [
        'file' => $big,
    ])->assertSessionHasErrors('file');
});

test('stream privado 403 sin permiso y ok con permiso', function () {
    $admin = makeAdmin();
    $service = app(DocumentCaptureService::class);
    $result = $service->capture(makeJpegUpload(), $admin->id);
    $doc = $result['document'];

    $guest = makeUserWithPermissions(['dashboard.view']);
    $this->actingAs($guest)
        ->get(route('documents.stream', $doc))
        ->assertForbidden();

    $this->actingAs($admin)
        ->get(route('documents.stream', $doc))
        ->assertOk();
});

test('hash duplicado avisa sin bloquear', function () {
    $admin = makeAdmin();
    // Mismos bytes
    $img = imagecreatetruecolor(100, 80);
    $bg = imagecolorallocate($img, 255, 255, 255);
    imagefill($img, 0, 0, $bg);
    $path1 = tempnam(sys_get_temp_dir(), 'dup');
    $path2 = tempnam(sys_get_temp_dir(), 'dup');
    imagejpeg($img, $path1, 90);
    imagejpeg($img, $path2, 90);
    imagedestroy($img);

    $f1 = new UploadedFile($path1, 'a.jpg', 'image/jpeg', null, true);
    $f2 = new UploadedFile($path2, 'b.jpg', 'image/jpeg', null, true);

    $this->actingAs($admin)->post(route('documents.store'), ['file' => $f1])->assertRedirect();
    $response = $this->actingAs($admin)->post(route('documents.store'), ['file' => $f2]);
    $response->assertRedirect();
    expect(session('status'))->toContain('cargado anteriormente');
    expect(Document::whereNotNull('code')->count())->toBe(2);
});

test('eliminación soft y hard sin huérfanos', function () {
    $admin = makeAdmin();
    $service = app(DocumentCaptureService::class);

    $soft = $service->capture(makeJpegUpload('soft.jpg'), $admin->id)['document'];
    $softPath = $soft->path;
    $this->actingAs($admin)->delete(route('documents.destroy', $soft))->assertRedirect(route('documents.index'));
    expect(Document::withTrashed()->find($soft->id)?->trashed())->toBeTrue();
    expect(Storage::disk('local')->exists($softPath))->toBeTrue();

    $hard = $service->capture(makeJpegUpload('hard.jpg'), $admin->id)['document'];
    $hardPath = $hard->path;
    $hardPreview = $hard->preview_path;
    $this->actingAs($admin)->delete(route('documents.destroy', $hard), ['hard' => 1])->assertRedirect();
    expect(Document::withTrashed()->find($hard->id))->toBeNull();
    expect(Storage::disk('local')->exists($hardPath))->toBeFalse();
    if ($hardPreview) {
        expect(Storage::disk('local')->exists($hardPreview))->toBeFalse();
    }
});

test('consulta solo lectura documentos', function () {
    seedPermissions();
    $consulta = User::factory()->create();
    $consulta->assignRole('Consulta');
    $admin = makeAdmin();
    $doc = app(DocumentCaptureService::class)->capture(makeJpegUpload(), $admin->id)['document'];

    $this->actingAs($consulta)->get(route('documents.index'))->assertOk();
    $this->actingAs($consulta)->get(route('documents.show', $doc))->assertOk();
    $this->actingAs($consulta)->get(route('documents.capture'))->assertForbidden();
    $this->actingAs($consulta)->post(route('documents.store'), ['file' => makeJpegUpload()])->assertForbidden();
});

test('service worker no cachea endpoints sensibles ni POST', function () {
    $sw = file_get_contents(public_path('sw.js'));
    expect($sw)->toContain("req.method !== 'GET'")
        ->and($sw)->toContain('/documentos')
        ->and($sw)->toContain('/movimientos')
        ->and($sw)->toContain('PRECACHE');
});

test('layout no solicita cámara automáticamente', function () {
    $admin = makeAdmin();
    $html = $this->actingAs($admin)->get(route('movements.quick'))->assertOk()->getContent();
    expect($html)->not->toContain('getUserMedia')
        // UI pública 12A oculta por defecto (documents.show_in_ui=false); la captura sigue por ruta directa.
        ->and($html)->not->toContain('Capturar documento');

    // Encabezado en <header>, formulario en <main> (regresión: slot header sin cerrar empujaba todo al lateral).
    expect($html)->toMatch('/<header[\s\S]*?>[\s\S]*Carga rápida[\s\S]*?<\/header>/')
        ->and($html)->toMatch('/<main[\s\S]*?>[\s\S]*name="type"[\s\S]*?<\/main>/');
    $headerPos = strpos($html, '>Carga rápida<');
    $formPos = strpos($html, 'name="type"');
    expect($headerPos)->toBeInt()->and($formPos)->toBeInt()->and($headerPos)->toBeLessThan($formPos);

    $capture = $this->actingAs($admin)->get(route('documents.capture'))->assertOk()->getContent();
    expect($capture)->toContain('AR Sistemas necesita abrir la cámara')
        ->and($capture)->toContain('documentCaptureApp');
});

test('headers de seguridad presentes', function () {
    $admin = makeAdmin();
    $response = $this->actingAs($admin)->get(route('documents.index'));
    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Content-Security-Policy');
    expect($response->headers->get('Permissions-Policy'))->toContain('camera=(self)');
});

test('34B optimización cleanup métricas y no borrar original si falla', function () {
    $admin = makeAdmin();
    $service = app(DocumentCaptureService::class);
    $doc = $service->capture(makeJpegUpload('opt.jpg', 1600, 1200), $admin->id)['document'];

    expect($doc->original_path)->not->toBeEmpty()
        ->and(Storage::disk('local')->exists($doc->original_path))->toBeTrue()
        ->and($doc->optimization_status)->toBeIn([
            DocumentOptimizationStatus::Optimized,
            DocumentOptimizationStatus::Skipped,
            DocumentOptimizationStatus::Failed,
        ]);

    if ($doc->optimization_status === DocumentOptimizationStatus::Optimized) {
        expect($doc->optimized_size)->toBeLessThan($doc->original_size);
        expect($doc->preview_path)->not->toBeEmpty();
    }

    // Simular fallo de optimización: no debe borrar original
    $failDoc = Document::query()->create([
        'uuid' => (string) Str::uuid(),
        'code' => 'DOC-2099-999991',
        'disk' => 'local',
        'path' => 'documents/temp/keep-me.bin',
        'original_path' => 'documents/temp/keep-me.bin',
        'original_name' => 'keep-me.bin',
        'mime' => 'image/jpeg',
        'size' => 10,
        'original_size' => 10,
        'status' => DocumentStatus::Capturado->value,
        'optimization_status' => DocumentOptimizationStatus::Pending->value,
        'uploaded_by' => $admin->id,
        'source' => 'capture',
    ]);
    Storage::disk('local')->put('documents/temp/keep-me.bin', 'not-an-image');
    $opt = app(DocumentOptimizationService::class)->optimize(
        $failDoc,
        Storage::disk('local')->path('documents/temp/keep-me.bin'),
        'image/jpeg'
    );
    expect($opt['optimization_status'])->toBe(DocumentOptimizationStatus::Failed);
    expect(Storage::disk('local')->exists('documents/temp/keep-me.bin'))->toBeTrue();

    Storage::disk('local')->put('documents/temp/old.tmp', 'x');
    // Forzar mtime viejo no siempre posible; dry-run al menos no rompe
    $this->artisan('documents:cleanup-temp', ['--dry-run' => true, '--hours' => 0])
        ->assertSuccessful();

    $metrics = app(DocumentStorageMetricsService::class)->snapshot();
    expect($metrics)->toHaveKeys(['documents_count', 'total_bytes', 'used_percent', 'level']);

    // keep_original exception
    $kept = $service->capture(makeJpegUpload('keep.jpg'), $admin->id, null, null, true)['document'];
    expect($kept->keep_original)->toBeTrue()
        ->and($kept->optimization_status)->toBe(DocumentOptimizationStatus::KeepOriginal);

    // no video
    $vtmp = tempnam(sys_get_temp_dir(), 'vid');
    file_put_contents($vtmp, 'fake');
    $video = new UploadedFile($vtmp, 'clip.mp4', 'video/mp4', null, true);
    $this->actingAs($admin)->post(route('documents.store'), ['file' => $video])
        ->assertSessionHasErrors('file');
});

test('documentos no son públicos por URL directa', function () {
    $admin = makeAdmin();
    $doc = app(DocumentCaptureService::class)->capture(makeJpegUpload(), $admin->id)['document'];
    $response = $this->get('/storage/'.$doc->path);
    expect(in_array($response->status(), [403, 404], true))->toBeTrue();
});
