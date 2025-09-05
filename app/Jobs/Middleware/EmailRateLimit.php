<?php

namespace App\Jobs\Middleware;

use App\Jobs\SendEmailJob;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Carbon\CarbonImmutable;
use Exception;

class EmailRateLimit
{
    protected int $minuteLimit = 60;     // 每分钟最大任务数
    protected int $hourLimit = 3600;     // 每小时最大任务数
    protected int $randomBuffer = 5;     // 随机延迟缓冲秒数

    public function handle($job, $next)
    {
        try {
            $queueName = $job->queue ?? 'default';
            $now = CarbonImmutable::now();

            // 检查并更新当前分钟和小时的限流
            $canExecute = $this->checkAndUpdateRateLimit($queueName, $now);

            if ($canExecute) {
                return $next($job);
            }

            // 分配未来时间槽
            $nextSlot = $this->allocateFutureSlotLua(
                $queueName,
                intval($now->format('YmdHi')),
                intval($now->format('YmdH'))
            );

            // 确保返回值 >= 当前分钟
            $currentSlot = intval($now->format('YmdHi'));
            if ($nextSlot < $currentSlot) {
                $nextSlot = $currentSlot + 1;
            }

            $slotTime = CarbonImmutable::createFromFormat('YmdHi', strval($nextSlot));
            $delaySeconds = max(1, $slotTime->timestamp - $now->timestamp + rand(0, $this->randomBuffer));

            // 使用 release 延迟重新执行当前任务
            $job->release($delaySeconds);

        } catch (Exception $e) {
            Log::error('邮件限流中间件错误', [
                'error' => $e->getMessage(),
                'job' => get_class($job),
                'queue' => $job->queue ?? 'default'
            ]);
            
            // 发生错误时允许任务继续执行，避免阻塞
            return $next($job);
        }
    }

    /**
     * 检查并更新限流计数器
     */
    protected function checkAndUpdateRateLimit(string $queueName, CarbonImmutable $now): bool
    {
        $currentMinuteKey = $this->getCurrentMinuteKey($queueName, $now);
        $currentHourKey = $this->getCurrentHourKey($queueName, $now);

        // 原子自增
        $minuteCount = Redis::incr($currentMinuteKey);
        $hourCount = Redis::incr($currentHourKey);

        // 设置过期时间 - 保留2个周期
        // 当前分钟key：保留2分钟（当前分钟 + 下一分钟）
        $minuteExpiry = 120 - $now->second; // 2分钟减去当前秒数
        // 当前小时key：保留2小时（当前小时 + 下一小时）
        $hourExpiry = 7200 - ($now->minute * 60 + $now->second); // 2小时减去已过去的时间

        Redis::expire($currentMinuteKey, max(1, $minuteExpiry));
        Redis::expire($currentHourKey, max(1, $hourExpiry));

        // 当前分钟/小时未超限，可以执行任务
        return ($minuteCount <= $this->minuteLimit && $hourCount <= $this->hourLimit);
    }

    protected function allocateFutureSlotLua(string $queueName, int $currentSlot, int $currentHour): int
    {
        $nextSlotKey = "rate_limit:{$queueName}:next_slot";
        $futureMinuteBaseKey = "rate_limit:{$queueName}:future_minute:";
        $futureHourBaseKey = "rate_limit:{$queueName}:future_hour:";

        $luaScript = $this->getFutureSlotAllocationScript();

        try {
            $nextSlot = intval(Redis::eval(
                $luaScript,
                3, // Number of keys
                $nextSlotKey,
                $futureMinuteBaseKey,
                $futureHourBaseKey,
                $this->minuteLimit,
                $this->hourLimit,
                $currentSlot,
                $currentHour
            ));

            return $nextSlot;
        } catch (Exception $e) {
            Log::error('Lua脚本执行失败', ['error' => $e->getMessage()]);
            
            // 后备策略：简单递增
            return $currentSlot + 1;
        }
    }

    /**
     * 获取未来时间槽分配的Lua脚本
     */
    protected function getFutureSlotAllocationScript(): string
    {
        return '
            local next_slot_key = KEYS[1]
            local future_minute_base = KEYS[2]
            local future_hour_base = KEYS[3]
            
            local minute_limit = tonumber(ARGV[1])
            local hour_limit = tonumber(ARGV[2])
            local current_slot = tonumber(ARGV[3])
            local current_hour = tonumber(ARGV[4])
            
            -- 获取或初始化下一个可用槽位，如果key不存在则从当前槽+1开始
            local next_slot = redis.call("GET", next_slot_key)
            if not next_slot then
                next_slot = current_slot + 1
            else
                next_slot = tonumber(next_slot)
            end
            
            -- 确保不会分配过去的时间槽
            if next_slot <= current_slot then
                next_slot = current_slot + 1
            end
            
            -- 搜索下一个可用槽位
            local search_start = next_slot
            while true do
                local minute_key = future_minute_base .. next_slot
                local hour_key = future_hour_base .. math.floor(next_slot / 100)
                
                -- 获取计数，key不存在时返回0
                local minute_count = tonumber(redis.call("GET", minute_key) or "0")
                local hour_count = tonumber(redis.call("GET", hour_key) or "0")
                
                -- 检查是否还有可用容量（在INCR之前检查）
                if minute_count < minute_limit and hour_count < hour_limit then
                    -- 预留这个槽位
                    local new_minute_count = redis.call("INCR", minute_key)
                    local new_hour_count = redis.call("INCR", hour_key)
                    
                    -- 设置过期时间：槽位时间+1分钟/小时（只在新创建key时设置）
                    if new_minute_count == 1 then
                        -- 计算槽位时间+1分钟的秒数
                        local slot_year = math.floor(next_slot / 100000000)
                        local slot_month = math.floor((next_slot % 100000000) / 1000000)
                        local slot_day = math.floor((next_slot % 1000000) / 10000)
                        local slot_hour = math.floor((next_slot % 10000) / 100)
                        local slot_minute = next_slot % 100
                        
                        local slot_timestamp = os.time({
                            year = slot_year,
                            month = slot_month,
                            day = slot_day,
                            hour = slot_hour,
                            min = slot_minute,
                            sec = 0
                        }) + 60  -- +1分钟
                        
                        local current_time = os.time()
                        local ttl_minute = math.max(1, slot_timestamp - current_time)
                        redis.call("EXPIRE", minute_key, ttl_minute)
                    end
                    
                    if new_hour_count == 1 then
                        -- 计算槽位小时+1小时的秒数
                        local slot_hour_val = math.floor(next_slot / 100)
                        local hour_year = math.floor(slot_hour_val / 1000000)
                        local hour_month = math.floor((slot_hour_val % 1000000) / 10000)
                        local hour_day = math.floor((slot_hour_val % 10000) / 100)
                        local hour_hour = slot_hour_val % 100
                        
                        local hour_timestamp = os.time({
                            year = hour_year,
                            month = hour_month,
                            day = hour_day,
                            hour = hour_hour,
                            min = 0,
                            sec = 0
                        }) + 3600  -- +1小时
                        
                        local current_time = os.time()
                        local ttl_hour = math.max(1, hour_timestamp - current_time)
                        redis.call("EXPIRE", hour_key, ttl_hour)
                    end
                    
                    -- 只有当槽位满了才更新指针
                    if new_minute_count >= minute_limit or new_hour_count >= hour_limit then
                        redis.call("SETEX", next_slot_key, 3600, next_slot + 1)
                    end
                    
                    return next_slot
                end
                
                -- 当前槽位满了，尝试下一个槽位
                next_slot = next_slot + 1
                
                -- 防止无限循环，最多搜索120分钟
                if next_slot > search_start + 120 then
                    return search_start + 1
                end
            end
        ';
    }

    /**
     * 生成Redis key的辅助方法
     */
    protected function getCurrentMinuteKey(string $queueName, CarbonImmutable $now): string
    {
        return "rate_limit:{$queueName}:current_minute:" . $now->format('YmdHi');
    }

    protected function getCurrentHourKey(string $queueName, CarbonImmutable $now): string
    {
        return "rate_limit:{$queueName}:current_hour:" . $now->format('YmdH');
    }
}