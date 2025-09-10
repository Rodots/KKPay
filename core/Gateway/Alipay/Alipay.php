<?php

namespace Core\Gateway\Alipay;

class Alipay
{
    /**
     * 插件描述
     * @var array
     */
    public static array $info = [
        'title'       => '支付宝支付',
        'author'      => 'KKPay',
        'url'         => 'https://opendocs.alipay.com/open-v3/08c7f9f8_alipay.trade.pay',
        'description' => '蚂蚁集团旗下的支付宝，是以每个人为中心，以实名和信任为基础的生活平台。自2004年成立以来，支付宝已经与超过200家金融机构达成合作，为上千万小微商户提供支付服务。随着场景拓展和产品创新，拓展的服务场景不断增加，支付宝已发展成为融合了支付、生活服务、政务服务、理财、保险、公益等多个场景与行业的开放性平台。支付宝还推出了跨境支付、退税等多项服务，让中国用户在境外也能享受移动支付的便利。',
        'version'     => '1.0.0',
        'config'      => [
            [
                "field"       => "app_id",
                "type"        => "input",
                "label"       => "AppID",
                "placeholder" => "请输入支付宝AppID",
                "required"    => true,
                "maxlength"   => 32
            ],
            [
                "field"       => "app_private_key",
                "type"        => "textarea",
                "label"       => "应用私钥",
                "placeholder" => "请输入应用私钥",
                "required"    => true,
                "maxlength"   => 2048
            ],
            [
                "field"       => "alipay_public_key",
                "type"        => "textarea",
                "label"       => "支付宝公钥",
                "placeholder" => "请输入支付宝公钥",
                "required"    => true,
                "maxlength"   => 2048,
                "span"        => 24
            ],
            [
                'field'        => 'payment_types',
                'type'         => 'checkbox',
                'label'        => '支付类型',
                'required'     => true,
                'options'      => [
                    ['label' => '当面付', 'value' => 'dmf'],
                    ['label' => '订单码支付', 'value' => 'ddm'],
                    ['label' => 'APP支付', 'value' => 'app'],
                    ['label' => '手机网站支付', 'value' => 'wap'],
                    ['label' => '电脑网站支付', 'value' => 'pc'],
                ],
                'defaultValue' => ['dmf'],
            ],
            [
                'field'       => 'aes_secret_key',
                'type'        => 'input',
                'label'       => '内容加密密钥',
                'placeholder' => '请输入在支付宝开放平台设置的AES密钥（接口内容加密）',
                'maxlength'   => 512,
                'span'        => 24,
                'tooltip'     => '可选项，如未在开放平台设置，则不需要填写'
            ],
            [
                'field'   => 'sandbox_mode',
                'type'    => 'switch',
                'label'   => '沙箱模式',
                'tooltip' => '开启后将使用支付宝沙箱环境进行测试'
            ]
        ]
    ];
}
