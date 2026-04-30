<?php

declare(strict_types=1);

namespace Core\Abstract;

/**
 * 投诉处理抽象基类
 *
 * 定义各支付网关投诉处理的统一接口规范，
 * 具体网关在各自目录下实现 Complaint 类继承此抽象类。
 */
abstract class ComplaintAbstract
{
    /**
     * 查询消费者投诉列表
     *
     * @param array $config 网关配置（通道子账户的 config 字段）
     * @param array $params 查询参数（页码、时间范围等）
     * @return array 投诉列表数据 ['list' => [...], 'total' => int]
     */
    abstract public static function queryComplaintList(array $config, array $params): array;

    /**
     * 查询消费者投诉详情
     *
     * @param array  $config      网关配置
     * @param string $complaintId 上游投诉单号
     * @return array 投诉详情数据
     */
    abstract public static function queryComplaintDetail(array $config, string $complaintId): array;

    /**
     * 处理消费者投诉
     *
     * @param array  $config      网关配置
     * @param string $complaintId 上游投诉单号
     * @param array  $params      处理参数（处理意见、方案等）
     * @return array 处理结果 ['state' => bool, 'message' => string]
     */
    abstract public static function processComplaint(array $config, string $complaintId, array $params): array;

    /**
     * 投诉处理附件图片上传
     *
     * @param array  $config    网关配置
     * @param string $imagePath 图片文件路径
     * @return array 上传结果 ['state' => bool, 'image_url' => string]
     */
    abstract public static function uploadImage(array $config, string $imagePath): array;

    /**
     * 商户投诉留言/回复
     *
     * @param array  $config      网关配置
     * @param string $complaintId 上游投诉单号
     * @param array  $params      回复参数（回复内容、图片等）
     * @return array 回复结果 ['state' => bool, 'message' => string]
     */
    abstract public static function replyComplaint(array $config, string $complaintId, array $params): array;

    /**
     * 商户交易投诉通知回调处理
     *
     * @param array $config     网关配置
     * @param array $notifyData 回调通知数据
     * @return array 处理结果 ['state' => bool, 'message' => string]
     */
    abstract public static function complaintNotify(array $config, array $notifyData): array;
}
