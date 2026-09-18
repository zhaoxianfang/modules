<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>数据库日志</title>
<style>
  :root { --bd: #e5e7eb; --bg: #f8fafc; --fg: #0f172a; --muted: #64748b; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 14px/1.5 -apple-system, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif; color: var(--fg); background: var(--bg); }
  .wrap { max-width: 1180px; margin: 0 auto; padding: 24px; }
  h1 { font-size: 20px; margin: 0 0 16px; }
  .toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 16px; }
  .toolbar input, .toolbar select { padding: 6px 10px; border: 1px solid var(--bd); border-radius: 6px; }
  .toolbar button { padding: 6px 14px; border: 1px solid var(--bd); border-radius: 6px; background: #fff; cursor: pointer; }
  .toolbar .clean { margin-left: auto; color: #b91c1c; border-color: #fca5a5; }
  .alert { padding: 8px 12px; background: #ecfdf5; border: 1px solid #6ee7b7; border-radius: 6px; margin-bottom: 12px; color: #065f46; }
  table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid var(--bd); border-radius: 8px; overflow: hidden; }
  th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--bd); vertical-align: top; }
  th { background: #f1f5f9; font-weight: 600; }
  tr:hover td { background: #f8fafc; }
  .lv { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; }
  .lv-DEBUG, .lv-INFO { background: #e0f2fe; color: #0369a1; }
  .lv-NOTICE, .lv-WARNING { background: #fef9c3; color: #854d0e; }
  .lv-ERROR, .lv-CRITICAL, .lv-ALERT, .lv-EMERGENCY { background: #fee2e2; color: #b91c1c; }
  .msg { max-width: 420px; word-break: break-all; }
  a { color: #2563eb; text-decoration: none; }
  a:hover { text-decoration: underline; }
  .meta { color: var(--muted); font-size: 12px; }
  .pager { margin-top: 16px; }
  .empty { padding: 40px; text-align: center; color: var(--muted); }
</style>
</head>
<body>
<div class="wrap">
  <h1>数据库日志（system_logs）</h1>

  @if (session('status'))
    <div class="alert">{{ session('status') }}</div>
  @endif

  <form class="toolbar" method="get">
    <input type="text" name="q" value="{{ request('q') }}" placeholder="搜索 消息 / URL / 异常类" style="min-width:240px">
    <select name="level">
      <option value="">全部等级</option>
      @foreach ($levels as $l)
        <option value="{{ $l }}" @selected(request('level') === $l)>{{ $l }}</option>
      @endforeach
    </select>
    <select name="channel">
      <option value="">全部通道</option>
      @foreach ($channels as $c)
        <option value="{{ $c }}" @selected(request('channel') === $c)>{{ $c }}</option>
      @endforeach
    </select>
    <button type="submit">筛选</button>
  </form>
  <form class="toolbar" method="post" action="{{ route('logs.clean') }}" onsubmit="return confirm('确认清理 30 天前的日志？');">
    @csrf
    <button type="submit" class="clean">清理过期日志</button>
  </form>

  @if ($logs->isEmpty())
    <div class="empty">暂无日志记录。</div>
  @else
    <table>
      <thead>
        <tr>
          <th style="width:90px">等级</th>
          <th style="width:160px">时间</th>
          <th>消息</th>
          <th style="width:120px">请求</th>
          <th style="width:80px">状态码</th>
          <th style="width:70px">操作</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($logs as $log)
          <tr>
            <td><span class="lv lv-{{ $log->level_name }}">{{ $log->level_name }}</span></td>
            <td class="meta">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
            <td class="msg">{{ Str::limit($log->message, 200) }}</td>
            <td class="meta">{{ $log->request_method }}<br>{{ $log->request_path }}</td>
            <td>{{ $log->response_code ?? '-' }}</td>
            <td>
              <a href="{{ route('logs.show', $log->id) }}">详情</a>
              <form method="post" action="{{ route('logs.destroy', $log->id) }}" onsubmit="return confirm('删除该条？');" style="display:inline">
                @csrf @method('DELETE')
                <button type="submit" style="border:0;background:none;color:#b91c1c;cursor:pointer;padding:0">删除</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>

    <div class="pager">{{ $logs->links() }}</div>
  @endif
</div>
</body>
</html>
