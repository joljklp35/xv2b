<?php

namespace App\Jobs\Middleware;

use App\Jobs\SendEmailJob;
use Illuminate\Support\Facades\Redis;
use Carbon\CarbonImmutable;

class EmailRateLimit
{
    protected int $minuteLimit = 60;
    protected int $hourLimit = 3600;
    protected int $randomBuffer = 5;

    public function handle($job, $next)
    {
        $queueName = $job->queue ?? 'default';
        $now = CarbonImmutable::now();

        $currentSlot = intval($now->format('YmdHi'));
        $currentHour = intval($now->format('YmdH'));

        $nextSlotKey = "rate_limit:{$queueName}:next_slot";
        $futureMinuteKey = "rate_limit:{$queueName}:future_slot:{$currentSlot}";
        $futureHourKey = "rate_limit:{$queueName}:future_hour:{$currentHour}";

        $ttlMinute = max(1, $now->addMinute()->timestamp - $now->timestamp);
        $ttlHour = max(1, $now->addHour()->timestamp - $now->timestamp);

        $luaScript = file_get_contents(app_path('Lua/email_rate_limit.lua'));
        $nextSlot = intval(Redis::eval(
            $luaScript,
            3,
            $nextSlotKey,
            $futureMinuteKey,
            $futureHourKey,
            $this->minuteLimit,
            $this->hourLimit,
            $currentSlot,
            $currentHour,
            $ttlMinute,
            $ttlHour
        ));

        if ($nextSlot <= $currentSlot) {
            $next($job);
            return;
        }

        $slotTime = CarbonImmutable::createFromFormat('YmdHi', strval($nextSlot));
        $delaySeconds = max(1, $slotTime->timestamp - $now->timestamp + rand(0, $this->randomBuffer));

        dispatch(new SendEmailJob($job->getParams(), $queueName))
            ->delay($now->addSeconds($delaySeconds));

        $job->delete();
    }
}
