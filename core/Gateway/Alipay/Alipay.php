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
        'notes'       => '<p>选择可用的支付类型，注意只能选择已经签约的产品，否则会无法支付！</p><p>如果使用<span class="text-green-600">公钥证书</span>模式对接，需将<span class="text-green-600">应用公钥证书</span>、<span class="text-green-600">支付宝公钥证书</span>、<span class="text-green-600">支付宝根证书</span>共<b>3</b>个<span class="text-destructive">.crt</span>文件放置于<span class="text-blue-600">/core/Gateway/Alipay/cert/支付宝AppID/</span>文件夹</p>',
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
                "placeholder" => "请输入支付宝公钥，填错也可以支付成功但会导致无法回调，如果用公钥证书模式此处留空不填",
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
