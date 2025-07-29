<?php

declare(strict_types = 1);

namespace app\model;

use Illuminate\Database\Eloquent\SoftDeletes;
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
            'status'      => 'boolean',
            'risk_status' => 'boolean',
            'competence'  => 'array'
        ];
    }

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
                $merchant->merchant_number = 'M' . date('Y') . random(19, 'upper_and_num');
            } while (self::where('merchant_number', $merchant->merchant_number)->exists());
        });
    }

    /**
     * 创建商户
     *
     * @param array $data 商户数据
     * @return Merchant|false 成功返回商户对象，失败返回false
     * @throws Throwable
     */
    public static function createMerchant(array $data): false|Merchant
    {
        // 开启事务
        Db::beginTransaction();
        try {
            // 创建商户
            $merchant           = new self();
            $merchant->group_id = $data['group_id'];
            $merchant->email    = empty($data['email']) ? null : trim($data['email']);
            $merchant->phone    = empty($data['phone']) ? null : trim($data['phone']);
            $merchant->qq       = empty($data['qq']) ? null : trim($data['qq']);
            $merchant->salt     = random(4);
            $merchant->password = password_hash(hash('SHA3-256', $merchant->salt . $data['password'] . 'kkpay'), PASSWORD_DEFAULT);
            $merchant->save();

            // 创建商户安全信息
            $securityRow              = new MerchantSecurity();
            $securityRow->merchant_id = $merchant->id;
            $securityRow->save();

            // 创建商户密钥
            $encryptionRow              = new MerchantEncryption();
            $encryptionRow->merchant_id = $merchant->id;
            $encryptionRow->aes_key     = random(32);
            $encryptionRow->sha3_key    = random(32);
            $encryptionRow->save();

            // 创建人民币钱包
            $walletRow              = new MerchantWallet();
            $walletRow->merchant_id = $merchant->id;
            $walletRow->currency    = 'CNY';
            $walletRow->save();

            // 提交事务
            Db::commit();
        } catch (Throwable $e) {
            // 回滚事务
            Db::rollBack();
            throw $e;
        }

        return $merchant;
    }

    /**
     * 更新商户信息
     *
     * @param int   $id   商户ID
     * @param array $data 更新数据
     * @return true 更新是否成功
     * @throws Throwable
     */
    public static function updateMerchant(int $id, array $data): true
    {
        // 开启事务
        Db::beginTransaction();
        try {
            $merchant = self::find($id);
            if (!$merchant) {
                throw new \Exception('该商户不存在');
            }

            // 更新商户基本信息
            $merchant->group_id = $data['group_id'] ?? $merchant->group_id;
            $merchant->email    = isset($data['email']) ? (empty($data['email']) ? null : trim($data['email'])) : $merchant->email;
            $merchant->phone    = isset($data['phone']) ? (empty($data['phone']) ? null : trim($data['phone'])) : $merchant->phone;
            $merchant->qq       = isset($data['qq']) ? (empty($data['qq']) ? null : trim($data['qq'])) : $merchant->qq;

            // 更新状态和权限
            if (isset($data['status'])) {
                $merchant->status = (int)$data['status'];
            }

            if (isset($data['competence'])) {
                $merchant->competence = $data['competence'];
            }

            $merchant->save();

            // 提交事务
            Db::commit();
        } catch (Throwable $e) {
            // 回滚事务
            Db::rollBack();
            throw $e;
        }

        return true;
    }
}
