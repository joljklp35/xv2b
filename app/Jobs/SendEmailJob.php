<?php

namespace App\Jobs;
use App\Jobs\Middleware\EmailRateLimit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use App\Models\MailLog;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $params;
    
    public $tries = 5;
    public $timeout = 30;

    public function retryUntil()
    {
        return now()->addHours(1);
    }

    protected $rateLimits = [
        'send_email' => [
            'per_minute' => 60,
            'per_hour' => 3600,
        ],
        'send_email_mass' => [
            'per_minute' => 60,
            'per_hour' => 3600,
        ],
    ];

    public function __construct(array $params, $queue = 'send_email')
    {
        $this->onQueue($queue);
        $this->params = $params;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function middleware()
    {
        return [new EmailRateLimit()];
    }

    public function handle()
    {
        $queueName = $this->queue ?? 'default';
        $limit = $this->rateLimits[$queueName] ?? null;

        if ($limit) {
            $now = now();
            $minuteKey = "rate_limit:{$queueName}:minute:" . $now->format('YmdHi');
            $hourKey = "rate_limit:{$queueName}:hour:" . $now->format('YmdH');

            $minuteCount = Redis::incr($minuteKey);
            if ($minuteCount === 1) {
                Redis::expire($minuteKey, 60);
            }

            $hourCount = Redis::incr($hourKey);
            if ($hourCount === 1) {
                Redis::expire($hourKey, 3600);
            }

            if ($minuteCount > $limit['per_minute'] || $hourCount > $limit['per_hour']) {
                $baseDelay = 5;
                $maxDelay = 600;

                $minuteOver = max(0, $minuteCount - $limit['per_minute']);
                $minuteDelay = ceil(($minuteOver / $limit['per_minute']) * $baseDelay);

                $hourOver = max(0, $hourCount - $limit['per_hour']);
                $hourDelay = ceil(($hourOver / $limit['per_hour']) * $baseDelay);

                $delay = max($minuteDelay, $hourDelay);
                $delay = min($delay, $maxDelay);

                $params = $this->params;

                Log::warning('队列限速触发', [
                    'queue' => $queueName,
                    'minute_count' => $minuteCount,
                    'hour_count' => $hourCount,
                    'minute_key' => $minuteKey,
                    'hour_key' => $hourKey,
                    'delay_seconds' => $delay,
                    'job' => self::class,
                    'email' => $params['email'] ?? null,
                    'subject' => $params['subject'] ?? null,
                    'template_name' => $params['template_name'] ?? null,
                    'error' => null, // 修复：原来这里未定义 $error
                    'config' => config('mail')
                ]);
                $this->release($delay);
                return;
            }
        }

        if (config('v2board.email_host')) {
            Config::set('mail.host', config('v2board.email_host', env('mail.host')));
            Config::set('mail.port', config('v2board.email_port', env('mail.port')));
            Config::set('mail.encryption', config('v2board.email_encryption', env('mail.encryption')));
            Config::set('mail.username', config('v2board.email_username', env('mail.username')));
            Config::set('mail.password', config('v2board.email_password', env('mail.password')));
            Config::set('mail.from.address', config('v2board.email_from_address', env('mail.from.address')));
            Config::set('mail.from.name', config('v2board.app_name', 'V2Board'));
        }

        $params = $this->params;
        $email = $params['email'];
        $subject = $params['subject'];
        $params['template_name'] = 'mail.' . config('v2board.email_template', 'default') . '.' . $params['template_name'];

        $error = null; // 初始化错误变量，避免未定义
        try {
            Mail::send(
                $params['template_name'],
                $params['template_value'],
                function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                }
            );
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }

        MailLog::create([
            'email' => $params['email'],
            'subject' => $params['subject'],
            'template_name' => $params['template_name'],
            'error' => $error,
        ]);

        return [
            'email' => $params['email'],
            'subject' => $params['subject'],
            'template_name' => $params['template_name'],
            'error' => $error,
            'config' => config('mail'),
        ];
    }
}
