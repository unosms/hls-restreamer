<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
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

    return view('dashboard', [
        'totalStreams' => $totalStreams,
        'runningStreams' => $runningStreams,
        'offlineStreams' => $offlineStreams,
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::match(['GET', 'POST'], '/streams', function () {
        // Serve manager backend for form actions and AJAX status requests.
        if (request()->isMethod('post') || request()->query('ajax') === 'status') {
            ob_start();
            include resource_path('views/dashboard_streams.php');
            return response(ob_get_clean());
        }

        return view('streams');
    })->name('streams');

    Route::match(['GET', 'POST'], '/streams-content', function () {
        ob_start();
        include resource_path('views/dashboard_streams.php');
        return response(ob_get_clean());
    })->name('streams.content');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

});

require __DIR__.'/auth.php';
