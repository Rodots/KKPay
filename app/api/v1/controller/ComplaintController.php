<?php

declare(strict_types=1);

namespace app\api\v1\controller;

use app\api\v1\middleware\ApiSignatureVerification;
use Core\baseController\ApiBase;
use Core\Service\ComplaintService;
use support\annotation\Middleware;
use support\Request;
use support\Response;

/**
 * 投诉控制器（商户API）
 *
 * 提供投诉列表查询、详情查看、回复/处理投诉等商户对接接口。
 */
#[Middleware(ApiSignatureVerification::class)]
class ComplaintController extends ApiBase
{
    /**
     * 查询投诉列表
     *
     * biz_content 参数：
     * - page_num: 页码（默认1）
     * - page_size: 每页数量（默认20）
     * - status: 投诉状态（可选）
     * - trade_no: 平台订单号（可选）
     * - complaint_time: 投诉时间范围（可选，数组 [开始, 结束]）
     *
     * @param Request $request 请求对象
     * @return Response
     */
    public function list(Request $request): Response
    {
        $bizContent = $this->parseBizContent($request);
        if (is_string($bizContent)) {
            return $this->fail($bizContent);
        }

        $pageNum  = $this->getInt($bizContent, 'page_num', 1);
        $pageSize = $this->getInt($bizContent, 'page_size', 20);
        $from     = ($pageNum - 1) * $pageSize;

        $params = ['status' => $this->getString($bizContent, 'status'), 'trade_no' => $this->getString($bizContent, 'trade_no'), 'complaint_time' => $bizContent['complaint_time'] ?? null];

        $result = ComplaintService::getComplaintList($params, $from, $pageSize, $this->getMerchantId($request));

        return $this->success([
            'list'      => $result['list'],
            'total'     => $result['total'],
            'page_num'  => $pageNum,
            'page_size' => $pageSize,
        ]);
    }

    /**
     * 查询投诉详情
     *
     * biz_content 参数：
     * - id: 投诉记录ID
     *
     * @param Request $request 请求对象
     * @return Response
     */
    public function detail(Request $request): Response
    {
        $bizContent = $this->parseBizContent($request);
        if (is_string($bizContent)) {
            return $this->fail($bizContent);
        }

        $id = $this->getInt($bizContent, 'id');
        if ($id <= 0) {
            return $this->fail('投诉ID不能为空');
        }

        $complaint = ComplaintService::getComplaintDetail($id, $this->getMerchantId($request));
        if (!$complaint) {
            return $this->fail('投诉记录不存在');
        }

        return $this->success($complaint->append(['status_text']));
    }

    /**
     * 回复/留言投诉
     *
     * biz_content 参数：
     * - id: 投诉记录ID
     * - reply_content: 回复内容
     * - reply_images: 回复图片URL列表（可选）
     *
     * @param Request $request 请求对象
     * @return Response
     */
    public function reply(Request $request): Response
    {
        $bizContent = $this->parseBizContent($request);
        if (is_string($bizContent)) {
            return $this->fail($bizContent);
        }

        $id           = $this->getInt($bizContent, 'id');
        $replyContent = $this->getString($bizContent, 'reply_content');
        $replyImages  = $bizContent['reply_images'] ?? [];

        if ($id <= 0) {
            return $this->fail('投诉ID不能为空');
        }
        if (empty($replyContent)) {
            return $this->fail('回复内容不能为空');
        }

        // 校验投诉是否属于当前商户
        $complaint = ComplaintService::getComplaintDetail($id, $this->getMerchantId($request));
        if (!$complaint) {
            return $this->fail('投诉记录不存在');
        }

        $result = ComplaintService::replyComplaint($id, $replyContent, $replyImages);

        return $result['state'] ? $this->success(message: $result['message']) : $this->fail($result['message']);
    }

    /**
     * 处理投诉
     *
     * biz_content 参数：
     * - id: 投诉记录ID
     * - feedback_content: 处理意见
     * - feedback_code: 处理方案编码（可选，默认AGREE）
     * - feedback_images: 处理图片URL列表（可选）
     *
     * @param Request $request 请求对象
     * @return Response
     */
    public function process(Request $request): Response
    {
        $bizContent = $this->parseBizContent($request);
        if (is_string($bizContent)) {
            return $this->fail($bizContent);
        }

        $id              = $this->getInt($bizContent, 'id');
        $feedbackContent = $this->getString($bizContent, 'feedback_content');

        if ($id <= 0) {
            return $this->fail('投诉ID不能为空');
        }
        if (empty($feedbackContent)) {
            return $this->fail('处理意见不能为空');
        }

        // 校验投诉是否属于当前商户
        $complaint = ComplaintService::getComplaintDetail($id, $this->getMerchantId($request));
        if (!$complaint) {
            return $this->fail('投诉记录不存在');
        }

        $result = ComplaintService::processComplaint($id, ['feedback_content' => $feedbackContent, 'feedback_code' => $this->getString($bizContent, 'feedback_code') ?? 'AGREE', 'feedback_images' => $bizContent['feedback_images'] ?? []]);

        return $result['state'] ? $this->success(message: $result['message']) : $this->fail($result['message']);
    }
}
