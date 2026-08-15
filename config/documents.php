<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Captura y validación (12A)
    |--------------------------------------------------------------------------
    */

    /*
     | Exposición temporal en UI pública (sidebar / atajos).
     | false = 12A queda en código/rutas/storage pero sin menú ni shortcuts.
     | Reactivar con DOCUMENTS_SHOW_IN_UI=true cuando la captura esté certificada.
     */
    'show_in_ui' => (bool) env('DOCUMENTS_SHOW_IN_UI', false),

    'max_upload_kb' => (int) env('DOCUMENTS_MAX_UPLOAD_KB', 10240), // 10 MB

    'allowed_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ],

    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],

    'rejected_mimes' => [
        'image/heic',
        'image/heif',
        'video/mp4',
        'video/webm',
        'video/quicktime',
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage privado (disk local = storage/app/private)
    |--------------------------------------------------------------------------
    */

    'disk' => env('DOCUMENTS_DISK', 'local'),

    'paths' => [
        'documents' => 'documents',
        'temp' => 'documents/temp',
        'previews' => 'documents/previews',
        'optimized' => 'documents/optimized',
    ],

    /*
    |--------------------------------------------------------------------------
    | Optimización / retención (34B) — en 12A no se borra el original post-upload
    |--------------------------------------------------------------------------
    */

    'optimization' => [
        'enabled' => true,
        'target_min_kb' => 200,
        'target_max_kb' => 500,
        'max_edge_px' => 1600,
        'jpeg_quality' => 78,
        'preview_max_edge_px' => 480,
        'preview_jpeg_quality' => 70,
        // 12A: conservar original hasta OCR/aprobación (12B).
        'delete_original_after_optimize' => false,
    ],

    'temp_ttl_hours' => (int) env('DOCUMENTS_TEMP_TTL_HOURS', 24),

    'storage_quota_bytes' => (int) env('DOCUMENTS_STORAGE_QUOTA_BYTES', 2_147_483_648), // 2 GiB

    'storage_warn_percent' => (int) env('DOCUMENTS_STORAGE_WARN_PERCENT', 70),

    'storage_critical_percent' => (int) env('DOCUMENTS_STORAGE_CRITICAL_PERCENT', 85),

];
