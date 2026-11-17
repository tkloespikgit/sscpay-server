<?php

return [
    'source_path' => base_path('plugins/wordpress'),
    'disk' => env('PLUGIN_RELEASE_DISK', 'local'),
    'prefix' => 'plugin-releases',
    'build_path' => storage_path('app/plugin-builds'),
];
