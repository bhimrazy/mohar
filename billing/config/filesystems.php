<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        | Object storage for business files (ticket attachments, invoice PDFs, exports).
        | Locally backed by RustFS (compose.yaml); any S3-compatible service works in production.
        | Set OBJECTS_DRIVER=local to use storage/app/objects without running RustFS.
        */
        'objects' => [
            'driver' => env('OBJECTS_DRIVER', 's3'),
            // Local driver: a directory. S3/RustFS: an optional key prefix inside the bucket (never a local path).
            'root' => env('OBJECTS_DRIVER', 's3') === 'local' ? storage_path('app/objects') : env('OBJECTS_PREFIX', ''),
            'key' => env('OBJECTS_ACCESS_KEY'),
            'secret' => env('OBJECTS_SECRET_KEY'),
            'region' => env('OBJECTS_REGION', 'us-east-1'),
            'bucket' => env('OBJECTS_BUCKET', 'billing'),
            'endpoint' => env('OBJECTS_ENDPOINT'),
            'use_path_style_endpoint' => env('OBJECTS_PATH_STYLE', true),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
