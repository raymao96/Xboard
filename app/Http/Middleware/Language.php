<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class Language
{
    public function handle($request, Closure $next)
    {
        $locale = null;

        // 1. header（前端/APP 主動指定）
        if ($request->header('content-language')) {
            $locale = $request->header('content-language');
        }

        // 2. user（登入後優先）
        $user = $request->user();
        if (!$locale && $user && $user->remarks) {
            $locale = $user->remarks === 'CN'
                ? 'zh-CN'
                : 'zh-TW';
        }

        // 3. fallback domain（未登入 or 無設定）
        if (!$locale) {
            $host = $request->getHost();

            $locale = $host !== parse_url(config('v2board.app_url'), PHP_URL_HOST)
                ? 'zh-CN'
                : 'zh-TW';
        }

        App::setLocale($locale);

        return $next($request);
    }
}
