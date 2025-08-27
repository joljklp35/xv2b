<?php

use App\Services\ThemeService;
use Illuminate\Http\Request;

Route::get('/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))), function (Request $request) {
    if ($wh = config('v2board.whitehost')) {
        if (!in_array(strtolower($request->server('HTTP_HOST')), array_map('strtolower', explode(',', $wh)))) {
            Log::warning('非法访问：' . $request->server('HTTP_HOST'));
            abort(403);
        }
    }
    return view('admin', [
        'title' => config('v2board.app_name', 'V2Board'),
        'theme_sidebar' => config('v2board.frontend_theme_sidebar', 'light'),
        'theme_header' => config('v2board.frontend_theme_header', 'dark'),
        'theme_color' => config('v2board.frontend_theme_color', 'default'),
        'background_url' => config('v2board.frontend_background_url'),
        'version' => config('app.version'),
        'logo' => config('v2board.logo'),
        'secure_path' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
});

if (!empty(config('v2board.subscribe_path'))) {
    Route::get(config('v2board.subscribe_path'), 'V1\\Client\\ClientController@subscribe')->middleware('client');
}
