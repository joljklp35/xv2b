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
                $nextSlot = $this->getNextValidSlot($currentSlot);
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

    /**
     * 获取下一个有效的时间槽（处理分钟进位）
     */
    protected function getNextValidSlot(int $currentSlot): int
    {
        $year = intval(substr($currentSlot, 0, 4));
        $month = intval(substr($currentSlot, 4, 2));
        $day = intval(substr($currentSlot, 6, 2));
        $hour = intval(substr($currentSlot, 8, 2));
        $minute = intval(substr($currentSlot, 10, 2));

        // 创建当前时间对象并加1分钟
        $currentTime = CarbonImmutable::createFromFormat('Y-m-d H:i:s', 
            sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute)
        );
        
        $nextTime = $currentTime->addMinute();
        return intval($nextTime->format('YmdHi'));
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
            return $this->getNextValidSlot($currentSlot);
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
            
            -- 辅助函数：获取下一个有效时间槽（处理分钟进位）
            local function get_next_valid_slot(slot)
                local year = math.floor(slot / 100000000)
                local month = math.floor((slot % 100000000) / 1000000)
                local day = math.floor((slot % 1000000) / 10000)
                local hour = math.floor((slot % 10000) / 100)
                local minute = slot % 100
                
                -- 分钟+1
                minute = minute + 1
                if minute >= 60 then
                    minute = 0
                    hour = hour + 1
                    if hour >= 24 then
                        hour = 0
                        day = day + 1
                        -- 这里简化处理，实际应该考虑月份天数
                        if day > 31 then
                            day = 1
                            month = month + 1
                            if month > 12 then
                                month = 1
                                year = year + 1
                            end
                        end
                    end
                end
                
                return year * 100000000 + month * 1000000 + day * 10000 + hour * 100 + minute
            end
            
            -- 辅助函数：计算时间戳
            local function calculate_timestamp(slot)
                local year = math.floor(slot / 100000000)
                local month = math.floor((slot % 100000000) / 1000000)
                local day = math.floor((slot % 1000000) / 10000)
                local hour = math.floor((slot % 10000) / 100)
                local minute = slot % 100
                
                return os.time({
                    year = year,
                    month = month,
                    day = day,
                    hour = hour,
                    min = minute,
                    sec = 0
                })
            end
            
            -- 获取或初始化下一个可用槽位
            local next_slot = redis.call("GET", next_slot_key)
            if not next_slot then
                next_slot = get_next_valid_slot(current_slot)
            else
                next_slot = tonumber(next_slot)
            end
            
            -- 确保不会分配过去的时间槽
            if next_slot <= current_slot then
                next_slot = get_next_valid_slot(current_slot)
            end
            
            -- 搜索下一个可用槽位
            local search_start = next_slot
            local search_count = 0
            while search_count < 120 do  -- 最多搜索120分钟
                local minute_key = future_minute_base .. next_slot
                local hour_key = future_hour_base .. math.floor(next_slot / 100)
                
                -- 获取计数，key不存在时返回0
                local minute_count = tonumber(redis.call("GET", minute_key) or "0")
                local hour_count = tonumber(redis.call("GET", hour_key) or "0")
                
                -- 检查是否还有可用容量
                if minute_count < minute_limit and hour_count < hour_limit then
                    -- 预留这个槽位
                    local new_minute_count = redis.call("INCR", minute_key)
                    local new_hour_count = redis.call("INCR", hour_key)
                    
                    -- 设置过期时间（修复：始终设置过期时间）
                    local slot_timestamp = calculate_timestamp(next_slot)
                    local current_time = os.time()
                    
                    -- 分钟key过期时间：槽位时间+2分钟
                    local ttl_minute = math.max(120, slot_timestamp + 120 - current_time)
                    redis.call("EXPIRE", minute_key, ttl_minute)
                    
                    -- 小时key过期时间：槽位小时+2小时
                    local hour_slot = math.floor(next_slot / 100)
                    local hour_timestamp = calculate_timestamp(hour_slot * 100)
                    local ttl_hour = math.max(7200, hour_timestamp + 7200 - current_time)
                    redis.call("EXPIRE", hour_key, ttl_hour)
                    
                    -- 只有当槽位满了才更新指针
                    if new_minute_count >= minute_limit or new_hour_count >= hour_limit then
                        local next_pointer = get_next_valid_slot(next_slot)
                        redis.call("SETEX", next_slot_key, 3600, next_pointer)
                    end
                    
                    return next_slot
                end
                
                -- 当前槽位满了，尝试下一个槽位
                next_slot = get_next_valid_slot(next_slot)
                search_count = search_count + 1
            end
            
            -- 如果搜索超时，返回一个未来的槽位
            return get_next_valid_slot(current_slot)
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