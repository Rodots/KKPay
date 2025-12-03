<?php

declare(strict_types = 1);

namespace app\admin\controller;

use app\model\Blacklist;
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
                'entity_value.max' => '黑名单内容长度不能超过512位',
                'expired_at.array' => '请重新选择选择时间范围'
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
                'entity_value.require' => '黑名单内容不能为空',
                'entity_value.max'     => '黑名单内容字数不能超过512个',
                'reason.require'       => '请填写黑名单原因',
                'reason.max'           => '黑名单原因字数不能超过1024个',
                'expired_at.date'      => '请选择正确的时间'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
        if (RiskService::addToBlacklist(trim($params['entity_type']), trim($params['entity_value']), trim($params['reason']), expiredAt: $params['expired_at'])) {
            return $this->success('新增成功');
        }
        return $this->fail('新增失败');
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
}
