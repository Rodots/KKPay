<?php

declare(strict_types = 1);

namespace App\api\controller;

use App\model\User;
use support\Request;

class PayController
{
    public function submit(Request $request)
    {
        $param = $request->only([
            'pid', 'type', 'out_trade_no', 'notify_url', 'return_url', 'name', 'money', 'param', 'timestamp', 'sign', 'sign_type'
        ]);
        $pid   = $request->input('pid');
        if (empty($pid)) {
            return '商户ID不能为空';
        }
        if (!$user = User::find($pid)) {
            return '商户不存在';
        }
        if ($user->status === 0) {
            return '商户已被禁用，无法支付';
        }
        if ($user->competence['pay'] === '0') {
            return '商户未开通支付功能';
        }

        $type         = filter($request->input('type'));
        $out_trade_no = filter($request->input('out_trade_no'));
        $notify_url   = htmlspecialchars($request->input('notify_url'));
        $return_url   = htmlspecialchars($request->input('return_url'));
        $name         = filter($request->input('name'));
        $money        = (float)$request->input('money');
        $param        = filter($request->input('param'));
        $timestamp    = (int)$request->input('timestamp');
        $sign         = $request->input('sign');
        $sign_type    = filter($request->input('sign_type'));

        if (empty($out_trade_no)) {
            return '商户订单号(out_trade_no)不能为空';
        }
        if (empty($notify_url)) {
            return '异步通知地址(notify_url)不能为空';
        }
        if (empty($return_url)) {
            return '同步通知地址(return_url)不能为空';
        }
        if (empty($name)) {
            return '商品名称(name)不能为空';
        }
        if ($money <= 0) {
            return '商品金额(money)必须大于0';
        }

        return 'submit';
    }

    public function create(Request $request)
    {
        $param = $request->only([
            'pid', 'method', 'device', 'type', 'out_trade_no', 'notify_url', 'return_url', 'name', 'money', 'clientip', 'param', 'auth_code', 'sub_openid', 'sub_appid', 'timestamp', 'sign', 'sign_type'
        ]);

        return 'create';
    }

    public function query(Request $request)
    {
        $param = $request->only([
            'pid', 'trade_no', 'out_trade_no', 'timestamp', 'sign', 'sign_type'
        ]);

        return 'create';
    }
}
