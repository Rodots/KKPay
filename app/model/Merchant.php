<?php

declare(strict_types=1);

namespace app\model;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use support\Log;
use support\Model;
use support\Db;
use Throwable;

/**
 * 商户表
 */
class Merchant extends Model
{
    // 启用软删除
    use SoftDeletes;

    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'merchant';

    /**
     * 获取应该转换的属性。
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'status'            => 'boolean',
            'risk_status'       => 'boolean',
            'buyer_pay_fee'     => 'boolean',
            'competence'        => 'array',
            'channel_whitelist' => 'array'
        ];
    }

    /**
     * 可批量赋值的属性。
     *
     * @var array
     */
    protected $fillable = [
        'status',
    ];

    /**
     * 模型启动方法，用于注册模型事件
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($merchant) {
            // 生成一个24位的随机字符串作为商户编号，并确保不重复
            do {
                $merchant->merchant_number = 'M' . date('Y') . random(11, 'upper_and_num');
            } while (self::where('merchant_number', $merchant->merchant_number)->exists());
        });
    }

    /**
     * 访问器：创建时间
     */
    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 访问器：更新时间
     */
    protected function updatedAt(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 访问器：删除时间
     */
    protected function deletedAt(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 访问器：保证金
     */
    protected function margin(): Attribute
    {
        return Attribute::make(
            get: fn() => MerchantWallet::where('merchant_id', $this->id)->value('margin'),
        );
    }

    /**
     * 商户钱包，一对一关联
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(MerchantWallet::class, 'merchant_id', 'id');
    }

    /**
     * 商户密钥配置，一对一关联
     */
    public function encryption(): HasOne
    {
        return $this->hasOne(MerchantEncryption::class, 'merchant_id', 'id');
    }

    /**
     * 创建商户
     *
     * @param array $data 数据
     * @return Merchant 成功返回模型对象，失败抛出错误
     * @throws Exception
     */
    public static function createMerchant(array $data): Merchant
    {
        // 开启事务
        Db::beginTransaction();
        try {
            // 创建商户
            $merchantRow                    = new self();
            $merchantRow->email             = empty($data['email']) ? null : trim($data['email']);
            $merchantRow->mobile            = empty($data['mobile']) ? null : trim($data['mobile']);
            $merchantRow->remark            = empty($data['remark']) ? null : trim($data['remark']);
            $merchantRow->diy_order_subject = empty($data['diy_order_subject']) ? null : trim($data['diy_order_subject']);
            $merchantRow->buyer_pay_fee     = $data['buyer_pay_fee'];
            $merchantRow->salt              = random(4);
            $merchantRow->password          = password_hash(hash('xxh128', trim($data['password'])) . $merchantRow->salt, PASSWORD_BCRYPT);
            $merchantRow->status            = $data['status'];
            $merchantRow->risk_status       = $data['risk_status'];
            if (isset($data['competence'])) {
                $merchantRow->competence = $data['competence'];
            }
            $merchantRow->save();

            // 创建商户安全信息
            $securityRow              = new MerchantSecurity();
            $securityRow->merchant_id = $merchantRow->id;
            $securityRow->save();

            // 创建商户密钥
            $encryptionRow              = new MerchantEncryption();
            $encryptionRow->merchant_id = $merchantRow->id;
            $encryptionRow->mode        = 'only_sha3';
            $encryptionRow->hash_key    = random(32);
            $encryptionRow->save();

            // 创建商户钱包
            $walletRow              = new MerchantWallet();
            $walletRow->merchant_id = $merchantRow->id;
            $walletRow->margin      = $data['margin'];
            $walletRow->save();

            // 提交事务
            Db::commit();
        } catch (Throwable $e) {
            // 回滚事务
            Db::rollBack();
            Log::error('创建商户失败: ' . $e->getMessage());
            throw new Exception('创建失败');
        }

        return $merchantRow;
    }

    /**
     * 更新商户
     *
     * @param int   $id   商户ID
     * @param array $data 数据
     * @return true 更新是否成功
     * @throws Exception
     */
    public static function updateMerchant(int $id, array $data): true
    {
        // 开启事务
        Db::beginTransaction();
        try {
            if (!$merchant = self::find($id)) {
                throw new Exception('该商户不存在');
            }

            // 更新商户基本信息
            $merchant->email             = isset($data['email']) ? (empty($data['email']) ? null : trim($data['email'])) : $merchant->email;
            $merchant->mobile            = isset($data['mobile']) ? (empty($data['mobile']) ? null : trim($data['mobile'])) : $merchant->mobile;
            $merchant->remark            = empty($data['remark']) ? null : trim($data['remark']);
            $merchant->diy_order_subject = empty($data['diy_order_subject']) ? null : trim($data['diy_order_subject']);
            $merchant->buyer_pay_fee     = $data['buyer_pay_fee'];
            $merchant->status            = $data['status'];
            $merchant->risk_status       = $data['risk_status'];
            if (isset($data['competence'])) {
                $merchant->competence = $data['competence'];
            }
            // 更新密码
            if (isset($data['new_password'])) {
                $merchant->salt     = random(4);
                $merchant->password = password_hash(hash('xxh128', trim($data['new_password'])) . $merchant->salt, PASSWORD_BCRYPT);
            }
            $merchant->save();

            // 更新商户保证金
            if (isset($data['margin']) && is_numeric($data['margin'])) {
                MerchantWallet::where('merchant_id', $merchant->id)->update(['margin' => $data['margin']]);
            }

            // 提交事务
            Db::commit();
        } catch (Throwable $e) {
            // 回滚事务
            Db::rollBack();
            Log::error('编辑商户失败: ' . $e->getMessage());
            throw new Exception('编辑失败');
        }

        return true;
    }

    /**
     * 重置商户密码
     *
     * @param int    $id       商户ID
     * @param string $password 新密码
     *
     * @return true 重置是否成功
     * @throws Exception
     */
    public static function resetPassword(int $id, string $password): true
    {
        $merchant = self::find($id);
        if (!$merchant) {
            throw new Exception('该商户不存在');
        }

        // 更新商户密码
        $merchant->salt     = random(4);
        $merchant->password = password_hash(hash('xxh128', trim($password)) . $merchant->salt, PASSWORD_BCRYPT);

        if (!$merchant->save()) {
            throw new Exception('重置密码失败');
        }
        return true;
    }

    /**
     * 检查商户是否拥有特定权限
     *
     * @param string $permission
     * @return bool
     */
    public function hasPermission(string $permission): bool
    {
        // 如果当前实例已经加载了数据，直接用 PHP 判断，避免重复查询数据库
        if (isset($this->attributes['competence'])) {
            return in_array($permission, $this->competence ?? []);
        }

        // 如果只是通过 ID 查询（静态调用场景），或者当前实例未加载该字段
        // 使用数据库查询判断
        return $this->where('id', $this->id)
            ->whereJsonContains('competence', $permission)
            ->exists();
    }

    /**
     * 检查商户是否配置了通道白名单
     *
     * @return bool
     */
    public function hasChannelWhitelist(): bool
    {
        return !empty($this->channel_whitelist);
    }

    /**
     * 获取商户可用的通道ID列表
     *
     * @return array 返回通道ID数组，未配置返回空数组
     */
    public function getAvailableChannelIds(): array
    {
        $channels = $this->channel_whitelist;
        if (empty($channels)) {
            return [];
        }
        return array_column($channels, 'channel_id');
    }

    /**
     * 获取商户在特定通道的配置
     *
     * @param int $channelId 通道ID
     * @return array|null 返回通道配置，未配置返回null
     */
    public function getChannelConfig(int $channelId): ?array
    {
        $channels = $this->channel_whitelist;
        if (empty($channels)) {
            return null;
        }

        foreach ($channels as $channelConfig) {
            if ($channelConfig['channel_id'] === $channelId) {
                return $channelConfig;
            }
        }

        return null;
    }

    /**
     * 检查通道是否在商户白名单中
     *
     * @param int $channelId 通道ID
     * @return bool
     */
    public function isChannelAllowed(int $channelId): bool
    {
        return $this->getChannelConfig($channelId) !== null;
    }

    /**
     * 检查子账户是否在商户白名单中
     *
     * @param int $channelId 通道ID
     * @param int $accountId 子账户ID
     * @return bool
     */
    public function isAccountAllowed(int $channelId, int $accountId): bool
    {
        $channelConfig = $this->getChannelConfig($channelId);
        if ($channelConfig === null) {
            return false;
        }

        // 如果使用全部子账户，直接返回 true
        if (!empty($channelConfig['use_all_accounts'])) {
            return true;
        }

        // 检查子账户是否在白名单中
        if (empty($channelConfig['accounts'])) {
            return false;
        }

        foreach ($channelConfig['accounts'] as $accountConfig) {
            if ($accountConfig['account_id'] === $accountId) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取商户在特定通道/子账户的自定义费率
     *
     * 费率优先级：商户子账户费率 > 商户通道费率 > null（使用默认费率）
     *
     * @param int      $channelId 通道ID
     * @param int|null $accountId 子账户ID
     * @return string|null 费率（四位小数），null 表示使用默认费率
     */
    public function getMerchantRate(int $channelId, ?int $accountId = null): ?string
    {
        $channelConfig = $this->getChannelConfig($channelId);
        if ($channelConfig === null) {
            return null;
        }

        // 如果指定了子账户，先查子账户费率
        if ($accountId !== null && !empty($channelConfig['accounts'])) {
            foreach ($channelConfig['accounts'] as $accountConfig) {
                if ($accountConfig['account_id'] === $accountId && isset($accountConfig['rate']) && $accountConfig['rate'] !== null) {
                    return (string)$accountConfig['rate'];
                }
            }
        }

        // 返回通道级别的商户费率
        return isset($channelConfig['rate']) && $channelConfig['rate'] !== null ? (string)$channelConfig['rate'] : null;
    }

    /**
     * 获取商户在某通道下可用的子账户ID列表
     *
     * @param int $channelId 通道ID
     * @return array|null 返回子账户ID数组，use_all_accounts=true时返回null表示使用全部
     */
    public function getAllowedAccountIds(int $channelId): ?array
    {
        $channelConfig = $this->getChannelConfig($channelId);
        if ($channelConfig === null) {
            return [];
        }

        // 使用全部子账户
        if (!empty($channelConfig['use_all_accounts'])) {
            return null;
        }

        // 返回配置的子账户ID列表
        if (empty($channelConfig['accounts'])) {
            return [];
        }

        return array_column($channelConfig['accounts'], 'account_id');
    }
}
