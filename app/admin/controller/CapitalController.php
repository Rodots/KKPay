<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\Merchant;
use app\model\MerchantWalletPrepaidRecord;
use app\model\MerchantWalletRecord;
use app\model\MerchantWithdrawalRecord;
use Core\baseController\AdminBase;
use Core\Service\MerchantWithdrawalService;
use SodiumException;
use support\Db;
use support\Request;
use support\Response;
use support\Rodots\Crypto\XChaCha20;
use Throwable;

class CapitalController extends AdminBase
{
    /**
     * 商户余额变动记录
     *
     * @param Request $request
     * @return Response
     */
    public function walletRecord(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 10);
        $sort   = $request->get('sort', 'id');
        $order  = $request->get('order', 'desc');
        $params = $request->only(['merchant_number', 'type', 'remark', 'trade_no', 'created_at']);

        try {
            validate([
                'merchant_number' => ['startWith:M', 'alphaNum', 'length:16'],
                'type'            => ['max:32'],
                'remark'          => ['max:255'],
                'trade_no'        => ['startWith:P', 'alphaNum', 'length:24'],
                'created_at'      => ['array']
            ], [
                'merchant_number.startWith' => '商户编号是以M开头的16位数字+英文组合',
                'merchant_number.alphaNum'  => '商户编号是以M开头的16位数字+英文组合',
                'merchant_number.length'    => '商户编号是以M开头的16位数字+英文组合',
                'type.max'                  => '操作类型不能超过32个字符',
                'remark.max'                => '备注不能超过255个字符',
                'trade_no.startWith'        => '平台订单号是以P开头的24位数字+英文组合',
                'trade_no.alphaNum'         => '平台订单号是以P开头的24位数字+英文组合',
                'trade_no.length'           => '平台订单号是以P开头的24位数字+英文组合',
                'created_at.array'          => '请重新选择时间范围'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 检测要排序的字段是否在允许的字段列表中并检测排序顺序是否正确
        if (!in_array($sort, ['id', 'type', 'trade_no']) || !in_array($order, ['asc', 'desc'])) {
            return $this->fail('排序失败，请刷新后重试');
        }

        // 构建查询
        $query = MerchantWalletRecord::whereHas('merchant', fn($q) => $q->whereNull('deleted_at'))->with(['merchant:id,merchant_number'])->when($params, function ($q) use ($params) {
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                switch ($key) {
                    case 'merchant_number':
                        $q->where('merchant_id', Merchant::where('merchant_number', $value)->value('id'));
                        break;
                    case 'type':
                        $q->where('type', $value);
                        break;
                    case 'remark':
                        $q->where('remark', 'like', '%' . $value . '%');
                        break;
                    case 'trade_no':
                        $q->where('trade_no', $value);
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

    /**
     * 商户预付金变动记录
     *
     * @param Request $request
     * @return Response
     */
    public function walletPrepaidRecord(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 10);
        $sort   = $request->get('sort', 'id');
        $order  = $request->get('order', 'desc');
        $params = $request->only(['merchant_number', 'remark', 'created_at']);

        try {
            validate([
                'merchant_number' => ['startWith:M', 'alphaNum', 'length:16'],
                'remark'          => ['max:255'],
                'created_at'      => ['array']
            ], [
                'merchant_number.startWith' => '商户编号是以M开头的16位数字+英文组合',
                'merchant_number.alphaNum'  => '商户编号是以M开头的16位数字+英文组合',
                'merchant_number.length'    => '商户编号是以M开头的16位数字+英文组合',
                'remark.max'                => '备注不能超过255个字符',
                'created_at.array'          => '请重新选择时间范围'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 检测要排序的字段是否在允许的字段列表中并检测排序顺序是否正确
        if ($sort !== 'id' || !in_array($order, ['asc', 'desc'])) {
            return $this->fail('排序失败，请刷新后重试');
        }

        // 构建查询
        $query = MerchantWalletPrepaidRecord::whereHas('merchant', fn($q) => $q->whereNull('deleted_at'))->with(['merchant:id,merchant_number'])->when($params, function ($q) use ($params) {
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                switch ($key) {
                    case 'merchant_number':
                        $q->where('merchant_id', Merchant::where('merchant_number', $value)->value('id'));
                        break;
                    case 'remark':
                        $q->where('remark', 'like', '%' . $value . '%');
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

    /**
     * 商户余额增减（统一管理可用余额、不可用余额、预付金）
     *
     * @param Request $request
     * @return Response
     * @throws SodiumException
     */
    public function adjustBalance(Request $request): Response
    {
        $payload = $request->post('payload');
        if (empty($payload)) {
            return $this->fail('非法请求');
        }

        $params = new XChaCha20(config('kkpay.api_crypto_key', ''))->get($payload);

        if (empty($params['id'])) {
            return $this->fail('必要参数缺失');
        }

        // 验证商户是否存在
        $merchant_id = (int)$params['id'];
        if (!Merchant::where('id', $merchant_id)->exists()) {
            return $this->fail('该商户不存在');
        }

        try {
            // 验证参数
            validate([
                'balance_type' => ['require', 'in:available,unavailable,prepaid'],
                'amount'       => ['require', 'float'],
                'remark'       => ['require', 'max:255']
            ], [
                'balance_type.require' => '变更类型不能为空',
                'balance_type.in'      => '变更类型不正确',
                'amount.require'       => '变更金额不能为空',
                'amount.float'         => '变更金额必须为数字',
                'remark.require'       => '备注不能为空',
                'remark.max'           => '备注不能超过255个字符'
            ])->check($params);
            $balance_type = $params['balance_type'];
            $amount       = number_format((float)$params['amount'], 2, '.', '');
            $remark       = $params['remark'];

            // 开启数据库事务
            Db::beginTransaction();

            // 根据余额类型调用不同的变更方法
            match ($balance_type) {
                'available' => MerchantWalletRecord::changeAvailable($merchant_id, $amount, '后台操作', null, $remark),
                'unavailable' => MerchantWalletRecord::changeUnAvailable($merchant_id, $amount, '后台操作', null, $remark),
                'prepaid' => MerchantWalletPrepaidRecord::changePrepaid($merchant_id, $amount, $remark),
            };

            Db::commit();

            // 记录操作日志
            $action = bccomp($amount, '0.00', 2) === 1 ? '增加' : '减少';
            $type   = match ($balance_type) {
                'available' => '可用余额',
                'unavailable' => '不可用余额',
                'prepaid' => '预付金',
            };
            $this->adminLog("为商户【{$merchant_id}】$action{$type}：{$amount}元");
        } catch (Throwable $e) {
            Db::rollBack();
            return $this->fail($e->getMessage());
        }

        return $this->success('操作成功');
    }

    /**
     * 商户提款记录列表
     *
     * @param Request $request
     * @return Response
     */
    public function withdrawalList(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 10);
        $sort   = $request->get('sort', 'id');
        $order  = $request->get('order', 'desc');
        $params = $request->only(['merchant_number', 'status', 'created_at']);

        try {
            validate([
                'merchant_number' => ['startWith:M', 'alphaNum', 'length:16'],
                'status'          => ['in:PENDING,PROCESSING,COMPLETED,FAILED,REJECTED,CANCELED'],
                'created_at'      => ['array']
            ], [
                'merchant_number.startWith' => '商户编号是以M开头的16位数字+英文组合',
                'merchant_number.alphaNum'  => '商户编号是以M开头的16位数字+英文组合',
                'merchant_number.length'    => '商户编号是以M开头的16位数字+英文组合',
                'status.in'                 => '状态参数不正确',
                'created_at.array'          => '请重新选择时间范围'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 检测排序字段和顺序
        if (!in_array($sort, ['id', 'amount', 'status']) || !in_array($order, ['asc', 'desc'])) {
            return $this->fail('排序失败，请刷新后重试');
        }

        // 构建查询
        $query = MerchantWithdrawalRecord::whereHas('merchant', fn($q) => $q->whereNull('deleted_at'))->with(['merchant:id,merchant_number,remark'])->when($params, function ($q) use ($params) {
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                switch ($key) {
                    case 'merchant_number':
                        $q->where('merchant_id', Merchant::where('merchant_number', $value)->value('id'));
                        break;
                    case 'status':
                        $q->where('status', $value);
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
        $list  = $query->offset($from)->limit($limit)->orderBy($sort, $order)->get()->append(['fee_type_text', 'status_text']);

        return $this->success(data: [
            'list'  => $list,
            'total' => $total,
        ]);
    }

    /**
     * 商户清账
     *
     * @param Request $request
     * @return Response
     */
    public function settleAccount(Request $request): Response
    {
        $merchantId = $request->post('id');
        $payeeId    = $request->post('payee_id');

        if (empty($merchantId) || empty($payeeId)) {
            return $this->fail('必要参数缺失');
        }

        $result = MerchantWithdrawalService::settleAccount((int)$merchantId, (int)$payeeId);

        if ($result['success']) {
            // 记录操作日志
            $this->adminLog("为商户【{$merchantId}】执行清账操作：{$result['message']}");
            return $this->success($result['message'], ['withdrawal_id' => $result['withdrawal_id']]);
        }

        return $this->fail($result['message']);
    }

    /**
     * 修改提款记录状态
     *
     * @param Request $request
     * @return Response
     */
    public function changeWithdrawalStatus(Request $request): Response
    {
        $id     = $request->post('id');
        $status = $request->post('status');
        $reason = $request->post('reason');

        if (empty($id) || empty($status)) {
            return $this->fail('必要参数缺失');
        }

        $result = MerchantWithdrawalService::changeStatus((int)$id, $status, $reason);

        if ($result['success']) {
            // 记录操作日志
            $this->adminLog("修改提款记录【{$id}】状态为【{$status}】");
            return $this->success($result['message']);
        }

        return $this->fail($result['message']);
    }
}
