<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\MailLog;
use App\Models\MailTemplate;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailService
{
    // Render {{key}} / {{key|default}} placeholders.
    private static function renderPlaceholders(string $template, array $vars): string
    {
        if ($template === '' || empty($vars)) {
            return $template;
        }

        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)(?:\|([^}]*))?\s*\}\}/', function ($m) use ($vars) {
            $key = $m[1] ?? '';
            $default = array_key_exists(2, $m) ? trim((string) $m[2]) : null;

            if (!array_key_exists($key, $vars) || $vars[$key] === null || $vars[$key] === '') {
                return $default !== null ? $default : $m[0];
            }

            $value = $vars[$key];
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }
            if (is_scalar($value)) {
                return (string) $value;
            }

            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }, $template);
    }

    /**
     * 获取需要发送提醒的用户总数
     */
    public function getTotalUsersNeedRemind(): int
    {
        return User::where(function ($query) {
            $query->where('remind_expire', true)
                ->orWhere('remind_traffic', true);
        })
            ->where('banned', false)
            ->whereNotNull('email')
            ->count();
    }

    /**
     * 分块处理用户提醒邮件
     */
    public function processUsersInChunks(int $chunkSize, ?callable $progressCallback = null): array
    {
        $statistics = [
            'processed_users' => 0,
            'expire_emails' => 0,
            'traffic_emails' => 0,
            'errors' => 0,
            'skipped' => 0,
        ];

        User::select('id', 'email', 'expired_at', 'transfer_enable', 'u', 'd', 'remind_expire', 'remind_traffic')
            ->where(function ($query) {
                $query->where('remind_expire', true)
                    ->orWhere('remind_traffic', true);
            })
            ->where('banned', false)
            ->whereNotNull('email')
            ->chunk($chunkSize, function ($users) use (&$statistics, $progressCallback) {
                $this->processUserChunk($users, $statistics);

                if ($progressCallback) {
                    $progressCallback();
                }

                // 定期清理内存
                if ($statistics['processed_users'] % 2500 === 0) {
                    gc_collect_cycles();
                }
            });

        return $statistics;
    }

    /**
     * 处理用户块
     */
    private function processUserChunk($users, array &$statistics): void
    {
        foreach ($users as $user) {
            try {
                $statistics['processed_users']++;
                $emailsSent = 0;

                // 检查并发送过期提醒
                if ($user->remind_expire && $this->shouldSendExpireRemind($user)) {
                    $this->remindExpire($user);
                    $statistics['expire_emails']++;
                    $emailsSent++;
                }

                // 检查并发送流量提醒
                if ($user->remind_traffic && $this->shouldSendTrafficRemind($user)) {
                    $this->remindTraffic($user);
                    $statistics['traffic_emails']++;
                    $emailsSent++;
                }

                if ($emailsSent === 0) {
                    $statistics['skipped']++;
                }

            } catch (\Exception $e) {
                $statistics['errors']++;

                Log::error('发送提醒邮件失败', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * 检查是否应该发送过期提醒
     */
    private function shouldSendExpireRemind(User $user): bool
    {
        if ($user->expired_at === NULL) {
            return false;
        }
        $expiredAt = $user->expired_at;
        $now = time();
        if (($expiredAt - 86400) < $now && $expiredAt > $now) {
            return true;
        }
        return false;
    }

    /**
     * 检查是否应该发送流量提醒
     */
    private function shouldSendTrafficRemind(User $user): bool
    {
        if ($user->transfer_enable <= 0) {
            return false;
        }

        $usedBytes = $user->u + $user->d;
        $usageRatio = $usedBytes / $user->transfer_enable;

        // 流量使用超过80%时发送提醒
        return $usageRatio >= 0.8;
    }

    public function remindTraffic(User $user)
    {
        if (!$user->remind_traffic)
            return;
        if (!$this->remindTrafficIsWarnValue($user->u, $user->d, $user->transfer_enable))
            return;
        $flag = CacheKey::get('LAST_SEND_EMAIL_REMIND_TRAFFIC', $user->id);
        if (Cache::get($flag))
            return;
        if (!Cache::put($flag, 1, 24 * 3600))
            return;

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('The traffic usage in :app_name has reached 80%', [
                'app_name' => admin_setting('app_name', 'XBoard')
            ]),
            'template_name' => 'remindTraffic',
            'template_value' => [
                'name' => admin_setting('app_name', 'XBoard'),
                'url' => admin_setting('app_url')
            ]
        ]);
    }

    public function remindExpire(User $user)
    {
        if (!$this->shouldSendExpireRemind($user)) {
            return;
        }

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('The service in :app_name is about to expire', [
                'app_name' => admin_setting('app_name', 'XBoard')
            ]),
            'template_name' => 'remindExpire',
            'template_value' => [
                'name' => admin_setting('app_name', 'XBoard'),
                'url' => admin_setting('app_url')
            ]
        ]);
    }

    private function remindTrafficIsWarnValue($u, $d, $transfer_enable)
    {
        $ud = $u + $d;
        if (!$ud)
            return false;
        if (!$transfer_enable)
            return false;
        $percentage = ($ud / $transfer_enable) * 100;
        if ($percentage < 80)
            return false;
        if ($percentage >= 100)
            return false;
        return true;
    }

    /**
     * 发送邮件
     *
     * @param array $params 包含邮件参数的数组，必须包含以下字段：
     *   - email: 收件人邮箱地址
     *   - subject: 邮件主题
     *   - template_name: 邮件模板名称，例如 "welcome" 或 "password_reset"
     *   - template_value: 邮件模板变量，一个关联数组，包含模板中需要替换的变量和对应的值
     * @return array 包含邮件发送结果的数组，包含以下字段：
     *   - email: 收件人邮箱地址
     *   - subject: 邮件主题
     *   - template_name: 邮件模板名称
     *   - error: 如果邮件发送失败，包含错误信息；否则为 null
     * @throws \InvalidArgumentException 如果 $params 参数缺少必要的字段，抛出此异常
     */
    public static function sendEmail(array $params)
    {
        if (admin_setting('email_host')) {
            Config::set('mail.host', admin_setting('email_host', config('mail.host')));
            Config::set('mail.port', admin_setting('email_port', config('mail.port')));
            Config::set('mail.encryption', admin_setting('email_encryption', config('mail.encryption')));
            Config::set('mail.username', admin_setting('email_username', config('mail.username')));
            Config::set('mail.password', admin_setting('email_password', config('mail.password')));
            Config::set('mail.from.address', admin_setting('email_from_address', config('mail.from.address')));
            Config::set('mail.from.name', admin_setting('app_name', 'XBoard'));
        }
        $email = $params['email'];
        $subject = $params['subject'];
        $templateName = $params['template_name'];

	// --- 1. 判定語系邏輯 ---
        $isTraditional = false;
	if (!empty($params['from_tw_site'])) {
            $isTraditional = true;
        } else {
            $user = \App\Models\User::where('email', $email)->first();
            if ($user && in_array($user->remarks, ['TW', 'RAYFISH'])) {
                $isTraditional = true;
            }
        }

	// --- 2. 根據目錄結構映射模板名稱 ---
        // 如果是繁體，路徑變為 mail.default_tw.模板名 (例如 mail.default_tw.verify)
        // 如果是簡體，維持原樣 mail.default.模板名 (例如 mail.default.verify)
	$finalTemplate = $isTraditional ? "mail.default_tw.{$templateName}" : "mail.default.{$templateName}";

        // --- 3. 如果判定為繁體，強行翻譯主旨 (Subject) ---
        if ($isTraditional) {
            $subjectMap = [
		'邮箱验证码' => '認證碼',
		'邮件登录' => 'Email 認證登入',
		'您的服务即将到期' => '到期通知',
		'您的流量已使用 80%' => '流量預警',
            ];
            foreach ($subjectMap as $zh => $tw) {
                if (str_contains($subject, $zh)) {
                    $subject = str_replace($zh, $tw, $subject);
                }
            }
        }

	// --- 4. 寫入偵錯日誌 (清理掉不存在的變數) ---
        error_log("!! [MAIL_DEBUG] To: $email | Template: $finalTemplate | Subject: $subject | Result: " . ($isTraditional ? 'TW' : 'CN'));

        // --- 5. 執行發信 ---
	$error = null; // 必須先初始化，否則下面 Log 會報錯
	try {
            \Illuminate\Support\Facades\Mail::send(
                $finalTemplate,
                $params['template_value'] ?? $params,
                function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                }
            );
            error_log("!! [MAIL_SUCCESS] Sent Successfully");
        } catch (\Exception $e) {
            // 只有在這裡才能寫 $e->getMessage()，否則會導致 Job FAIL
            error_log("!! [MAIL_ERROR] Failed: " . $e->getMessage());
        }

	// --- 6. 記錄日誌 ---
        $log = [
            'email' => $email,
            'subject' => $subject,
            'template_name' => $finalTemplate, // 記錄你實際發出的那個模板名
            'error' => $error,
            'config' => [] // 建議先給空陣列，避免 config 內容過大導致資料庫寫入失敗
	];
        MailLog::create($log);
        return $log;
    }

    /**
     * Build HTML-escaped vars for DB template rendering.
     */
    private static function buildSafeVars(array $templateValue): array
    {
        $safe = [];
        foreach ($templateValue as $key => $value) {
            if (is_scalar($value)) {
                $safe[$key] = e((string) $value);
            }
        }
        // 'content' may be pre-escaped text or admin-authored HTML.
        // For text mode, apply nl2br so line breaks survive in DB templates
        // (Blade templates handle this with {!! nl2br($content) !!}).
        if (isset($templateValue['content'])) {
            $content = (string) $templateValue['content'];
            $contentMode = $templateValue['content_mode'] ?? null;
            $safe['content'] = ($contentMode === 'text') ? nl2br($content) : $content;
        }
        return $safe;
    }
}
