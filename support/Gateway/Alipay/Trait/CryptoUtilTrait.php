<?php

declare(strict_types = 1);

namespace Gateway\Alipay\Trait;

/**
 * 加密辅助工具特征
 *
 * 提供
 * - PKCS7 填充/去填充（块大小 16）
 * - 随机字符串生成（用于降级场景）
 *
 * 注意
 * - 该特征不直接执行加解密，仅提供通用工具方法
 */
trait CryptoUtilTrait
{
    /**
     * 为字符串添加 PKCS7 填充
     *
     * 参数
     * - source: 待填充明文
     * - blockSize: 块大小（默认 16）
     *
     * 返回
     * - 追加了填充字节的字符串
     */
    protected function addPKCS7Padding(string $source, int $blockSize = 16): string
    {
        $source = trim($source);
        $pad    = $blockSize - (strlen($source) % $blockSize);
        return $source . str_repeat(chr($pad), $pad);
    }

    /**
     * 移除 PKCS7 填充
     *
     * 行为
     * - 校验末尾填充长度与填充字节一致性，不合法时原样返回
     */
    protected function stripPKCS7Padding(string $source): string
    {
        if (empty($source)) {
            return $source;
        }

        $pad = ord(substr($source, -1));

        // 验证填充的有效性
        if ($pad > 16 || $pad <= 0) {
            return $source;
        }

        // 检查填充字节是否一致
        $paddingBytes = substr($source, -$pad);
        if (str_repeat(chr($pad), $pad) !== $paddingBytes) {
            return $source;
        }

        return substr($source, 0, -$pad);
    }
}
