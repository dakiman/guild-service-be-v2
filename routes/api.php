<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    $checks = [];

    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        $checks['database'] = 'ok';
    } catch (\Throwable) {
        $checks['database'] = 'failed';
    }

    try {
        \Illuminate\Support\Facades\Redis::ping();
        $checks['redis'] = 'ok';
    } catch (\Throwable) {
        $checks['redis'] = 'failed';
    }

    $allOk = ! in_array('failed', $checks);

    return response()->json($checks, $allOk ? 200 : 503);
});
