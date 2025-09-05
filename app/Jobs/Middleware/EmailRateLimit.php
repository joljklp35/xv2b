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
        $currentSlot = intval($now->format('YmdHi'));

        $nextSlot = $this->allocateSlot($queueName, $currentSlot);
        $delaySeconds = ($nextSlot - $currentSlot) * 60 + rand(0, $this->randomBuffer);

        if ($delaySeconds <= 0) {
            $next($job);
        } else {
            dispatch(new SendEmailJob($job->getParams(), $queueName))
                ->delay($now->addSeconds($delaySeconds));
            $job->delete();
        }
    }

    protected function allocateSlot(string $queueName, int $currentSlot): int
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
            $slotKey = "rate_limit:{$queueName}:{$nextSlot}";
            $count = Redis::incr($slotKey);
            $minuteSlotTime = CarbonImmutable::createFromFormat('YmdHi', strval($nextSlot));
            $ttlMinute = max(1, $minuteSlotTime->addMinute()->timestamp - CarbonImmutable::now()->timestamp);
            Redis::expire($slotKey, $ttlMinute);
            $hourSlotTime = CarbonImmutable::createFromFormat('YmdHi', strval($nextSlot));
            $hourKey = "rate_limit:{$queueName}:hour:" . $hourSlotTime->format('YmdH');
            $hourCount = Redis::incr($hourKey);
            $ttlHour = max(1, $hourSlotTime->addHour()->timestamp - CarbonImmutable::now()->timestamp);
            Redis::expire($hourKey, $ttlHour);
            if ($count <= $this->minuteLimit && $hourCount <= $this->hourLimit) {
                Redis::set($nextSlotKey, $nextSlot);
                return $nextSlot;
            }
            $nextSlot = intval(Redis::incr($nextSlotKey));
        }
        return $nextSlot;
    }
}
