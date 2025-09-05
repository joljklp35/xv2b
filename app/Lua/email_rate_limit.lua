-- KEYS[1] = nextSlotKey
-- KEYS[2] = futureMinuteBaseKey
-- KEYS[3] = futureHourBaseKey
-- ARGV[1] = minuteLimit
-- ARGV[2] = hourLimit
-- ARGV[3] = currentSlot (YmdHi)
-- ARGV[4] = currentHour (YmdH)
-- ARGV[5] = ttlMinute
-- ARGV[6] = ttlHour

local nextSlotKey = KEYS[1]
local futureMinuteBaseKey = KEYS[2]
local futureHourBaseKey = KEYS[3]

local minuteLimit = tonumber(ARGV[1])
local hourLimit = tonumber(ARGV[2])
local currentSlot = tonumber(ARGV[3])
local currentHour = tonumber(ARGV[4])
local ttlMinute = tonumber(ARGV[5])
local ttlHour = tonumber(ARGV[6])

-- 获取下一个可用 slot
local nextSlot = redis.call("get", nextSlotKey)
if not nextSlot then
    nextSlot = currentSlot
else
    nextSlot = tonumber(nextSlot)
    if nextSlot < currentSlot then
        nextSlot = currentSlot
    end
end

-- 循环查找可用 slot
while true do
    local futureMinuteKey = futureMinuteBaseKey .. nextSlot
    local futureHourKey = futureHourBaseKey .. string.sub(tostring(nextSlot), 1, 10) -- YmdH

    local countMinute = redis.call("get", futureMinuteKey)
    if not countMinute then countMinute = 0 else countMinute = tonumber(countMinute) end

    local countHour = redis.call("get", futureHourKey)
    if not countHour then countHour = 0 else countHour = tonumber(countHour) end

    if countMinute < minuteLimit and countHour < hourLimit then
        -- 分配当前 slot
        redis.call("incr", futureMinuteKey)
        redis.call("expire", futureMinuteKey, ttlMinute)

        redis.call("incr", futureHourKey)
        redis.call("expire", futureHourKey, ttlHour)

        redis.call("set", nextSlotKey, nextSlot)
        return nextSlot
    else
        -- 下一个 slot
        nextSlot = nextSlot + 1
    end
end
