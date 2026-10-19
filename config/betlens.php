<?php

return [
    'import_execution_source' => env('BETLENS_IMPORT_EXECUTION_SOURCE', env('APP_ENV') === 'production' ? 'Laravel-DO' : 'Laravel-local'),
    'raw_import_disk' => env('BETLENS_RAW_IMPORT_DISK', 'local'),
];
