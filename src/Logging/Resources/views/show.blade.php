<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>日志详情</title>
<style>
  :root { --bd: #e5e7eb; --bg: #f8fafc; --fg: #0f172a; --muted: #64748b; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 14px/1.6 -apple-system, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif; color: var(--fg); background: var(--bg); }
  .wrap { max-width: 1000px; margin: 0 auto; padding: 24px; }
  h1 { font-size: 20px; margin: 0 0 16px; }
  a { color: #2563eb; text-decoration: none; }
  .back { display: inline-block; margin-bottom: 16px; }
  .card { background: #fff; border: 1px solid var(--bd); border-radius: 8px; padding: 16px; margin-bottom: 16px; }
  .field { display: flex; gap: 12px; padding: 6px 0; border-bottom: 1px dashed var(--bd); }
  .field:last-child { border-bottom: 0; }
  .k { width: 180px; color: var(--muted); flex: none; }
  .v { word-break: break-all; white-space: pre-wrap; }
  pre { background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 6px; overflow: auto; max-height: 420px; margin: 0; font: 12px/1.5 ui-monospace, Menlo, Consolas, monospace; }
  .lv { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; }
  .lv-DEBUG, .lv-INFO { background: #e0f2fe; color: #0369a1; }
  .lv-NOTICE, .lv-WARNING { background: #fef9c3; color: #854d0e; }
  .lv-ERROR, .lv-CRITICAL, .lv-ALERT, .lv-EMERGENCY { background: #fee2e2; color: #b91c1c; }
  h2 { font-size: 15px; margin: 0 0 8px; color: var(--muted); }
</style>
</head>
<body>
<div class="wrap">
  <a class="back" href="{{ route('logs.index') }}">← 返回列表</a>
  <h1>日志详情 #{{ $log->id }}</h1>

  <div class="card">
    <div class="field"><div class="k">等级</div><div class="v"><span class="lv lv-{{ $log->level_name }}">{{ $log->level_name }}</span> <span class="k">({{ $log->level }})</span></div></div>
    <div class="field"><div class="k">通道</div><div class="v">{{ $log->channel ?? '-' }}</div></div>
    <div class="field"><div class="k">时间</div><div class="v">{{ $log->created_at?->format('Y-m-d H:i:s') }}</div></div>
    <div class="field"><div class="k">消息</div><div class="v">{{ $log->message }}</div></div>
  </div>

  <div class="card">
    <h2>请求上下文</h2>
    <div class="field"><div class="k">方法 / 路径</div><div class="v">{{ $log->request_method }} {{ $log->request_path }}</div></div>
    <div class="field"><div class="k">URL</div><div class="v">{{ $log->request_url }}</div></div>
    <div class="field"><div class="k">路由</div><div class="v">{{ $log->request_route ?? '-' }}</div></div>
    <div class="field"><div class="k">控制器</div><div class="v">{{ $log->request_controller ?? '-' }}</div></div>
    <div class="field"><div class="k">IP</div><div class="v">{{ $log->request_ip }}</div></div>
    <div class="field"><div class="k">User-Agent</div><div class="v">{{ $log->request_user_agent }}</div></div>
    <div class="field"><div class="k">来源</div><div class="v">{{ $log->request_referer }}</div></div>
    <div class="field"><div class="k">会话</div><div class="v">{{ $log->request_session_id }}</div></div>
    <div class="field"><div class="k">用户</div><div class="v">@if($log->user_id){{ $log->user_type }} #{{ $log->user_id }}@else 未认证 @endif</div></div>
    <div class="field"><div class="k">请求入参</div><div class="v">{!! $log->request_input ? '<pre>'.e(json_encode($log->request_input, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)).'</pre>' : '-' !!}</div></div>
    <div class="field"><div class="k">请求头</div><div class="v">{!! $log->request_headers ? '<pre>'.e(json_encode($log->request_headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)).'</pre>' : '-' !!}</div></div>
  </div>

  <div class="card">
    <h2>响应结果</h2>
    <div class="field"><div class="k">状态码</div><div class="v">{{ $log->response_code ?? '-' }} {{ $log->response_status }}</div></div>
    <div class="field"><div class="k">耗时</div><div class="v">@if($log->response_duration_ms !== null){{ $log->response_duration_ms }} ms @else - @endif</div></div>
    <div class="field"><div class="k">峰值内存</div><div class="v">@if($log->response_memory_mb !== null){{ $log->response_memory_mb }} MB @else - @endif</div></div>
    <div class="field"><div class="k">响应头</div><div class="v">{!! $log->response_headers ? '<pre>'.e(json_encode($log->response_headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)).'</pre>' : '-' !!}</div></div>
    <div class="field"><div class="k">响应体</div><div class="v">{!! $log->response_body ? '<pre>'.e($log->response_body).'</pre>' : '-' !!}</div></div>
  </div>

  @if ($log->exception_class)
    <div class="card">
      <h2>异常</h2>
      <div class="field"><div class="k">类</div><div class="v">{{ $log->exception_class }}</div></div>
      <div class="field"><div class="k">消息</div><div class="v">{{ $log->exception_message }}</div></div>
      <div class="field"><div class="k">文件</div><div class="v">{{ $log->file }}</div></div>
      <div class="field"><div class="k">堆栈</div><div class="v"><pre>{{ $log->trace }}</pre></div></div>
    </div>
  @endif

  <div class="card">
    <h2>上下文 / 附加数据</h2>
    <div class="field"><div class="k">context</div><div class="v">{!! $log->context ? '<pre>'.e(json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)).'</pre>' : '-' !!}</div></div>
    <div class="field"><div class="k">extra</div><div class="v">{!! $log->extra ? '<pre>'.e(json_encode($log->extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)).'</pre>' : '-' !!}</div></div>
  </div>
</div>
</body>
</html>
