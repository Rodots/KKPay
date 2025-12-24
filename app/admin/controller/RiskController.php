<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\Blacklist;
use app\model\Merchant;
use app\model\RiskLog;
use Core\baseController\AdminBase;
use Core\Service\RiskService;
use SodiumException;
use support\Request;
use support\Response;
use support\Rodots\Crypto\XChaCha20;
use Throwable;

class RiskController extends AdminBase
{
    /**
     * 黑名单列表
     */
    public function blackList(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 10);
        $params = $request->only(['entity_type', 'entity_value', 'origin', 'expired_at']);

        try {
            validate([
                'entity_value' => ['max:512'],
                'expired_at'   => ['array']
            ], [
                'entity_value.max' => '黑名单内容不能超过512个字符',
                'expired_at.array' => '过期时间范围格式不正确'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 构建查询
        $query = Blacklist::when($params, function ($q) use ($params) {
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                switch ($key) {
                    case 'entity_type':
                        $q->where('entity_type', $value);
                        break;
                    case 'entity_value':
                        $q->where('entity_value', $value);
                        break;
                    case 'origin':
                        $q->where('origin', $value);
                        break;
                    case 'expired_at':
                        $q->whereBetween('expired_at', [$value[0], $value[1]]);
                        break;
                }
            }
            return $q;
        });

        // 获取总数和数据
        $total = $query->count();
        $list  = $query->offset($from)->limit($limit)->orderBy('id', 'desc')->get()->append(['entity_type_text', 'origin_text', 'expired_at_text']);

        return $this->success(data: [
            'list'  => $list,
            'total' => $total,
        ]);
    }

    /**
     * 新增黑名单
     *
     * @param Request $request
     * @return Response
     * @throws SodiumException
     */
    public function blackCreate(Request $request): Response
    {
        $payload = $request->post('payload');
        if (empty($payload)) {
            return $this->fail('非法请求');
        }

        $params = new XChaCha20(config('kkpay.api_crypto_key', ''))->get($payload);

        try {
            validate([
                'entity_type'  => ['require'],
                'entity_value' => ['require', 'max:512'],
                'reason'       => ['require', 'max:1024'],
                'expired_at'   => ['date']
            ], [
                'entity_type.require'  => '请选择黑名单类型',
                'entity_value.require' => '请输入黑名单内容',
                'entity_value.max'     => '黑名单内容不能超过512个字符',
                'reason.require'       => '请输入拉黑原因',
                'reason.max'           => '拉黑原因不能超过1024个字符',
                'expired_at.date'      => '过期时间格式不正确'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
        if (RiskService::addToBlacklist(trim($params['entity_type']), trim($params['entity_value']), trim($params['reason']), expiredAt: $params['expired_at'])) {
            return $this->success('新增或更新黑名单成功');
        }
        return $this->fail('新增或更新黑名单失败');
    }

    /**
     * 解除黑名单
     * @param Request $request
     * @return Response
     */
    public function delBlack(Request $request): Response
    {
        $id = $request->post('id');

        if (empty($id)) {
            return $this->fail('必要参数缺失');
        }

        try {
            Blacklist::where('id', $id)->delete();
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
        return $this->success('解除成功');
    }

    /**
     * 批量解除黑名单
     * @param Request $request
     * @return Response
     */
    public function batchDelBlack(Request $request): Response
    {
        $ids = $request->post('ids');

        if (empty($ids) || !is_array($ids)) {
            return $this->fail('必要参数缺失');
        }

        try {
            Blacklist::whereIn('id', $ids)->delete();
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
        return $this->success('解除成功');
    }

    /**
     * 风控日志
     */
    public function log(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 10);
        $params = $request->only(['merchant_number', 'type', 'content', 'created_at']);

        try {
            validate([
                'merchant_number' => ['startWith:M', 'alphaNum', 'length:16'],
                'type'            => ['number'],
                'content'         => ['max:512'],
                'created_at'      => ['array']
            ], [
                'merchant_number.startWith' => '商户编号格式不正确（以M开头的16位字母数字组合）',
                'merchant_number.alphaNum'  => '商户编号格式不正确（以M开头的16位字母数字组合）',
                'merchant_number.length'    => '商户编号格式不正确（以M开头的16位字母数字组合）',
                'type.number'               => '风控类型必须为数字',
                'content.max'               => '风控内容不能超过512个字符',
                'created_at.array'          => '时间范围格式不正确'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 构建查询
        $query = RiskLog::whereHas('merchant', fn($q) => $q->whereNull('deleted_at'))->with(['merchant:id,merchant_number'])->when($params, function ($q) use ($params) {
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
                    case 'content':
                        $q->where('content', 'like', '%' . $value . '%');
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
        $list  = $query->offset($from)->limit($limit)->get()->append(['type_text']);

        return $this->success(data: [
            'list'  => $list,
            'total' => $total,
        ]);
    }
}
