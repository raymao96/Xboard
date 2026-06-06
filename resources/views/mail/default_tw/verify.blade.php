<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>認證碼</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;padding:40px 20px;">
<tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;">
    <!-- Logo -->
    <tr><td style="padding-bottom:24px;text-align:center;">
        <span style="font-size:20px;font-weight:700;color:#18181b;">{{$name}}</span>
    </td></tr>
    <!-- Card -->
    <tr><td style="background:#ffffff;border-radius:12px;border:1px solid #e4e4e7;padding:40px;">
        <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td style="font-size:22px;font-weight:700;color:#18181b;padding-bottom:8px;">認證碼</td></tr>
            <tr><td style="font-size:15px;color:#52525b;line-height:1.6;padding-bottom:28px;">您剛剛索取的 {{$name}} 認證碼如下，請在 5 分鐘內返回相應頁面填寫。</td></tr>
            <tr><td align="center" style="padding-bottom:28px;">
                <div style="display:inline-block;background:#f4f4f5;border:1px solid #e4e4e7;border-radius:8px;padding:16px 40px;font-size:32px;font-weight:700;letter-spacing:6px;color:#18181b;font-family:'Courier New',Courier,monospace;">{{$code}}</div>
            </td></tr>
        </table>
    </td></tr>
    <!-- Footer -->
    <tr><td style="padding-top:24px;text-align:center;">
        <p style="font-size:12px;color:#d4d4d8;margin:8px 0 0;">（此信件為系統自動傳送，請勿回覆）</p>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
