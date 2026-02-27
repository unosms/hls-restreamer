<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

$collectStreamStats = static function (): array {
    $baseDir = '/var/www/stream/live';
    $channels = [];

    if (is_dir($baseDir)) {
        foreach (scandir($baseDir) as $d) {
            if ($d === '.' || $d === '..') {
                continue;
            }
            if (is_dir($baseDir.'/'.$d) && preg_match('/^[A-Za-z0-9_-]{1,50}$/', $d)) {
                $channels[] = $d;
            }
        }
    }

    $totalStreams = count($channels);
    $runningStreams = 0;

    $runCmd = static function (string $cmd): array {
        $out = [];
        $rc = 0;
        @exec($cmd.' 2>&1', $out, $rc);
        return [$rc, trim(implode("\n", $out))];
    };

    $systemctl = is_executable('/bin/systemctl') ? '/bin/systemctl' : 'systemctl';

    foreach ($channels as $ch) {
        $service = 'hls_'.$ch.'.service';
        [$rc, $out] = $runCmd($systemctl.' is-active '.escapeshellarg($service));
        if ($rc !== 0 || $out !== 'active') {
            [$rc, $out] = $runCmd('sudo -n '.$systemctl.' is-active '.escapeshellarg($service));
        }
        if ($out === 'active') {
            $runningStreams++;
        }
    }

    $offlineStreams = max(0, $totalStreams - $runningStreams);

    return [
        'totalStreams' => $totalStreams,
        'runningStreams' => $runningStreams,
        'offlineStreams' => $offlineStreams,
        'updatedAt' => now()->toDateTimeString(),
    ];
};

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () use ($collectStreamStats) {
    return view('dashboard', $collectStreamStats());
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/dashboard/stats', function () use ($collectStreamStats) {
    return response()->json($collectStreamStats());
})->middleware(['auth', 'verified'])->name('dashboard.stats');

Route::middleware('auth')->group(function () {
    Route::match(['GET', 'POST'], '/streams', function () {
        // Backward compatibility for direct POST/AJAX calls.
        if (request()->isMethod('post') || request()->query('ajax') === 'status') {
            $managerMode = 'streams';
            ob_start();
            include resource_path('views/dashboard_streams.php');
            return response(ob_get_clean());
        }

        return view('streams');
    })->name('streams');

    Route::match(['GET', 'POST'], '/streams-content', function () {
        $managerMode = 'streams';
        ob_start();
        include resource_path('views/dashboard_streams.php');
        return response(ob_get_clean());
    })->name('streams.content');

    Route::get('/settings', function () {
        return view('settings');
    })->name('settings');

    Route::match(['GET', 'POST'], '/settings-content', function () {
        $managerMode = 'settings';
        ob_start();
        include resource_path('views/dashboard_streams.php');
        return response(ob_get_clean());
    })->name('settings.content');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

});

require __DIR__.'/auth.php';
