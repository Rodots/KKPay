<?php

declare(strict_types = 1);

namespace app\model;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use support\Model;

/**
 * 管理员操作日志表
 */
class AdminLog extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'admin_log';

    /**
     * 禁用自动写入updated_at
     *
     * @var null
     */
    const null UPDATED_AT = null;

    /**
     * 获取应该转换的属性。
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'admin_id' => 'integer'
        ];
    }

    /**
     * 获取创建时间
     */
    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 获取对应管理员信息
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
