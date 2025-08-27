<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Coupon;
use App\Models\User;
use App\Models\Plan;
use App\Jobs\OrderHandleJob;
use App\Utils\Helper;
use App\Services\OrderNotifyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class OrderService
{
    CONST STR_TO_TIME = [
        'month_price' => 1,
        'quarter_price' => 3,
        'half_year_price' => 6,
        'year_price' => 12,
        'two_year_price' => 24,
        'three_year_price' => 36
    ];
    public $order;
    public $user;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function open()
    {
        $order = $this->order;
        $this->user = User::find($order->user_id);
        if ($order->type == 9) {
            DB::beginTransaction();
            $this->user->balance += $order->total_amount + $this->getbounus($order->total_amount);

            if (!$this->user->save()) {
                DB::rollBack();
                abort(500, '充值失败');
            }
            $order->status = 3;
            if (!$order->save()) {
                DB::rollBack();
                abort(500, '充值失败');
            }
            DB::commit();
            return;
        }

        $plan = Plan::find($order->plan_id);

        if ($order->refund_amount) {
            $this->user->balance = $this->user->balance + $order->refund_amount;
        }
        DB::beginTransaction();
        if ($order->surplus_order_ids) {
            try {
                Order::whereIn('id', $order->surplus_order_ids)->update([
                    'status' => 4
                ]);
            } catch (\Exception $e) {
                DB::rollback();
                abort(500, '开通失败');
            }
        }
        switch ((string)$order->period) {
            case 'onetime_price':
                $this->buyByOneTime($order, $plan);
                break;
            case 'reset_price':
                $this->buyByResetTraffic();
                break;
            default:
                $this->buyByPeriod($order, $plan);
        }

        switch ((int)$order->type) {
            case 1:
                $this->openEvent(config('v2board.new_order_event_id', 0));
                break;
            case 2:
                $this->openEvent(config('v2board.renew_order_event_id', 0));
                break;
            case 3:
                $this->openEvent(config('v2board.change_order_event_id', 0));
                break;
        }

        $this->setSpeedLimit($plan->speed_limit);

        if (!$this->user->save()) {
            DB::rollBack();
            abort(500, '开通失败');
        }
        $order->status = 3;
        if (!$order->save()) {
            DB::rollBack();
            abort(500, '开通失败');
        }

        DB::commit();
    }


    public function setOrderType(User $user)
    {
        $order = $this->order;
        if ($order->period === 'deposit'){
            $order->type = 9;
        } else if ($order->period === 'reset_price') {
            $order->type = 4;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id && ($user->expired_at > time() || $user->expired_at === NULL)) {
            if (!(int)config('v2board.plan_change_enable', 1)) abort(500, '目前不允许更改订阅，请联系客服或提交工单操作');
            $order->type = 3;
            if ((int)config('v2board.surplus_enable', 1)) $this->getSurplusValue($user, $order);
            if ($order->surplus_amount >= $order->total_amount) {
                $order->refund_amount = $order->surplus_amount - $order->total_amount;
                $order->total_amount = 0;
            } else {
                $order->total_amount = $order->total_amount - $order->surplus_amount;
            }
        } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) { // 用户订阅未过期且购买订阅与当前订阅相同 === 续费
            $order->type = 2;
        } else { // 新购
            $order->type = 1;
        }
    }

    public function setVipDiscount(User $user)
    {
        $order = $this->order;
        if ($user->discount) {
            $order->discount_amount = $order->discount_amount + ($order->total_amount * ($user->discount / 100));
        }
        $order->total_amount = $order->total_amount - $order->discount_amount;
    }

    public function setInvite(User $user):void
    {
        $order = $this->order;
        if ($user->invite_user_id && ($order->total_amount <= 0)) return;
        $order->invite_user_id = $user->invite_user_id;
        $inviter = User::find($user->invite_user_id);
        if (!$inviter) return;
        $isCommission = false;
        switch ((int)$inviter->commission_type) {
            case 0:
                $commissionFirstTime = (int)config('v2board.commission_first_time_enable', 1);
                $isCommission = (!$commissionFirstTime || ($commissionFirstTime && !$this->haveValidOrder($user)));
                break;
            case 1:
                $isCommission = true;
                break;
            case 2:
                $isCommission = !$this->haveValidOrder($user);
                break;
        }

        if (!$isCommission) return;
        if ($inviter && $inviter->commission_rate) {
            $order->commission_balance = $order->total_amount * ($inviter->commission_rate / 100);
        } else {
            $order->commission_balance = $order->total_amount * (config('v2board.invite_commission', 10) / 100);
        }
    }

    private function haveValidOrder(User $user)
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [0, 2])
            ->first();
    }

    private function getSurplusValue(User $user, Order $order)
    {
        if ($user->expired_at === NULL) {
            $this->getSurplusValueByOneTime($user, $order);
        } else {
            $this->getSurplusValueByPeriod($user, $order);
        }
    }


    private function getSurplusValueByOneTime(User $user, Order $order)
    {
        $lastOneTimeOrder = Order::where('user_id', $user->id)
            ->where('period', 'onetime_price')
            ->where('status', 3)
            ->orderBy('id', 'DESC')
            ->first();
        if (!$lastOneTimeOrder) return;
        $nowUserTraffic = $user->transfer_enable / 1073741824;
        if ($nowUserTraffic == 0) return;
        $paidTotalAmount = ($lastOneTimeOrder->total_amount + $lastOneTimeOrder->balance_amount);
        if ($paidTotalAmount == 0) return;
        $notUsedTraffic = $nowUserTraffic - (($user->u + $user->d) / 1073741824);
        $remainingTrafficRatio = $notUsedTraffic / $nowUserTraffic;
        $result = $remainingTrafficRatio * $paidTotalAmount;
        $order->surplus_amount = max($result, 0);
        $orderModel = Order::where('user_id', $user->id)->where('period', '!=', 'reset_price')->where('status', 3);
        $order->surplus_order_ids = array_column($orderModel->get()->toArray(), 'id');
    }

    private function getSurplusValueByPeriod(User $user, Order $order)
    {
        $orders = Order::where('user_id', $user->id)
            ->where('period', '!=', 'reset_price')
            ->where('period', '!=', 'onetime_price')
            ->where('period', '!=', 'deposit')
            ->where('period', '!=', '')
            ->where('status', 3)
            ->get()
            ->toArray();
        if (!$orders) return;
        $orderAmountSum = 0;
        $orderMonthSum = 0;
        $lastValidateAt = null;
        foreach ($orders as $item) {
            $period = self::STR_TO_TIME[$item['period']];
            $orderEndTime = strtotime("+{$period} month", $item['created_at']);
            if ($orderEndTime < time()) continue;
            $lastValidateAt = $item['created_at'] > $lastValidateAt ? $item['created_at'] : $lastValidateAt;
            $orderMonthSum += $period;
            $orderAmountSum += $item['total_amount'] + $item['balance_amount'] + $item['surplus_amount'] - $item['refund_amount'];
        }
        if ($lastValidateAt === null) return;
    
        $expiredAtByOrder = strtotime("+{$orderMonthSum} month", $lastValidateAt);
        if ($expiredAtByOrder < time()) return;
        $orderSurplusSecond = $expiredAtByOrder - time();
        $orderRangeSecond = $expiredAtByOrder - $lastValidateAt;
    
        $totalTraffic = $user->transfer_enable / 1073741824;
        $usedTraffic = ($user->u + $user->d) / 1073741824;
        if ($totalTraffic == 0) return;
    
        $remainingTrafficRatio = ($totalTraffic - $usedTraffic) / $totalTraffic;
    
        $avgPricePerSecond = $orderAmountSum / $orderRangeSecond;
        if ($orderRangeSecond <= 31 * 86400) {
            $orderSurplusAmount = $avgPricePerSecond * $orderSurplusSecond * $remainingTrafficRatio;
        } else {
            $firstMonthSeconds = min($orderSurplusSecond, 30 * 86400);
            $laterMonthsSeconds = $orderSurplusSecond - $firstMonthSeconds;
            $orderSurplusAmount = $avgPricePerSecond * $firstMonthSeconds * $remainingTrafficRatio +
                                  $avgPricePerSecond * $laterMonthsSeconds;
        }
    
        $order->surplus_amount = max($orderSurplusAmount, 0);
        $order->surplus_order_ids = array_column($orders, 'id');
    }

    public function paid(string $callbackNo)
    {
        $order = $this->order;
        if ($order->status !== 0) return true;
        if ($order->coupon_id) {
            $coupon = Coupon::find($order->coupon_id);
            if ($coupon && !empty($coupon->bind_email)) {
                $inviter = User::where('email', $coupon->bind_email)->first();
                if ($inviter && $order->user_id && $inviter->id !== $order->user_id) {
                    User::where('id', $order->user_id)
                        ->whereNull('invite_user_id')
                        ->update(['invite_user_id' => $inviter->id]);
                    $order->invite_user_id = $inviter->id;
                }
            }
        }
        $order->status = 1;
        $order->paid_at = time();
        $order->callback_no = $callbackNo;
        if (!$order->save()) return false;
        try {
            OrderHandleJob::dispatch($order->trade_no);
            app(OrderNotifyService::class)->notify($order);
            $this->handleFirstOrderReward($order);
        } catch (\Exception $e) {
            Log::error('订单支付处理异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    
        return true;
    }
    

    public function cancel():bool
    {
        $order = $this->order;
        DB::beginTransaction();
        $order->status = 2;
        if (!$order->save()) {
            DB::rollBack();
            return false;
        }
        if ($order->balance_amount) {
            $userService = new UserService();
            if (!$userService->addBalance($order->user_id, $order->balance_amount)) {
                DB::rollBack();
                return false;
            }
        }
        DB::commit();
        return true;
    }

    private function setSpeedLimit($speedLimit)
    {
        $this->user->speed_limit = $speedLimit;
    }

    private function buyByResetTraffic()
    {
        $this->user->u = 0;
        $this->user->d = 0;
    }

    private function buyByPeriod(Order $order, Plan $plan)
    {
        // change plan process
        if ((int)$order->type === 3) {
            $this->user->expired_at = time();
        }
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        $this->user->device_limit = $plan->device_limit;
        // 从一次性转换到循环
        if ($this->user->expired_at === NULL) $this->buyByResetTraffic();
        // 新购
        if ($order->type === 1) $this->buyByResetTraffic();

        // 到期当天续费刷新流量
        $expireDay = date('d', $this->user->expired_at);
        $expireMonth = date('m', $this->user->expired_at);
        $today = date('d');
        $currentMonth = date('m');
        if ($order->type === 2 && $expireMonth == $currentMonth && $expireDay === $today ) {
            $this->buyByResetTraffic();
        }

        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        if (empty($order->period) && $order->gift_days > 0) {
            $baseTime = $this->user->expired_at ?? time();
            $secondsToAdd = (int) round($order->gift_days * 86400);
            $this->user->expired_at = $baseTime + $secondsToAdd;
        } else {
            $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
        }
    }

    private function buyByOneTime(Order $order, Plan $plan)
    {
        $transfer_enable = $plan->transfer_enable;
        if (!$order->surplus_order_ids) {
            $notUsedTraffic = ($this->user->transfer_enable - ($this->user->u + $this->user->d)) / 1073741824;
            if ($notUsedTraffic > 0 && $this->user->expired_at == NULL) {
                $transfer_enable += $notUsedTraffic;
            }
        }
        $this->buyByResetTraffic();
        $this->user->transfer_enable = $transfer_enable * 1073741824;
        $this->user->device_limit = $plan->device_limit;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
    }

    private function getTime($str, $timestamp)
    {
        if ($timestamp < time()) {
            $timestamp = time();
        }
        switch ($str) {
            case 'month_price':
                return strtotime('+1 month', $timestamp);
            case 'quarter_price':
                return strtotime('+3 month', $timestamp);
            case 'half_year_price':
                return strtotime('+6 month', $timestamp);
            case 'year_price':
                return strtotime('+12 month', $timestamp);
            case 'two_year_price':
                return strtotime('+24 month', $timestamp);
            case 'three_year_price':
                return strtotime('+36 month', $timestamp);
        }
    }

    private function openEvent($eventId)
    {
        switch ((int) $eventId) {
            case 0:
                break;
            case 1:
                $this->buyByResetTraffic();
                break;
        }
    }

    private function getbounus($total_amount) {
        $deposit_bounus = config('v2board.deposit_bounus', []);
        if (empty($deposit_bounus)) {
            return 0;
        }
        $add = 0;
        foreach ($deposit_bounus as $tier) {
            list($amount, $bounus) = explode(':', $tier);
            $amount = (float)$amount * 100;
            $bounus = (float)$bounus * 100;
            $amount = (int)$amount;
            $bounus = (int)$bounus;
            if ($total_amount >= $amount) {
                $add = max($add, $bounus);
            }
        }
        return $add;
    }
    private function handleFirstOrderReward(Order $order)
    {
        $inviteGiveType = (int) config('v2board.is_Invitation_to_give', 0);
        if (!in_array($inviteGiveType, [2, 3])) {
            return;
        }
        //0. 0元订单不触发奖励
        if($order->total_amount==0) return;
        
        // 1. 获取订单用户和其邀请人
        $user = User::find($order->user_id);
        if (!$user || !$user->invite_user_id) {
            return;
        }

        // 2. 获取邀请人信息
        $inviter = User::find($user->invite_user_id);
        if (!$inviter) {
            return;
        }
        // 获取邀请人当前套餐
        $inviterCurrentPlan = Plan::find($inviter->plan_id);
        if (!$inviterCurrentPlan || (int)config('v2board.try_out_plan_id') == $inviter->plan_id) {
            return;
        }
        // 3. 检查是否是首次付费订单
        $hasOtherPaidOrders = Order::where('user_id', $user->id)
            ->where('id', '!=', $order->id)
            ->where('status', 3) // 已支付
            ->exists();

        if ($hasOtherPaidOrders) {
            return;
        }
        // 4. 处理邀请奖励
        $rewardPlan = Plan::find((int) config('v2board.complimentary_packages'));
        if (!$rewardPlan) {
            return;
        }
        try {
            DB::transaction(function () use ($user, $rewardPlan, $inviterCurrentPlan, $inviter) {
                // 初始化时间
                $currentTime = time();
                if ($inviter->expired_at === null || $inviter->expired_at < $currentTime) {
                    $inviter->expired_at = $currentTime;
                }

                // 计算奖励套餐的月均价值
                $rewardMonthlyValue = $this->getMonthlyValue($rewardPlan);

                // 计算邀请人当前套餐的月均价值
                $inviterMonthlyValue = $this->getMonthlyValue($inviterCurrentPlan);

                // 计算套餐价值比例：奖励套餐月均价值 / 邀请人套餐月均价值
                $priceRatio = $rewardMonthlyValue / $inviterMonthlyValue;

                // 配置的赠送小时数
                $configHours = (int) config('v2board.complimentary_package_duration', 0);

                // 根据价值比例折算实际赠送时间
                $adjustedHours = $configHours * $priceRatio;
                $add_seconds = $adjustedHours * 3600; // 转换为秒

                // 更新邀请人到期时间
                $inviter->expired_at = $inviter->expired_at + $add_seconds;

                // 将秒数转换为天数（用于显示）
                $calculated_days = $add_seconds / 86400;
                $formatted_days = number_format($calculated_days, 2, '.', '');

                // 创建赠送订单
                $order = new Order();
                $orderService = new OrderService($order);
                $order->user_id = $inviter->id;
                $order->plan_id = $inviter->plan_id;
                $order->period = '';
                $order->trade_no = Helper::guid();
                $order->total_amount = 0;
                $order->status = 0;
                $order->type = 6;
                $order->gift_days = $formatted_days;
                $orderService->paid('firstorder');
            });
        } catch (\Exception $e) {
            \Log::error('处理首单购买奖励失败', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'inviter_id' => $user->invite_user_id,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    private function getMonthlyValue($plan)
    {
        $monthlyValues = [];

        // 月付价格
        if ($plan->month_price > 0) {
            $monthlyValues[] = $plan->month_price;
        }

        // 季付价格折算为月价格
        if ($plan->quarter_price > 0) {
            $monthlyValues[] = $plan->quarter_price / 3;
        }

        // 半年付价格折算为月价格
        if ($plan->half_year_price > 0) {
            $monthlyValues[] = $plan->half_year_price / 6;
        }

        // 年付价格折算为月价格
        if ($plan->year_price > 0) {
            $monthlyValues[] = $plan->year_price / 12;
        }

        // 两年付价格折算为月价格
        if ($plan->two_year_price > 0) {
            $monthlyValues[] = $plan->two_year_price / 24;
        }

        // 三年付价格折算为月价格
        if ($plan->three_year_price > 0) {
            $monthlyValues[] = $plan->three_year_price / 36;
        }

        // 一次性付费，假设等同于年付折算
        if ($plan->onetime_price > 0) {
            $monthlyValues[] = $plan->onetime_price / 12;
        }
        // 如果没有有效价格，返回1以避免除零错误
        if (empty($monthlyValues)) {
            return 1;
        }

        // 返回最优惠的月均价值（最大值）
        return max($monthlyValues);
    }
}
