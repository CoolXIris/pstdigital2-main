@php($isReminder = $noticeType === 'reminder')
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:28px 12px;background:#f2f5f9;font-family:Arial,Helvetica,sans-serif;color:#26384f">
    <table role="presentation" style="width:100%;max-width:620px;margin:0 auto;border-collapse:collapse;background:#fff;border:1px solid #dce5ee;border-radius:12px;overflow:hidden">
        <tr><td style="padding:24px 30px;background:#073b78;color:#fff;font-size:15px;font-weight:700">PST DIGITAL - BPS SUMATERA SELATAN</td></tr>
        <tr><td style="padding:30px">
            <p style="margin:0 0 10px;color:#536a81">{{ $greeting }}</p>
            <h1 style="margin:0 0 14px;font-size:23px;line-height:1.3;color:#173b63">{{ $heading }}</h1>
            <p style="margin:0 0 22px;line-height:1.7;color:#536a81">{{ $bodyText }}</p>
            <table role="presentation" style="width:100%;margin-bottom:24px;border-collapse:collapse;background:#f6f9fc;border:1px solid #e1e9f1;border-radius:8px">
                <tr><td style="padding:11px 14px;color:#718096;width:130px">Topik</td><td style="padding:11px 14px;font-weight:600">{{ $topic }}</td></tr>
                <tr><td style="padding:11px 14px;color:#718096">Tanggal</td><td style="padding:11px 14px">{{ $scheduleDate }}</td></tr>
                <tr><td style="padding:11px 14px;color:#718096">Waktu</td><td style="padding:11px 14px">{{ $scheduleTime }}</td></tr>
                <tr><td style="padding:11px 14px;color:#718096">Petugas</td><td style="padding:11px 14px">{{ $staffName }}</td></tr>
            </table>
            <p style="margin:0 0 24px"><a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 20px;background:#0a579e;border-radius:6px;color:#fff;text-decoration:none;font-weight:700">{{ $buttonLabel }}</a></p>
            @if ($isReminder)
        <tr><td style="padding:24px 30px;background:#073b78;color:#fff;font-size:15px;font-weight:700">PST DIGITAL - BPS SUMATERA SELATAN</td></tr>
            @endif
        </td></tr>
        <tr><td style="padding:24px 30px;background:#073b78;color:#fff;font-size:15px;font-weight:700">PST DIGITAL - BPS SUMATERA SELATAN</td></tr>
    </table>
</body>
</html>
