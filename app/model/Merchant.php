<?php

declare(strict_types = 1);

namespace App\model;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
     * 获取创建时间
     */
    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 访问器：更新时间
     */
    protected function updatedAt(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->format('Y-m-d H:i:s') : null,
        );
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
            $merchantRow              = new self();
            $merchantRow->email       = empty($data['email']) ? null : trim($data['email']);
            $merchantRow->phone       = empty($data['phone']) ? null : trim($data['phone']);
            $merchantRow->remark      = empty($data['remark']) ? null : trim($data['remark']);
            $merchantRow->salt        = random(4);
            $merchantRow->password    = password_hash(hash('xxh128', trim($data['password'])) . $merchantRow->salt, PASSWORD_BCRYPT);
            $merchantRow->status      = (int)$data['status'];
            $merchantRow->risk_status = (int)$data['risk_status'];
            $merchantRow->save();

            // 创建商户安全信息
            $securityRow              = new MerchantSecurity();
            $securityRow->merchant_id = $merchantRow->id;
            $securityRow->save();

            // 创建商户密钥
            $encryptionRow              = new MerchantEncryption();
            $encryptionRow->merchant_id = $merchantRow->id;
            $encryptionRow->sha3_key    = random(32);
            $encryptionRow->save();

            // 创建商户钱包
            $walletRow              = new MerchantWallet();
            $walletRow->merchant_id = $merchantRow->id;
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
            $merchant = self::find($id);
            if (!$merchant) {
                throw new Exception('该商户不存在');
            }

            // 更新商户基本信息
            $merchant->email       = isset($data['email']) ? (empty($data['email']) ? null : trim($data['email'])) : $merchant->email;
            $merchant->phone       = isset($data['phone']) ? (empty($data['phone']) ? null : trim($data['phone'])) : $merchant->phone;
            $merchant->remark      = empty($data['remark']) ? null : trim($data['remark']);
            $merchant->status      = (int)$data['status'];
            $merchant->risk_status = (int)$data['risk_status'];

            // 更新密码
            if (isset($data['new_password'])) {
                $merchant->salt     = random(4);
                $merchant->password = password_hash(hash('xxh128', trim($data['new_password'])) . $merchant->salt, PASSWORD_BCRYPT);
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
            Log::error('编辑商户失败: ' . $e->getMessage());
            throw new Exception('编辑失败');
        }

        return true;
    }

    /**
     * 重置商户密码为123456
     *
     * @param int $id 商户ID
     * @return true 重置是否成功
     * @throws Exception
     */
    public static function resetPassword(int $id): true
    {
        $merchant = self::find($id);
        if (!$merchant) {
            throw new Exception('该商户不存在');
        }

        // 更新商户密码
        $merchant->salt     = random(4);
        $merchant->password = password_hash(hash('xxh128', '123456') . $merchant->salt, PASSWORD_BCRYPT);

        if (!$merchant->save()) {
            throw new Exception('重置密码失败');
        }
        return true;
    }
}
