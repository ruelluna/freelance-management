<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Blade;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$paths = [
    'resources/views/pages/settings/⚡profile.blade.php',
    'resources/views/pages/settings/⚡security.blade.php',
    'resources/views/pages/settings/⚡appearance.blade.php',
    'resources/views/pages/settings/⚡delete-user-form.blade.php',
    'resources/views/pages/settings/⚡delete-user-modal.blade.php',
    'resources/views/pages/settings/⚡two-factor-setup-modal.blade.php',
    'resources/views/pages/settings/two-factor/⚡recovery-codes.blade.php',
    'resources/views/components/⚡create-team-modal.blade.php',
    'resources/views/components/⚡team-switcher.blade.php',
];

foreach ($paths as $path) {
    $compiled = Blade::compileString(file_get_contents(base_path($path)));
    $tmp = tempnam(sys_get_temp_dir(), 'blade');
    file_put_contents($tmp, $compiled);

    $check = shell_exec('php -l '.escapeshellarg($tmp).' 2>&1');
    echo $path.PHP_EOL.$check.PHP_EOL;
    unlink($tmp);
}
