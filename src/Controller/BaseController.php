<?php

namespace zxf\Modules\Controller;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\Access\Gate as AuthGate;
use zxf\Modules\Controller\Trait\ControllerTrait;

/**
 * 扩展基础控制器
 *  继承了此类的控制器，可以在构造函数执行之后，在被调用方法之前，执行初始化方法initialize，initialize方法支持依赖注入
 */
class BaseController extends Controller
{
    use ControllerTrait;

    /**
     * 策略判断(默认使用User模型) 例如： $this->gate::authorize('update', $photo);
     * 设置指定模型的用户判断：$this->gate::forUser(auth('admin')->user())->authorize('update', $article);
     */
    protected string|null|Gate|AuthGate $gate = null;

    /**
     * Request 实例（在中间件闭包中赋值，确保已通过 auth 等中间件处理）
     */
    protected Request $request;

    public function __construct()
    {
        // 添加一个最后执行的中间件，此时其他中间件（包括 auth）已经加载完毕
        $this->middleware(function ($request, $next) {
            // 在中间件管道中赋值 $this->request，此时 auth 中间件已执行
            // 确保 $this->request->user() 能获取到已认证用户信息
            $this->request = $request;

            // 中间件基本加载完毕
            $this->initHandle($request);
            // 在路由调用方法之前，先调用初始化方法initialize
            // 给控制器新增 initialize 生命周期方法，可用于初始化
            // 甚至可以代替 构造函数，实现依赖注入
            after_class_calling($this, 'initialize');
            // 添加第二个前置方法
            after_class_calling($this, 'before');

            return $next($request);
        });
    }

    // 初始化
    protected function initHandle(Request $request): void
    {
        // 初始化策略类
        $this->gate = Gate::class;
    }
}
