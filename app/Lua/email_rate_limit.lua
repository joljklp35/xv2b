-- KEYS[1] = nextSlotKey
-- KEYS[2] = futureMinuteKey
-- KEYS[3] = futureHourKey
-- ARGV[1] = minuteLimit
-- ARGV[2] = hourLimit
-- ARGV[3] = currentSlot (YmdHi)
-- ARGV[4] = currentHour (YmdH)
-- ARGV[5] = ttlMinute
-- ARGV[6] = ttlHour

local nextSlotKey = KEYS[1]
local futureMinuteKey = KEYS[2]
local futureHourKey = KEYS[3]

local minuteLimit = tonumber(ARGV[1])
local hourLimit = tonumber(ARGV[2])
local currentSlot = tonumber(ARGV[3])
local currentHour = tonumber(ARGV[4])
local ttlMinute = tonumber(ARGV[5])
local ttlHour = tonumber(ARGV[6])

-- 增加分钟槽计数
local countMinute = redis.call("incr", futureMinuteKey)
if countMinute == 1 then
    redis.call("expire", futureMinuteKey, ttlMinute)
end

-- 增加小时槽计数
local countHour = redis.call("incr", futureHourKey)
if countHour == 1 then
    redis.call("expire", futureHourKey, ttlHour)
end

-- 判断是否超限
local next
if countMinute <= minuteLimit and countHour <= hourLimit then
    next = currentSlot
else
    next = redis.call("incr", nextSlotKey)
    if next < currentSlot then
        next = currentSlot
        redis.call("set", nextSlotKey, next)
    end
end

return next
