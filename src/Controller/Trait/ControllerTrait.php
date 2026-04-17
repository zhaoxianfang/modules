<?php

namespace zxf\Modules\Controller\Trait;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Validator;
use Throwable;

trait ControllerTrait
{
    /**
     * 返回上一页, 并带上错误信息
     *
     * @param  mixed  $errors  错误信息「字符串、数组、Exception」
     */
    public function backWithError(mixed $errors = '出错啦!'): RedirectResponse
    {
        return redirect()->back()->withInput()->withErrors($errors);
    }

    /**
     * 返回上一页, 并带上提示信息
     *
     * @param  string  $info  提示信息「字符串」
     * @return RedirectResponse
     */
    public function backWithSuccess(string $info = ''): RedirectResponse
    {
        return redirect()->back()->withInput()->with(['success' => $info]);
    }

    public function json(array $data = [], int $status = 200, string $jumpUrl = '', $wait = 3): JsonResponse
    {
        $data['code'] = empty($data['code']) ? $status : $data['code'];
        $data['message'] = empty($data['message']) ? '操作成功' : $data['message'];

        if (! empty($jumpUrl)) {
            $data['url'] = $jumpUrl;
            $data['wait'] = $wait; // 单位秒
        }

        return response()->json($data, $status);
    }

    public function api_json($data = [], $code = 200, $message = '成功', $status = 200): \Illuminate\Http\JsonResponse
    {
        return $this->json(compact('code', 'message', 'data'), $status);
    }

    /**
     * DataTables 渲染数据
     *
     * @param  array  $list  数据列表
     * @param  int  $total  数据总条数
     * @param  string  $errorMsg  错误信息
     */
    public function dataTables(array $list = [], int $total = 0, string $errorMsg = ''): JsonResponse
    {
        // draw 相当于是 datatables 插件需要展示的页码编号，[相当重要][必须有]
        $draw = (int) $this->request->input('draw', 1);
        if ($errorMsg) {
            return $this->json(['rows' => $list, 'total' => $total, 'draw' => $draw, 'error' => $errorMsg]);
        }

        // DataTables 渲染数据放在 data 或 list 或 rows 里面
        return $this->json(['rows' => $list, 'total' => $total, 'draw' => $draw]);
        // return $this->json([
        //     'list'            => $list, // 数据列表
        //     'recordsTotal'    => $total,// 数据总条数
        //     "draw"            => $draw, // (int)响应计数器
        //     "recordsFiltered" => $total, // (int)筛选后的总记录数
        //     'error'           => $errorMsg, // 注意：仅有错误信息时才返回error字段，请不要返回此字段
        // ]);
    }

    public function success(string|array $resp = '', string $jumpUrl = '')
    {
        $request = request();
        $data = ['code' => 200, 'message' => '操作成功'];
        if (is_string($resp)) {
            $data['message'] = $resp;
        } elseif (is_array($resp)) {
            if (! isset($resp['code']) && ! isset($resp['message'])) {
                $data['data'] = $resp;
            } else {
                $data = array_merge($data, $resp);
            }
        }
        if ($request->ajax()) {
            // is_layer 是否为弹出层
            if (! empty($request->input('is_layer', ''))) {
                $jumpUrl = '';
            }

            return $this->json($data, 200, $jumpUrl);
        }
        // 不是来自本站，没有上一个页面，直接返回提示页面
        if (! source_local_website('status')) {
            return $request->view('modules::error', [
                'title' => '提示',
                'message' => $data['message'],
            ]);
        }

        return $this->backWithSuccess($data['message']);
    }

    public function error(string|array|Validator|Throwable $resp = '', string $jumpUrl = '')
    {
        if($resp instanceof Validator){
            $resp = $resp->errors()->first();
        }
        if($resp instanceof Throwable){
            $resp = $resp->getMessage();
        }
        $request = request();
        $data = ['code' => 500, 'message' => '操作失败'];
        if (is_string($resp)) {
            $data['message'] = $resp;
        } elseif (is_array($resp)) {
            if (! isset($resp['code']) && ! isset($resp['message'])) {
                $data['data'] = $resp;
            } else {
                $data = array_merge($data, $resp);
            }
        }
        if ($request->ajax()) {
            // is_layer 是否为弹出层
            if (! empty($request->input('is_layer', ''))) {
                $jumpUrl = '';
            }
            return $this->json($data, 500, $jumpUrl);
        }
        // 不是来自本站，没有上一个页面，直接返回错误页面
        if (! source_local_website('status')) {
            return $request->view('modules::error', [
                'title' => '出错啦',
                'message' => $data['message'],
            ]);
        }

        // 来自本站，有上一个页面，携错返回上一个页面
        return $this->backWithError($data['message']);
    }

}
