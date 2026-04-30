<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\PaymentGatewayLog;
use Core\baseController\AdminBase;
use support\Request;
use support\Response;
use Throwable;

class PaymentController extends AdminBase
{
    /**
     * 支付网关错误日志列表
     *
     * @param Request $request 请求对象
     * @return Response JSON响应
     */
    public function errorLog(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 20);
        $sort   = $request->get('sort', 'created_at');
        $order  = $request->get('order', 'desc');
        $params = $request->only(['trade_no', 'gateway', 'method', 'error_message', 'created_at']);

        try {
            validate([
                'trade_no'      => 'max:24',
                'gateway'       => 'max:16',
                'method'        => 'max:16',
                'error_message' => 'max:255',
                'created_at'    => 'array'
            ], [
                'trade_no.max'      => '订单号不能超过24个字符',
                'gateway.max'       => '网关代码不能超过16个字符',
                'method.max'        => '调用方法不能超过16个字符',
                'error_message.max' => '错误信息关键词不能超过255个字符',
                'created_at.array'  => '时间范围格式不正确'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 检测要排序的字段是否在允许的字段列表中并检测排序顺序是否正确
        if (!in_array($sort, ['gateway', 'created_at']) || !in_array($order, ['asc', 'desc'])) {
            return $this->fail('排序失败，请刷新后重试');
        }

        // 构建查询
        $query = PaymentGatewayLog::when($params, function ($q) use ($params) {
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                switch ($key) {
                    case 'trade_no':
                        $q->where('trade_no', $value);
                        break;
                    case 'gateway':
                        $q->where('gateway', $value);
                        break;
                    case 'method':
                        $q->where('method', $value);
                        break;
                    case 'error_message':
                        $q->where('error_message', 'like', '%' . $value . '%');
                        break;
                    case 'created_at':
                        $q->whereBetween('created_at', [$value[0], $value[1]]);
                        break;
                }
            }
            return $q;
        });

        // 获取总数和数据
        $total = $query->count();
        $list  = $query->offset($from)->limit($limit)->orderBy($sort, $order)->get();

        return $this->success(data: [
            'list'  => $list,
            'total' => $total,
        ]);
    }
}
