<?php

declare(strict_types=1);

namespace Core\Service;

use app\model\Blacklist;
use app\model\Merchant;
use app\model\Order;
use app\model\OrderBuyer;
use app\model\OrderComplaint;
use app\model\PaymentChannelAccount;
use support\Log;
use Throwable;

/**
 * 投诉服务类
 *
 * 提供投诉相关的核心业务逻辑，与具体网关解耦。
 * 通过 payment_channel_account_id 动态加载对应网关的 Complaint 实现。
 */
class ComplaintService
{
    /** 自动抓取配置的 sys_config 键组 */
    private const string CONFIG_GROUP = 'complaint';

    private const string CONFIG_KEY = 'auto_fetch_accounts';

    /**
     * 加载指定网关的投诉处理类
     *
     * @param string $gateway 网关名称（如 Alipay）
     * @return string 投诉处理类的完全限定类名
     * @throws \Exception
     */
    private static function loadComplaintClass(string $gateway): string
    {
        $fqcn = "\\Core\\Gateway\\{$gateway}\\Complaint";
        if (!class_exists($fqcn)) {
            throw new \Exception("网关 '{$gateway}' 未实现投诉处理功能");
        }
        return $fqcn;
    }

    /**
     * 通过子账户ID获取网关名称和配置
     *
     * @param int $accountId 支付通道子账户ID
     * @return array ['gateway' => string, 'config' => array]
     * @throws \Exception
     */
    private static function getGatewayInfoByAccountId(int $accountId): array
    {
        $account = PaymentChannelAccount::with('paymentChannel')->find($accountId);
        if (!$account) {
            throw new \Exception('支付通道子账户不存在');
        }
        $channel = $account->paymentChannel;
        if (!$channel) {
            throw new \Exception('支付通道不存在');
        }
        return ['gateway' => $channel->gateway, 'config' => $account->config ?? []];
    }

    /**
     * 抓取并保存投诉列表
     *
     * 从对应网关拉取投诉数据，查重入库，匹配订单后自动拉黑投诉人。
     *
     * @param int $accountId 支付通道子账户ID
     * @return int 新增投诉数量
     */
    public static function fetchAndSaveComplaints(int $accountId): int
    {
        try {
            $info   = self::getGatewayInfoByAccountId($accountId);
            $fqcn   = self::loadComplaintClass($info['gateway']);
            $result = $fqcn::queryComplaintList($info['config'], ['page_num' => 1, 'page_size' => 50]);
            $list   = $result['list'] ?? [];
            $count  = 0;

            foreach ($list as $item) {
                // 查重：同一投诉单号 + 来源接口不重复入库
                if (OrderComplaint::where('complaint_id', $item['complaint_id'])->where('source_api', $item['source_api'])->exists()) {
                    continue;
                }

                // 匹配平台订单号
                $tradeNo    = null;
                $merchantId = null;
                if (!empty($item['api_trade_no'])) {
                    $order = Order::where('api_trade_no', $item['api_trade_no'])->first(['trade_no', 'merchant_id']);
                    if ($order) {
                        $tradeNo    = $order->trade_no;
                        $merchantId = $order->merchant_id;
                    }
                }
                if (!$tradeNo && !empty($item['trade_no_hint'])) {
                    $order = Order::where('trade_no', $item['trade_no_hint'])->first(['trade_no', 'merchant_id']);
                    if ($order) {
                        $tradeNo    = $order->trade_no;
                        $merchantId = $order->merchant_id;
                    }
                }

                // 入库
                $complaint = OrderComplaint::create([
                    'complaint_id'               => $item['complaint_id'],
                    'source_api'                 => $item['source_api'],
                    'trade_no'                   => $tradeNo,
                    'payment_channel_account_id' => $accountId,
                    'merchant_id'                => $merchantId,
                    'complaint_reason'           => $item['complaint_reason'] ?? '',
                    'complaint_type'             => $item['complaint_type'] ?? '',
                    'status'                     => $item['status'] ?? OrderComplaint::STATUS_PENDING,
                    'content'                    => mb_substr($item['content'] ?? '', 0, 2048),
                    'images'                     => $item['images'] ?? null,
                    'upgrade_content'            => isset($item['upgrade_content']) ? mb_substr($item['upgrade_content'], 0, 2048) : null,
                    'upgrade_time'               => $item['upgrade_time'] ?? null,
                    'complaint_time'             => $item['complaint_time'] ?? null,
                ]);

                // 自动拉黑投诉人
                self::autoBlacklistBuyer($complaint, $item['buyer_user_id'] ?? null, $item['buyer_open_id'] ?? null);
                $count++;
            }

            return $count;
        } catch (Throwable $e) {
            Log::error("抓取投诉失败[子账户:{$accountId}]: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 自动拉黑投诉人
     *
     * 优先使用接口返回的付款人信息，若无则通过订单关联的买家表获取。
     * 将 user_id 和 buyer_open_id 永久加入黑名单。
     *
     * @param OrderComplaint $complaint   投诉记录
     * @param string|null    $buyerUserId 接口返回的用户ID
     * @param string|null    $buyerOpenId 接口返回的用户OpenID
     * @return void
     */
    private static function autoBlacklistBuyer(OrderComplaint $complaint, ?string $buyerUserId = null, ?string $buyerOpenId = null): void
    {
        // 如果接口未返回买家信息，尝试从订单买家表获取
        if (empty($buyerUserId) && empty($buyerOpenId) && !empty($complaint->trade_no)) {
            $buyer = OrderBuyer::where('trade_no', $complaint->trade_no)->first(['user_id', 'buyer_open_id']);
            if ($buyer) {
                $buyerUserId = $buyer->user_id;
                $buyerOpenId = $buyer->buyer_open_id;
            }
        }

        $reason = "消费者投诉自动拉黑，投诉单号: {$complaint->complaint_id}";

        // 拉黑 user_id
        if (!empty($buyerUserId)) {
            RiskService::addToBlacklist(Blacklist::ENTITY_TYPE_USER_ID, $buyerUserId, $reason, Blacklist::ORIGIN_AUTO_DETECTION);
        }

        // 拉黑 buyer_open_id
        if (!empty($buyerOpenId)) {
            RiskService::addToBlacklist(Blacklist::ENTITY_TYPE_USER_ID, $buyerOpenId, $reason, Blacklist::ORIGIN_AUTO_DETECTION);
        }
    }

    /**
     * 获取投诉列表（后台/API通用）
     *
     * @param array    $params     筛选参数
     * @param int      $from       偏移量
     * @param int      $limit      每页数量
     * @param int|null $merchantId 商户ID（商户API时传入，限定查询范围）
     * @return array ['list' => Collection, 'total' => int]
     */
    public static function getComplaintList(array $params, int $from = 0, int $limit = 20, ?int $merchantId = null): array
    {
        $query = OrderComplaint::with(['paymentChannelAccount:id,name', 'merchant:id,merchant_number,nickname']);

        // 商户API限定范围
        if ($merchantId !== null) {
            $query->where('merchant_id', $merchantId);
        }

        // 筛选条件
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            match ($key) {
                'complaint_id' => $query->where('complaint_id', $value),
                'trade_no' => $query->where('trade_no', $value),
                'status' => $query->where('status', $value),
                'source_api' => $query->where('source_api', $value),
                'payment_channel_account_id' => $query->where('payment_channel_account_id', $value),
                'merchant_number' => $query->where('merchant_id', Merchant::where('merchant_number', $value)->value('id')),
                'complaint_time' => is_array($value) ? $query->whereBetween('complaint_time', [$value[0], $value[1]]) : null,
                default => null,
            };
        }

        $total = $query->count();
        $list  = $query->offset($from)->limit($limit)->orderBy('id', 'desc')->get()->append(['status_text']);

        return ['list' => $list, 'total' => $total];
    }

    /**
     * 获取投诉详情
     *
     * @param int      $id         投诉记录ID
     * @param int|null $merchantId 商户ID（商户API时传入，校验权限）
     * @return OrderComplaint|null
     */
    public static function getComplaintDetail(int $id, ?int $merchantId = null): ?OrderComplaint
    {
        $query = OrderComplaint::with(['paymentChannelAccount:id,name', 'merchant:id,merchant_number,nickname', 'order:trade_no,out_trade_no,total_amount,trade_state']);
        if ($merchantId !== null) {
            $query->where('merchant_id', $merchantId);
        }
        return $query->find($id);
    }

    /**
     * 回复/留言投诉
     *
     * @param int    $id      投诉记录ID
     * @param string $content 回复内容
     * @param array  $images  回复图片URL列表
     * @return array ['state' => bool, 'message' => string]
     */
    public static function replyComplaint(int $id, string $content, array $images = []): array
    {
        $complaint = OrderComplaint::find($id);
        if (!$complaint) {
            return ['state' => false, 'message' => '投诉记录不存在'];
        }

        try {
            $info   = self::getGatewayInfoByAccountId($complaint->payment_channel_account_id);
            $fqcn   = self::loadComplaintClass($info['gateway']);
            $result = $fqcn::replyComplaint($info['config'], $complaint->complaint_id, ['reply_content' => $content, 'reply_images' => $images, 'source_api' => $complaint->source_api]);

            if ($result['state']) {
                $complaint->reply_content = mb_substr($content, 0, 2048);
                if (!empty($images)) {
                    $complaint->reply_images = $images;
                }
                $complaint->save();
            }

            return $result;
        } catch (Throwable $e) {
            return ['state' => false, 'message' => '回复失败: ' . $e->getMessage()];
        }
    }

    /**
     * 处理投诉
     *
     * @param int   $id     投诉记录ID
     * @param array $params 处理参数
     * @return array ['state' => bool, 'message' => string]
     */
    public static function processComplaint(int $id, array $params): array
    {
        $complaint = OrderComplaint::find($id);
        if (!$complaint) {
            return ['state' => false, 'message' => '投诉记录不存在'];
        }

        try {
            $info   = self::getGatewayInfoByAccountId($complaint->payment_channel_account_id);
            $fqcn   = self::loadComplaintClass($info['gateway']);
            $result = $fqcn::processComplaint($info['config'], $complaint->complaint_id, array_merge($params, ['source_api' => $complaint->source_api]));

            if ($result['state']) {
                $complaint->status = OrderComplaint::STATUS_MERCHANT_FEEDBACKED;
                if (!empty($params['feedback_content'])) {
                    $complaint->reply_content = mb_substr($params['feedback_content'], 0, 2048);
                }
                $complaint->save();
            }

            return $result;
        } catch (Throwable $e) {
            return ['state' => false, 'message' => '处理失败: ' . $e->getMessage()];
        }
    }

    /**
     * 获取已设置自动抓取的子账户ID列表
     *
     * @return array 子账户ID数组
     */
    public static function getAutoFetchAccounts(): array
    {
        $value = sys_config(self::CONFIG_GROUP, self::CONFIG_KEY);
        if (empty($value)) {
            return [];
        }
        return json_decode($value, true) ?: [];
    }

    /**
     * 设置自动抓取的子账户ID列表
     *
     * @param array $accountIds 子账户ID数组
     * @return bool
     */
    public static function setAutoFetchAccounts(array $accountIds): bool
    {
        try {
            \support\Db::table('config')->updateOrInsert(['g' => self::CONFIG_GROUP, 'k' => self::CONFIG_KEY], ['v' => json_encode(array_values(array_unique(array_map('intval', $accountIds))))]);
            // 清理 sys_config 缓存，确保配置立即生效
            clear_sys_config_cache(self::CONFIG_GROUP);
            return true;
        } catch (Throwable $e) {
            Log::error('设置自动抓取配置失败: ' . $e->getMessage());
            return false;
        }
    }
}
