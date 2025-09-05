<?php

namespace App\Jobs\Middleware;

use App\Jobs\SendEmailJob;
use Illuminate\Support\Facades\Redis;
use Carbon\CarbonImmutable;

class EmailRateLimit
{
    protected int $minuteLimit = 60;   // 每分钟最大任务数
    protected int $hourLimit = 3600;   // 每小时最大任务数
    protected int $randomBuffer = 5;   // 平滑随机秒

    public function handle($job, $next)
    {
        $queueName = $job->queue ?? 'default';
        $now = CarbonImmutable::now();

        // 当前分钟/小时计数 key
        $currentMinuteKey = "rate_limit:{$queueName}:current_minute:" . $now->format('YmdHi');
        $currentHourKey   = "rate_limit:{$queueName}:current_hour:" . $now->format('YmdH');

        // 原子自增
        $minuteCount = Redis::incr($currentMinuteKey);
        $hourCount   = Redis::incr($currentHourKey);

        // 设置过期时间
        Redis::expire($currentMinuteKey, max(1, $now->addMinute()->timestamp - $now->timestamp));
        Redis::expire($currentHourKey, max(1, $now->addHour()->timestamp - $now->timestamp));

        // 当前分钟/小时未超限，直接执行任务
        if ($minuteCount <= $this->minuteLimit && $hourCount <= $this->hourLimit) {
            $next($job);
            return;
        }

        // 分配未来可用槽（Lua 原子操作）
        $nextSlot = $this->allocateFutureSlotLua(
            $queueName,
            intval($now->format('YmdHi')),
            intval($now->format('YmdH'))
        );

        // 确保返回值 >= 当前分钟
        $currentSlot = intval($now->format('YmdHi'));
        if ($nextSlot < $currentSlot) {
            $nextSlot = $currentSlot;
        }

        $slotTime = CarbonImmutable::createFromFormat('YmdHi', strval($nextSlot));
        $delaySeconds = max(1, $slotTime->timestamp - $now->timestamp + rand(0, $this->randomBuffer));

        // 重新 dispatch 当前任务
        dispatch(new SendEmailJob($job->getParams(), $queueName))
            ->delay($now->addSeconds($delaySeconds));

        // 删除当前任务
        $job->delete();
    }

    protected function allocateFutureSlotLua(string $queueName, int $currentSlot, int $currentHour): int
    {
        $nextSlotKey = "rate_limit:{$queueName}:next_slot";
        $futureMinuteBaseKey = "rate_limit:{$queueName}:future_minute:";
        $futureHourBaseKey   = "rate_limit:{$queueName}:future_hour:";

        $now = CarbonImmutable::now();
        $ttlMinute = max(1, $now->addMinutes(2)->timestamp - $now->timestamp);
        $ttlHour   = max(1, $now->addHours(2)->timestamp - $now->timestamp);

        $luaScript = file_get_contents(app_path('Lua/email_rate_limit.lua'));

        $nextSlot = intval(Redis::eval(
            $luaScript,
            3,
            $nextSlotKey,
            $futureMinuteBaseKey,
            $futureHourBaseKey,
            $this->minuteLimit,
            $this->hourLimit,
            $currentSlot,
            $currentHour,
            $ttlMinute,
            $ttlHour
        ));

        return $nextSlot;
    }
}
