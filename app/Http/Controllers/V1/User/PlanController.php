<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function fetch(Request $request)
    {
        $user = $request->user();
        $planService = app(PlanService::class);

        // =========================
        // 1. 單個 plan 查詢
        // =========================
        if ($request->input('id')) {
            $plan = Plan::find($request->input('id'));

            if (!$plan) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }

            if (!$planService->isPlanRegionAllowed($plan, $user) || !$planService->isPlanAvailableForUser($plan, $user)) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
            return $this->success(PlanResource::make($plan));
        }

        // =========================
        // 2. 取得所有可用 plans
        // =========================
        $plans = $planService->getAvailablePlans();

        // =========================
        // 3. TW / CN 業務過濾（不是語言）
        // =========================
        $isTW = PlanService::isTWUser($user);

        $plans = $plans->filter(function ($plan) use ($isTW) {
            return (int) $plan->tw === ($isTW ? 1 : 0);
        })->values();

        return $this->success(PlanResource::collection($plans));
    }
}
