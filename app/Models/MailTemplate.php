<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailTemplate extends Model
{
    protected $table = 'v2_mail_templates';

    protected $fillable = ['name', 'subject', 'content'];

    /**
     * Template definitions: required/optional vars and default content.
     */
    public const TEMPLATES = [
        'verify' => [
            'label' => '邮箱验证码',
            'required_vars' => ['code'],
            'optional_vars' => ['name', 'url'],
        ],
        'notify' => [
            'label' => '站点通知',
            'required_vars' => ['content'],
            'optional_vars' => ['name', 'url'],
        ],
        'remindExpire' => [
            'label' => '到期提醒',
            'required_vars' => [],
            'optional_vars' => ['name', 'url'],
        ],
        'remindTraffic' => [
            'label' => '流量提醒',
            'required_vars' => [],
            'optional_vars' => ['name', 'url'],
        ],
        'mailLogin' => [
            'label' => '邮件登录',
            'required_vars' => ['link'],
            'optional_vars' => ['name', 'url'],
        ],
    ];

    /**
     * 根據用戶或當前環境渲染對應語言的內容
     */
    public function renderContent($user = null)
    {
        $content = $this->content;
        $subject = $this->subject;

        // 1. 決定當前語系
        $isTraditional = false;
	//$appUrl = config('v2board.app_url');
	$appUrl = 'https://tw.getrapps.org';
        
        // 取得當前訪問的 Host，如果是從命令行（如定時任務）執行，則預設為 null
        $currentHost = request() ? request()->getHost() : null;

	$isTraditional = true;

        if ($user && isset($user->remarks)) {
            // 已註冊用戶：根據備註判斷 (TW 或 CN)
            $isTraditional = in_array($user->remarks, ['TW', 'RAYFISH']);
        } else if ($currentHost) {
            // 未註冊用戶（如註冊驗證碼）：根據域名判斷
            // 依照你的邏輯：當前的 Host 等於 app_url 裡面的 Host 時為 TW
            $isTraditional = ($currentHost === parse_url($appUrl, PHP_URL_HOST));
        }

        // 2. 如果判定為繁體環境，進行轉換
        if ($isTraditional) {
            return [
                'subject' => $this->convertToTraditional($subject),
                'content' => $this->convertToTraditional($content)
            ];
        }

        // 預設返回（簡體）
        return [
            'subject' => $subject,
            'content' => $content
        ];
    }

    /**
     * 繁簡轉換邏輯
     */
    protected function convertToTraditional($text)
    {
        if (!$text) return '';

        // 擴充對照表
        $map = [
            '邮箱登陆' => 'Email 認證登入',
            '您正在登入到{{$name}}, 请在 5 分钟内点击下方链接进行登入。如果您未授权该登入请求，请无视。' => '您正在登入 {{$name}} 。請在 5 分鐘內點按下方連結以授權登入，如果您未授權此登入，請忽略此信件。',
            '网站通知' => 'News',
            '本邮件由系统自动发出，请勿直接回复' => '此信件為系統自動傳送，請勿回覆',
            '到期提示' => '到期通知',
            '您的订阅套餐将于' => '您的訂閱計劃將於',
            '小时后到期，请及时续费' => '小時後到期，請及時續訂。',
            '流量提示' => '流量預警',
            '您本月的套餐流量已使用' => '您的本月流量已消耗',
            '请合理安排使用，避免提前耗尽' => '請合理評估用量。您可以等待每月免費固定重置，也可以付費提前重置，根據您的訂閱計劃，重置價格可能有所不同。',
            '邮箱验证码' => '認證碼',
            '请填写以下验证码完成邮箱验证 (5分钟内有效)' => '您剛剛索取的認證碼如下，請在 5 分鐘內返回相應頁面填寫。',
        ];

        return str_replace(array_keys($map), array_values($map), $text);
    }
    
    /**
     * Get template metadata (vars, label) for a given template name.
     */
    public static function getMeta(string $name): ?array
    {
        return self::TEMPLATES[$name] ?? null;
    }

    /**
     * Get all template names.
     */
    public static function getNames(): array
    {
        return array_keys(self::TEMPLATES);
    }

    /**
     * Validate that required placeholders are present in the content.
     */
    public static function validateContent(string $name, string $content): array
    {
        $meta = self::getMeta($name);
        if (!$meta) {
            return ["Unknown template: {$name}"];
        }

        $errors = [];
        foreach ($meta['required_vars'] as $var) {
            if (strpos($content, '{{' . $var . '}}') === false) {
                $errors[] = "缺少必要占位符: {{{$var}}}";
            }
        }
        return $errors;
    }
}
