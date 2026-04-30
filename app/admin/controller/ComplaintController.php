<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\PaymentChannel;
use app\model\PaymentChannelAccount;
use Core\baseController\AdminBase;
use Core\Service\ComplaintService;
use support\Request;
use support\Response;
use Throwable;

/**
 * 投诉管理控制器（后台）
 *
 * 提供投诉列表查询、详情查看、回复/处理投诉、自动抓取配置管理等后台管理接口。
 */
class ComplaintController extends AdminBase
{
    /**
     * 投诉列表
     *
     * @param Request $request 请求对象
     * @return Response
     */
    public function list(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 20);
        $params = $request->only(['complaint_id', 'trade_no', 'status', 'source_api', 'payment_channel_account_id', 'merchant_number', 'complaint_time']);

        try {
            validate([
                'complaint_id'               => ['max:64'],
                'trade_no'                   => ['max:24'],
                'payment_channel_account_id' => ['number'],
                'merchant_number'            => ['startWith:M', 'alphaNum', 'length:16'],
                'complaint_time'             => ['array'],
            ], [
                'complaint_id.max'                  => '投诉单号不能超过64个字符',
                'trade_no.max'                      => '订单号不能超过24个字符',
                'payment_channel_account_id.number' => '子账户ID必须为数字',
                'merchant_number.startWith'         => '商户编号格式不正确（以M开头的16位字母数字组合）',
                'merchant_number.alphaNum'          => '商户编号格式不正确（以M开头的16位字母数字组合）',
                'merchant_number.length'            => '商户编号格式不正确（以M开头的16位字母数字组合）',
                'complaint_time.array'              => '投诉时间范围格式不正确',
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $result = ComplaintService::getComplaintList($params, (int)$from, (int)$limit);

        return $this->success(data: $result);
    }

    /**
     * 投诉详情
     *
     * @param Request $request 请求对象
     * @return Response
     */
    public function detail(Request $request): Response
    {
        $id = (int)$request->get('id', 0);
        if ($id <= 0) {
            return $this->fail('投诉ID不能为空');
        }

        $complaint = ComplaintService::getComplaintDetail($id);
        if (!$complaint) {
            return $this->fail('投诉记录不存在');
        }

        return $this->success(data: $complaint->append(['status_text']));
    }

    /**
     * 回复/留言投诉
     *
     * @param Request $request 请求对象
     * @return Response
     */
    public function reply(Request $request): Response
    {
        try {
            $params = $this->decryptPayload($request);
            if ($params === null) {
                return $this->fail('非法请求');
            }

            validate([
                'id'            => ['require', 'number'],
                'reply_content' => ['require', 'max:2048'],
            ], [
                'id.require'            => '投诉ID不能为空',
                'id.number'             => '投诉ID必须为数字',
                'reply_content.require' => '回复内容不能为空',
                'reply_content.max'     => '回复内容不能超过2048个字符',
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $result = ComplaintService::replyComplaint((int)$params['id'], trim($params['reply_content']), $params['reply_images'] ?? []);

        if ($result['state']) {
            $this->adminLog("回复投诉【{$params['id']}】");
            return $this->success($result['message']);
        }

        return $this->fail($result['message']);
    }

    /**
     * 处理投诉
     *
     * @param Request $request 请求对象
     * @return Response
     */
    public function process(Request $request): Response
    {
        try {
            $params = $this->decryptPayload($request);
            if ($params === null) {
                return $this->fail('非法请求');
            }

            validate([
                'id'               => ['require', 'number'],
                'feedback_content' => ['require', 'max:2048'],
            ], [
                'id.require'               => '投诉ID不能为空',
                'id.number'                => '投诉ID必须为数字',
                'feedback_content.require' => '处理意见不能为空',
                'feedback_content.max'     => '处理意见不能超过2048个字符',
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $result = ComplaintService::processComplaint((int)$params['id'], ['feedback_content' => trim($params['feedback_content']), 'feedback_code' => $params['feedback_code'] ?? 'AGREE', 'feedback_images' => $params['feedback_images'] ?? []]);

        if ($result['state']) {
            $this->adminLog("处理投诉【{$params['id']}】");
            return $this->success($result['message']);
        }

        return $this->fail($result['message']);
    }

    /**
     * 获取自动抓取配置（含可选子账户列表）
     *
     * @param Request $request 请求对象
     * @return Response
     */
    public function autoFetchConfig(Request $request): Response
    {
        $accountIds = ComplaintService::getAutoFetchAccounts();

        // 已设置的子账户信息
        $accounts = [];
        if (!empty($accountIds)) {
            $accounts = PaymentChannelAccount::with('paymentChannel:id,name,gateway')->whereIn('id', $accountIds)->get(['id', 'name', 'payment_channel_id'])->toArray();
        }

        // 查询所有已实现投诉处理的网关对应的可选子账户
        $gatewayDir         = base_path('core/Gateway');
        $gateways           = [];
        $selectableAccounts = [];
        foreach (glob($gatewayDir . '/*/Complaint.php') as $file) {
            $gateways[] = basename(dirname($file));
        }
        if (!empty($gateways)) {
            $channelIds         = PaymentChannel::whereIn('gateway', $gateways)->pluck('id');
            $selectableAccounts = PaymentChannelAccount::whereIn('payment_channel_id', $channelIds)->get(['id', 'name', 'payment_channel_id'])->groupBy('payment_channel_id')->map(function ($group) {
                $channel = $group->first()->paymentChannel;
                return ['channel_id' => $channel->id, 'channel_name' => $channel->name, 'gateway' => $channel->gateway, 'accounts' => $group->map(fn($item) => ['id' => $item->id, 'name' => $item->name])->values()];
            })->values();
        }

        return $this->success(data: ['account_ids' => $accountIds, 'accounts' => $accounts, 'selectable_accounts' => $selectableAccounts]);
    }

    /**
     * 设置自动抓取子账户列表
     *
     * @param Request $request 请求对象
     * @return Response
     */
    public function setAutoFetchConfig(Request $request): Response
    {
        try {
            $params = $this->decryptPayload($request);
            if ($params === null) {
                return $this->fail('非法请求');
            }

            validate([
                'account_ids' => ['array'],
            ], [
                'account_ids.array' => '子账户列表格式不正确',
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        if (ComplaintService::setAutoFetchAccounts($params['account_ids'])) {
            $this->adminLog('设置投诉自动抓取子账户列表: ' . json_encode($params['account_ids']));
            return $this->success('配置成功');
        }

        return $this->fail('配置失败');
    }

    /**
     * 手动触发抓取投诉
     *
     * @param Request $request 请求对象
     * @return Response
     */
    public function manualFetch(Request $request): Response
    {
        $accountId = (int)$request->post('account_id', 0);
        if ($accountId <= 0) {
            return $this->fail('子账户ID不能为空');
        }

        // 验证子账户是否存在
        if (!PaymentChannelAccount::where('id', $accountId)->exists()) {
            return $this->fail('子账户不存在');
        }

        $count = ComplaintService::fetchAndSaveComplaints($accountId);

        $this->adminLog("手动抓取投诉，子账户ID: {$accountId}，新增 $count 条");
        return $this->success("抓取完成，新增 $count 条投诉记录");
    }
}
