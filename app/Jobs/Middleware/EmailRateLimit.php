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
    protected int $maxLoop = 100; 

    public function handle($job, $next)
    {
        $queueName = $job->queue ?? 'default';
        $now = CarbonImmutable::now();

        $currentMinuteKey = "rate_limit:{$queueName}:current_minute:" . $now->format('YmdHi');
        $currentHourKey   = "rate_limit:{$queueName}:current_hour:" . $now->format('YmdH');

        $minuteCount = Redis::incr($currentMinuteKey);
        $hourCount   = Redis::incr($currentHourKey);

        Redis::expire($currentMinuteKey, max(1, $now->addMinute()->timestamp - $now->timestamp));
        Redis::expire($currentHourKey, max(1, $now->addHour()->timestamp - $now->timestamp));

        if ($minuteCount <= $this->minuteLimit && $hourCount <= $this->hourLimit) {
            $next($job);
            return;
        }

        $nextSlot = $this->allocateFutureSlot($queueName, intval($now->format('YmdHi')));

        $delaySeconds = ($nextSlot - intval($now->format('YmdHi'))) * 60 + rand(0, $this->randomBuffer);

        dispatch(new SendEmailJob($job->getParams(), $queueName))
            ->delay($now->addSeconds($delaySeconds));

        $job->delete();
    }

    protected function allocateFutureSlot(string $queueName, int $currentSlot): int
    {
        $nextSlotKey = "rate_limit:{$queueName}:next_slot";

        $nextSlot = Redis::get($nextSlotKey);
        if ($nextSlot === null || intval($nextSlot) < $currentSlot) {
            Redis::set($nextSlotKey, $currentSlot);
            $nextSlot = $currentSlot;
        } else {
            $nextSlot = intval($nextSlot);
        }

        $loop = 0;
        while ($loop < $this->maxLoop) {
            $loop++;

            $futureKey = "rate_limit:{$queueName}:future_slot:" . $nextSlot;
            $count = Redis::incr($futureKey);

            $slotTime = CarbonImmutable::createFromFormat('YmdHi', strval($nextSlot));
            $ttl = max(1, $slotTime->addMinute()->timestamp - CarbonImmutable::now()->timestamp);
            Redis::expire($futureKey, $ttl);

            if ($count <= $this->minuteLimit) {
                Redis::set($nextSlotKey, $nextSlot);
                return $nextSlot;
            }

            $nextSlot = intval(Redis::incr($nextSlotKey));
        }

        return $nextSlot;
    }
}
