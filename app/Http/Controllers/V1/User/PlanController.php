<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function fetch(Request $request)
    {
        $user = $request->user();

        // =========================
        // 1. 單個 plan 查詢
        // =========================
        if ($request->input('id')) {
            $plan = Plan::find($request->input('id'));

            if (!$plan) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }

            if (!app(\App\Services\PlanService::class)
                ->isPlanAvailableForUser($plan, $user)) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
            return $this->success(PlanResource::make($plan));
        }

        // =========================
        // 2. 取得所有可用 plans
        // =========================
        $plans = app(\App\Services\PlanService::class)
            ->getAvailablePlans();

        // =========================
        // 3. TW / CN 業務過濾（不是語言）
        // =========================
        $isTW = $this->isTWUser($user);

        $plans = $plans->filter(function ($plan) use ($isTW) {
            return (int) $plan->tw === ($isTW ? 1 : 0);
        })->values();

        return $this->success(PlanResource::collection($plans));
    }

    /**
     * 業務判斷：是否 TW 用戶（不是語言控制）
     * 包含：備註為 TW 或 RAYFISH 的用戶
     */
    private function isTWUser($user): bool
    {
        if (!$user || !$user->remarks) {
            return false;
        }

        // 將備註轉為大寫並去除空格
        $remark = strtoupper(trim($user->remarks));

        // 判斷是否在台灣用戶的白名單內
        return in_array($remark, ['TW', 'RAYFISH']);
    }
}
