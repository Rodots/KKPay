<?php

declare(strict_types=1);

namespace Core\Gateway\Alipay;

use Core\Abstract\ComplaintAbstract;
use Core\Gateway\Alipay\Lib\Factory;
use GuzzleHttp\Exception\GuzzleException;
use support\Log;
use Throwable;

/**
 * 支付宝投诉处理网关
 */
class Complaint extends ComplaintAbstract
{
    /** V2 风控投诉接口标识 */
    public const string SOURCE_RISK = 'alipay.security.risk.complaint';

    /** V3 交易投诉接口标识 */
    public const string SOURCE_TRADE = 'alipay.merchant.tradecomplain';

    /**
     * 查询消费者投诉列表
     *
     * 同时从 V2 和 V3 两套接口获取投诉列表，合并后返回。
     *
     * @param array $config 网关配置
     * @param array $params 查询参数 ['page_num' => 1, 'page_size' => 10, 'begin_time' => '', 'end_time' => '', 'source' => '']
     * @return array ['list' => [...], 'total' => int]
     */
    public static function queryComplaintList(array $config, array $params): array
    {
        $allList = [];

        // V2 风控投诉列表
        try {
            $riskList = self::queryRiskComplaintList($config, $params);
            $allList  = $riskList;
        } catch (Throwable $e) {
            Log::error('RiskGO消费者投诉列表查询失败: ' . $e->getMessage());
        }

        // V3 交易投诉列表
        try {
            $tradeList = self::queryTradeComplaintList($config, $params);
            $allList   = array_merge($allList, $tradeList);
        } catch (Throwable $e) {
            Log::error('小程序交易投诉列表查询失败: ' . $e->getMessage());
        }

        return ['list' => $allList, 'total' => count($allList)];
    }

    /**
     * RiskGO消费者投诉列表查询
     *
     * @param array $config 网关配置
     * @param array $params 查询参数
     * @return array 投诉列表（已标准化）
     * @throws GuzzleException
     */
    private static function queryRiskComplaintList(array $config, array $params): array
    {
        $alipay = Factory::createFromArray(self::formatConfig($config));

        $bizParams = ['current_page_num' => $params['page_num'] ?? 1, 'page_size' => $params['page_size'] ?? 10, 'upgrade' => true];
        if (!empty($params['begin_time'])) {
            $bizParams['start_time'] = $params['begin_time'];
        }
        if (!empty($params['end_time'])) {
            $bizParams['end_time'] = $params['end_time'];
        }

        $result = $alipay->v2Execute($bizParams, 'alipay.security.risk.complaint.info.batchquery', '', '');
        $items  = $result['complaint_info_list'] ?? [];

        // 标准化投诉数据格式
        return array_map(fn(array $item) => [
            'complaint_id'     => $item['complaint_event_id'] ?? '',
            'source_api'       => self::SOURCE_RISK,
            'complaint_reason' => $item['complaint_reason'] ?? '',
            'complaint_type'   => $item['leaf_category_name'] ?? '',
            'status'           => self::mapRiskStatus($item['status'] ?? ''),
            'content'          => $item['content'] ?? null,
            'images'           => $item['images'] ?? null,
            'trade_no_hint'    => $item['trade_no'] ?? null,
            'api_trade_no'     => $item['trade_no'] ?? null,
            'complaint_time'   => $item['gmt_create'] ?? null,
            'buyer_user_id'    => $item['user_id'] ?? null,
            'buyer_open_id'    => $item['open_id'] ?? null,
            'upgrade_content'  => $item['upgrade_content'] ?? null,
            'upgrade_time'     => $item['gmt_upgrade'] ?? null,
        ], $items);
    }

    /**
     * 小程序交易投诉列表查询
     *
     * @param array $config 网关配置
     * @param array $params 查询参数
     * @return array 投诉列表（已标准化）
     * @throws GuzzleException
     */
    private static function queryTradeComplaintList(array $config, array $params): array
    {
        $alipay = Factory::createFromArray(self::formatConfig($config));

        $bizParams = ['target_infos' => ['target_id' => $config['app_id'], 'target_type' => 'APPID'], 'page_num' => $params['page_num'] ?? 1, 'page_size' => min(20, $params['page_size'] ?? 10)];
        if (!empty($params['begin_time'])) {
            $bizParams['begin_time'] = $params['begin_time'];
        }
        if (!empty($params['end_time'])) {
            $bizParams['end_time'] = $params['end_time'];
        }

        $result = $alipay->execute($bizParams, 'alipay.merchant.tradecomplain.batchquery');
        $items  = $result['trade_complaint_infos'] ?? [];

        // 标准化投诉数据格式
        return array_map(fn(array $item) => [
            'complaint_id'     => $item['complaint_event_id'] ?? '',
            'source_api'       => self::SOURCE_TRADE,
            'complaint_reason' => $item['complaint_reason'] ?? '',
            'complaint_type'   => $item['leaf_category_name'] ?? '',
            'status'           => self::mapTradeStatus($item['status'] ?? ''),
            'content'          => $item['content'] ?? null,
            'images'           => $item['images'] ?? null,
            'trade_no_hint'    => $item['trade_no'] ?? null,
            'api_trade_no'     => $item['trade_no'] ?? null,
            'complaint_time'   => $item['gmt_create'] ?? null,
            'buyer_user_id'    => $item['buyer_user_id'] ?? null,
            'buyer_open_id'    => $item['buyer_open_id'] ?? null,
        ], $items);
    }

    /**
     * 查询消费者投诉详情
     *
     * @param array  $config      网关配置
     * @param string $complaintId 上游投诉单号
     * @return array 投诉详情（含协商记录、升级信息等）
     */
    public static function queryComplaintDetail(array $config, string $complaintId): array
    {
        $alipay = Factory::createFromArray(self::formatConfig($config));

        // 尝试 V2 接口查询详情
        try {
            $result = $alipay->v2Execute(['complaint_event_id' => $complaintId], 'alipay.security.risk.complaint.info.query', '', '');
            return [
                'state'             => true,
                'source_api'        => self::SOURCE_RISK,
                'complaint_id'      => $result['complaint_event_id'] ?? $complaintId,
                'complaint_reason'  => $result['complaint_reason'] ?? '',
                'complaint_type'    => $result['leaf_category_name'] ?? '',
                'status'            => self::mapRiskStatus($result['status'] ?? ''),
                'content'           => $result['content'] ?? null,
                'images'            => $result['images'] ?? null,
                'trade_no_hint'     => $result['trade_no'] ?? null,
                'complaint_time'    => $result['gmt_create'] ?? null,
                'negotiate_records' => $result['reply_detail_infos'] ?? null,
                'upgrade_content'   => $result['upgrade_content'] ?? null,
                'upgrade_time'      => $result['gmt_upgrade'] ?? null,
            ];
        } catch (Throwable) {
            // V2 查询失败，继续尝试 V3
        }

        // 尝试 V3 接口查询详情
        try {
            $result = $alipay->execute(['complaint_event_id' => $complaintId], 'alipay.merchant.tradecomplain.query');
            return [
                'state'             => true,
                'source_api'        => self::SOURCE_TRADE,
                'complaint_id'      => $result['complaint_event_id'] ?? $complaintId,
                'complaint_reason'  => $result['complaint_reason'] ?? '',
                'complaint_type'    => $result['leaf_category_name'] ?? '',
                'status'            => self::mapTradeStatus($result['status'] ?? ''),
                'content'           => $result['content'] ?? null,
                'images'            => $result['images'] ?? null,
                'trade_no_hint'     => $result['trade_no'] ?? null,
                'complaint_time'    => $result['gmt_create'] ?? null,
                'negotiate_records' => $result['reply_detail_infos'] ?? null,
            ];
        } catch (Throwable $e) {
            return ['state' => false, 'message' => '查询投诉详情失败: ' . $e->getMessage()];
        }
    }

    /**
     * 处理消费者投诉
     *
     * @param array  $config      网关配置
     * @param string $complaintId 上游投诉单号
     * @param array  $params      处理参数 ['feedback_code' => '', 'feedback_content' => '', 'feedback_images' => [], 'source_api' => '']
     * @return array ['state' => bool, 'message' => string]
     */
    public static function processComplaint(array $config, string $complaintId, array $params): array
    {
        $alipay    = Factory::createFromArray(self::formatConfig($config));
        $sourceApi = $params['source_api'] ?? self::SOURCE_RISK;

        try {
            if ($sourceApi === self::SOURCE_RISK) {
                // V2: alipay.security.risk.complaint.process.finish
                $bizParams = ['complaint_event_id' => $complaintId, 'feedback_code' => $params['feedback_code'] ?? 'AGREE', 'feedback_content' => $params['feedback_content'] ?? ''];
                if (!empty($params['feedback_images'])) {
                    $bizParams['feedback_images'] = $params['feedback_images'];
                }
                $alipay->v2Execute($bizParams, 'alipay.security.risk.complaint.process.finish', '', '');
            } else {
                // V3: alipay.merchant.tradecomplain.reply（处理模式）
                $bizParams = ['complaint_event_id' => $complaintId, 'reply_content' => $params['feedback_content'] ?? ''];
                if (!empty($params['feedback_images'])) {
                    $bizParams['reply_images'] = $params['feedback_images'];
                }
                $alipay->execute($bizParams, 'alipay.merchant.tradecomplain.reply');
            }
            return ['state' => true, 'message' => '处理成功'];
        } catch (Throwable $e) {
            return ['state' => false, 'message' => '处理失败: ' . $e->getMessage()];
        }
    }

    /**
     * 投诉处理附件图片上传
     *
     * @param array  $config    网关配置
     * @param string $imagePath 图片文件路径
     * @return array ['state' => bool, 'image_url' => string]
     */
    public static function uploadImage(array $config, string $imagePath): array
    {
        $alipay = Factory::createFromArray(self::formatConfig($config));

        try {
            $result = $alipay->execute(['image_content' => $imagePath, 'image_type' => pathinfo($imagePath, PATHINFO_EXTENSION)], 'alipay.merchant.image.upload');
            return ['state' => true, 'image_url' => $result['image_url'] ?? ''];
        } catch (Throwable $e) {
            return ['state' => false, 'image_url' => '', 'message' => '图片上传失败: ' . $e->getMessage()];
        }
    }

    /**
     * 商户投诉留言/回复
     *
     * @param array  $config      网关配置
     * @param string $complaintId 上游投诉单号
     * @param array  $params      回复参数 ['reply_content' => '', 'reply_images' => [], 'source_api' => '']
     * @return array ['state' => bool, 'message' => string]
     */
    public static function replyComplaint(array $config, string $complaintId, array $params): array
    {
        $alipay    = Factory::createFromArray(self::formatConfig($config));
        $sourceApi = $params['source_api'] ?? self::SOURCE_RISK;

        try {
            $bizParams = ['complaint_event_id' => $complaintId, 'reply_content' => $params['reply_content'] ?? ''];
            if (!empty($params['reply_images'])) {
                $bizParams['reply_images'] = $params['reply_images'];
            }
            if ($sourceApi === self::SOURCE_RISK) {
                // V2: alipay.security.risk.complaint.reply.send
                $alipay->v2Execute($bizParams, 'alipay.security.risk.complaint.reply.send', '', '');
            } else {
                // V3: alipay.merchant.tradecomplain.reply（留言模式）
                $alipay->execute($bizParams, 'alipay.merchant.tradecomplain.reply');
            }
            return ['state' => true, 'message' => '回复成功'];
        } catch (Throwable $e) {
            return ['state' => false, 'message' => '回复失败: ' . $e->getMessage()];
        }
    }

    /**
     * 商户交易投诉通知回调处理
     *
     * @param array $config     网关配置
     * @param array $notifyData 回调通知数据
     * @return array ['state' => bool, 'data' => array]
     */
    public static function complaintNotify(array $config, array $notifyData): array
    {
        $alipay = Factory::createFromArray(self::formatConfig($config));

        try {
            // 验证回调签名
            $signatureManager = $alipay->getConfigManager()->getSignatureManager();
            if (!$signatureManager->verifyParams($notifyData)) {
                return ['state' => false, 'message' => '签名验证失败'];
            }

            return [
                'state' => true,
                'data'  => [
                    'complaint_id'     => $notifyData['complaint_event_id'] ?? '',
                    'status'           => $notifyData['status'] ?? '',
                    'complaint_reason' => $notifyData['complaint_reason'] ?? '',
                    'trade_no_hint'    => $notifyData['trade_no'] ?? null,
                ],
            ];
        } catch (Throwable $e) {
            return ['state' => false, 'message' => '通知处理失败: ' . $e->getMessage()];
        }
    }

    /**
     * 商家补充凭证（V3独有接口）
     *
     * @param array  $config      网关配置
     * @param string $complaintId 上游投诉单号
     * @param array  $params      凭证参数 ['evidence_content' => '', 'evidence_images' => []]
     * @return array ['state' => bool, 'message' => string]
     */
    public static function supplementEvidence(array $config, string $complaintId, array $params): array
    {
        $alipay = Factory::createFromArray(self::formatConfig($config));

        try {
            $bizParams = ['complaint_event_id' => $complaintId, 'evidence_content' => $params['evidence_content'] ?? ''];
            if (!empty($params['evidence_images'])) {
                $bizParams['evidence_images'] = $params['evidence_images'];
            }
            $alipay->execute($bizParams, 'alipay.merchant.tradecomplain.supplement');
            return ['state' => true, 'message' => '补充凭证成功'];
        } catch (Throwable $e) {
            return ['state' => false, 'message' => '补充凭证失败: ' . $e->getMessage()];
        }
    }

    /**
     * 映射 V2 风控投诉状态到统一状态枚举
     *
     * @param string $riskStatus V2接口返回的状态值
     * @return string 统一状态枚举值
     */
    private static function mapRiskStatus(string $riskStatus): string
    {
        return match ($riskStatus) {
            'MERCHANT_PROCESSING' => 'MERCHANT_PROCESSING',
            'MERCHANT_FEEDBACKED' => 'MERCHANT_FEEDBACKED',
            'FINISHED' => 'FINISHED',
            'CANCELLED' => 'CANCELLED',
            'UPGRADED' => 'UPGRADED',
            default => 'PENDING',
        };
    }

    /**
     * 映射 V3 交易投诉状态到统一状态枚举
     *
     * @param string $tradeStatus V3接口返回的状态值
     * @return string 统一状态枚举值
     */
    private static function mapTradeStatus(string $tradeStatus): string
    {
        return match ($tradeStatus) {
            'MERCHANT_PROCESSING' => 'MERCHANT_PROCESSING',
            'MERCHANT_FEEDBACKED' => 'MERCHANT_FEEDBACKED',
            'FINISHED' => 'FINISHED',
            'CANCELLED' => 'CANCELLED',
            'CLOSED' => 'CLOSED',
            default => 'PENDING',
        };
    }

    /**
     * 格式化配置项（复用 Gateway 的配置格式）
     *
     * @param array $channel 通道子账户配置
     * @return array AlipayClient 所需配置
     */
    private static function formatConfig(array $channel): array
    {
        $certBasePath = base_path('core/Gateway/Alipay/cert/' . $channel['app_id'] . '/');
        return [
            'appId'                   => $channel['app_id'],
            'privateKey'              => $channel['app_private_key'],
            'alipayPublicKey'         => $channel['alipay_public_key'],
            'alipayPublicKeyFilePath' => $certBasePath . 'alipayCertPublicKey_RSA2.crt',
            'rootCertPath'            => $certBasePath . 'alipayRootCert.crt',
            'appCertPath'             => $certBasePath . 'appCertPublicKey_' . $channel['app_id'] . '.crt',
            'certMode'                => $channel['cert_mode'],
            'encryptKey'              => $channel['aes_secret_key'],
        ];
    }
}
