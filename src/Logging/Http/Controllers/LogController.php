<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use zxf\Modules\Logging\Models\SystemLog;

/**
 * 数据库日志查看器控制器（友好的后台检索界面）。
 *
 * 路由前缀与中间件可在 config('logging.db.*') 中配置。建议在生产环境为其追加
 * 认证中间件（如 'auth' / 'can:view-logs'），默认仅挂载 'web'。
 *
 * @package zxf\Modules\Logging
 */
class LogController extends Controller
{
    public function index(Request $request)
    {
        $q = SystemLog::query()->latest();

        if ($lv = (string) $request->get('level')) {
            $q->where('level_name', strtoupper($lv));
        }
        if ($channel = (string) $request->get('channel')) {
            $q->where('channel', $channel);
        }
        if ($kw = (string) $request->get('q')) {
            $q->where(function ($query) use ($kw) {
                $query->where('message', 'like', "%{$kw}%")
                    ->orWhere('request_url', 'like', "%{$kw}%")
                    ->orWhere('request_path', 'like', "%{$kw}%")
                    ->orWhere('exception_class', 'like', "%{$kw}%");
            });
        }

        $logs = $q->paginate(50)->withQueryString();
        $levels = SystemLog::query()->distinct()->pluck('level_name')->filter()->values();
        $channels = SystemLog::query()->distinct()->pluck('channel')->filter()->values();

        return view('modules-logging::logs', compact('logs', 'levels', 'channels'));
    }

    public function show($id)
    {
        $log = SystemLog::findOrFail($id);

        return view('modules-logging::show', compact('log'));
    }

    public function destroy($id)
    {
        SystemLog::where('id', $id)->delete();

        return redirect()->route('logs.index')->with('status', '已删除该条日志');
    }

    public function clean()
    {
        $days = (int) config('logging.db.retention_days', 30);
        $deleted = SystemLog::where('created_at', '<', now()->subDays($days))->delete();

        return redirect()->route('logs.index')->with('status', "已清理 {$deleted} 条过期日志");
    }
}
