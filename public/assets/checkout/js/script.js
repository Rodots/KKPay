/**
 * 收银台交互模块
 * 支持动态主题色切换、订单过期检查
 */

const CheckoutModule = (() => {
    'use strict';

    // DOM 元素缓存
    const $ = (selector) => document.querySelector(selector);
    const $$ = (selector) => [...document.querySelectorAll(selector)];

    // SessionStorage Key
    const STORAGE_KEY = 'lastPaymentMethod';

    // 状态管理
    const state = {
        selectedMethod: '支付宝',
        selectedThemeColor: '#1677FF',
        isProcessing: false,
        isExpired: false,
        expireTime: null,
        countdownInterval: null
    };

    // DOM 元素引用
    const elements = {};

    /**
     * 初始化模块
     */
    const init = () => {
        cacheElements();
        loadLastUsedMethod();
        initTheme();
        initOrderExpiry();
        bindEvents();
    };

    /**
     * 从 sessionStorage 加载上次使用的支付方式
     */
    const loadLastUsedMethod = () => {
        try {
            const lastMethod = sessionStorage.getItem(STORAGE_KEY);
            if (lastMethod) {
                // 查找对应的支付方式选项
                const targetOption = elements.paymentOptions?.find(option =>
                    option.dataset.method === lastMethod
                );
                if (targetOption) {
                    // 选中该支付方式
                    const input = targetOption.querySelector('input[type="radio"]');
                    if (input) {
                        // 取消其他选中状态
                        elements.paymentOptions?.forEach(opt => {
                            opt.querySelector('input[type="radio"]').checked = false;
                        });
                        input.checked = true;

                        // 更新状态
                        state.selectedMethod = lastMethod;
                        state.selectedThemeColor = targetOption.dataset.themeColor;

                        // 显示"上次使用"标识
                        showLastUsedTag(lastMethod);
                    }
                }
            }
        } catch (e) {
            console.warn('无法读取 sessionStorage:', e);
        }
    };

    /**
     * 显示"上次使用"标识
     */
    const showLastUsedTag = (methodName) => {
        // 先清除所有"上次使用"标识
        elements.paymentOptions?.forEach(option => {
            const nameContainer = option.querySelector('.payment-name');
            const existingTag = nameContainer?.querySelector('.tag.last-used');
            if (existingTag) {
                existingTag.remove();
            }
        });

        // 为指定的支付方式添加"上次使用"标识
        const targetOption = elements.paymentOptions?.find(option =>
            option.dataset.method === methodName
        );
        if (targetOption) {
            const nameContainer = targetOption.querySelector('.payment-name');
            if (nameContainer) {
                const lastUsedTag = document.createElement('span');
                lastUsedTag.className = 'tag last-used';
                lastUsedTag.textContent = '上次使用';
                nameContainer.appendChild(lastUsedTag);
            }
        }
    };

    /**
     * 保存支付方式到 sessionStorage
     */
    const saveLastUsedMethod = (methodName) => {
        try {
            sessionStorage.setItem(STORAGE_KEY, methodName);
        } catch (e) {
            console.warn('无法写入 sessionStorage:', e);
        }
    };

    const cacheElements = () => {
        elements.root = document.documentElement;
        elements.container = $('.checkout-container');
        elements.payBtn = $('#payBtn');
        elements.modalOverlay = $('#modalOverlay');
        elements.modalClose = $('#modalClose');
        elements.cancelBtn = $('#cancelBtn');
        elements.confirmBtn = $('#confirmBtn');
        elements.selectedMethodName = $('#selectedMethodName');
        elements.modalOrderId = $('#modalOrderId');
        elements.toast = $('#toast');
        elements.toastMessage = $('.toast-message');
        elements.paymentOptions = $$('.payment-option');
        elements.expireTimeEl = $('#expireTime');
        elements.orderStatus = $('#orderStatus');
        elements.paymentSection = $('#paymentSection');
        elements.checkoutFooter = $('#checkoutFooter');
        elements.expiredNotice = $('#expiredNotice');
        elements.formContainer = $('#formContainer');
    };

    /**
     * 初始化订单过期检查
     */
    const initOrderExpiry = () => {
        const expireTimeStr = elements.expireTimeEl?.textContent?.trim();
        if (!expireTimeStr) return;

        state.expireTime = new Date(expireTimeStr.replace(/-/g, '/'));

        // 立即检查一次
        checkExpiry();

        // 每秒检查一次
        if (!state.isExpired) {
            state.countdownInterval = setInterval(checkExpiry, 1000);
        }
    };

    /**
     * 检查订单是否过期
     */
    const checkExpiry = () => {
        if (state.isExpired || !state.expireTime) return;

        const now = new Date();
        const timeLeft = state.expireTime.getTime() - now.getTime();

        if (timeLeft <= 0) {
            handleOrderExpired();
        } else {
            updateCountdown(timeLeft);
        }
    };

    /**
     * 更新倒计时显示
     * @param {number} timeLeft - 剩余毫秒数
     */
    const updateCountdown = (timeLeft) => {
        const minutes = Math.floor(timeLeft / 60000);
        const seconds = Math.floor((timeLeft % 60000) / 1000);
        const timeStr = `${minutes}分${seconds.toString().padStart(2, '0')}秒`;

        // 少于5分钟显示警告
        if (minutes < 5) {
            elements.expireTimeEl?.classList.add('expired');
        }

        // 更新状态文本
        if (elements.orderStatus) {
            elements.orderStatus.textContent = `订单已创建，请在 ${timeStr} 内完成付款。`;
        }
    };

    /**
     * 处理订单过期
     */
    const handleOrderExpired = () => {
        state.isExpired = true;
        clearInterval(state.countdownInterval);

        // 更新过期时间显示
        if (elements.expireTimeEl) {
            elements.expireTimeEl.textContent = '已过期';
            elements.expireTimeEl.classList.add('expired');
        }

        // 更新状态文本
        if (elements.orderStatus) {
            elements.orderStatus.textContent = '订单已过期';
        }

        // 隐藏支付区域和底部栏
        if (elements.paymentSection) {
            elements.paymentSection.style.display = 'none';
        }
        if (elements.checkoutFooter) {
            elements.checkoutFooter.style.display = 'none';
        }

        // 显示过期提示
        if (elements.expiredNotice) {
            elements.expiredNotice.style.display = 'flex';
        }

        // 添加过期样式类
        elements.container?.classList.add('is-expired');

        // 关闭弹窗
        closeModal();

        // 禁用所有支付方式选择
        elements.paymentOptions?.forEach(option => {
            const input = option.querySelector('input[type="radio"]');
            if (input) {
                input.disabled = true;
            }
            option.style.pointerEvents = 'none';
            option.style.opacity = '0.5';
        });
    };

    /**
     * 初始化主题色
     */
    const initTheme = () => {
        if (state.isExpired) return;

        const checkedOption = $('.payment-option input[type="radio"]:checked');
        if (checkedOption) {
            const option = checkedOption.closest('.payment-option');
            const methodName = option?.dataset.method;
            const themeColor = option?.dataset.themeColor;

            if (methodName && themeColor) {
                state.selectedMethod = methodName;
                state.selectedThemeColor = themeColor;
                applyThemeColor(themeColor);
                updateSelectedMethod(methodName);
                updateModalOrderId();
            }
        }
    };

    /**
     * 绑定事件监听
     */
    const bindEvents = () => {
        if (state.isExpired) return;

        // 支付方式选择
        elements.paymentOptions?.forEach(option => {
            option.addEventListener('change', handlePaymentChange);
        });

        // 支付按钮
        elements.payBtn?.addEventListener('click', showConfirmModal);

        // 弹窗控制
        elements.modalClose?.addEventListener('click', closeModal);
        elements.cancelBtn?.addEventListener('click', closeModal);
        elements.confirmBtn?.addEventListener('click', handleConfirmPayment);

        // 点击遮罩关闭
        elements.modalOverlay?.addEventListener('click', (e) => {
            if (e.target === elements.modalOverlay) closeModal();
        });

        // ESC 键关闭
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && elements.modalOverlay?.classList.contains('active')) {
                closeModal();
            }
        });
    };

    /**
     * 处理支付方式变更
     */
    const handlePaymentChange = (e) => {
        if (state.isExpired) return;

        const option = e.currentTarget;
        const methodName = option.dataset.method;
        const themeColor = option.dataset.themeColor;

        if (methodName && themeColor) {
            state.selectedMethod = methodName;
            state.selectedThemeColor = themeColor;

            updateSelectedMethod(methodName);
            applyThemeColor(themeColor);

            // 添加选中动画反馈
            const card = option.querySelector('.option-card');
            card?.animate([
                { transform: 'scale(1)' },
                { transform: 'scale(0.98)' },
                { transform: 'scale(1)' }
            ], {
                duration: 200,
                easing: 'cubic-bezier(0.4, 0, 0.2, 1)'
            });
        }
    };

    /**
     * 应用主题色到 CSS 变量
     */
    const applyThemeColor = (hexColor) => {
        const rgb = hexToRgb(hexColor);
        const hoverColor = adjustBrightness(hexColor, 20);
        const activeColor = adjustBrightness(hexColor, -20);
        const lightColor = `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, 0.06)`;

        elements.root.style.setProperty('--color-primary', hexColor);
        elements.root.style.setProperty('--color-primary-rgb', `${rgb.r}, ${rgb.g}, ${rgb.b}`);
        elements.root.style.setProperty('--color-primary-hover', hoverColor);
        elements.root.style.setProperty('--color-primary-active', activeColor);
        elements.root.style.setProperty('--color-primary-light', lightColor);
    };

    /**
     * 十六进制转 RGB
     */
    const hexToRgb = (hex) => {
        const cleanHex = hex.replace('#', '');
        const bigint = parseInt(cleanHex, 16);
        return {
            r: (bigint >> 16) & 255,
            g: (bigint >> 8) & 255,
            b: bigint & 255
        };
    };

    /**
     * 调整颜色亮度
     */
    const adjustBrightness = (hex, percent) => {
        const rgb = hexToRgb(hex);
        const adjust = (value) => {
            const adjusted = value + (percent * 2.55);
            return Math.max(0, Math.min(255, Math.round(adjusted)));
        };

        const r = adjust(rgb.r);
        const g = adjust(rgb.g);
        const b = adjust(rgb.b);

        return `#${((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1)}`;
    };

    /**
     * 更新显示的支付方式名称
     */
    const updateSelectedMethod = (methodName) => {
        if (elements.selectedMethodName) {
            elements.selectedMethodName.textContent = methodName;
        }
    };

    /**
     * 更新弹窗中的商家订单号
     */
    const updateModalOrderId = () => {
        const orderId = $('#orderId')?.textContent?.trim();
        if (orderId && elements.modalOrderId) {
            elements.modalOrderId.textContent = orderId;
        }
    };

    /**
     * 显示确认弹窗
     */
    const showConfirmModal = () => {
        if (state.isProcessing || state.isExpired || !elements.modalOverlay) return;

        updateModalOrderId();
        elements.modalOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    };

    /**
     * 关闭弹窗
     */
    const closeModal = () => {
        if (!elements.modalOverlay) return;

        elements.modalOverlay.classList.remove('active');
        document.body.style.overflow = '';
    };

    /**
     * 处理确认支付
     */
    const handleConfirmPayment = async () => {
        if (state.isProcessing || state.isExpired) return;

        state.isProcessing = true;
        const btn = elements.confirmBtn;
        const originalText = btn?.textContent;

        try {
            // 按钮加载状态
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = createSpinnerHTML() + ' 处理中...';
            }

            // 获取选中的支付方式类型
            const checkedRadio = document.querySelector('input[name="payment"]:checked');
            if (!checkedRadio) {
                showToast('请选择支付方式');
                return;
            }
            const paymentType = checkedRadio.value;

            // 从 URL 提取平台订单号
            const tradeNo = window.location.pathname.match(/\/checkout\/([^/]+)\.html/)?.[1];
            if (!tradeNo) {
                showToast('订单信息异常');
                return;
            }

            // 发起支付请求
            const resp = await fetch(`/checkout/${tradeNo}/pay`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `payment_type=${encodeURIComponent(paymentType)}`
            });
            const result = await resp.json();

            if (result.state && result.data) {
                // 保存支付方式到 sessionStorage
                saveLastUsedMethod(state.selectedMethod);
                // 关闭弹窗
                closeModal();
                // 处理支付结果
                handlePaymentResult(result.data);
            } else {
                showToast(result.message || '支付失败');
            }
        } catch (error) {
            showToast('网络错误，请重试');
            console.error('Payment error:', error);
        } finally {
            // 恢复按钮状态
            if (btn) {
                btn.disabled = false;
                btn.textContent = originalText;
            }
            state.isProcessing = false;
        }
    };

    /**
     * 处理支付结果
     * @param {Object} data - 支付结果数据
     */
    const handlePaymentResult = (data) => {
        if (data.pay_type === 'redirect' || data.pay_type === 'qrcode') {
            window.location.href = data.pay_info;
        } else if (data.pay_type === 'html') {
            const fc = elements.formContainer;
            if (fc) {
                fc.innerHTML = data.pay_info;
                fc.querySelector('form')?.submit();
            }
        } else {
            showToast('请完成支付');
        }
    };

    /**
     * 创建 Spinner SVG HTML
     */
    const createSpinnerHTML = () => {
        return `
            <svg class="spinner" viewBox="0 0 24 24" width="16" height="16" style="display: inline-block; vertical-align: middle; margin-right: 6px; animation: spin 1s linear infinite;">
                <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-dasharray="60" stroke-dashoffset="20"/>
            </svg>
        `;
    };

    /**
     * 显示 Toast 提示
     */
    const showToast = (message, duration = 3000) => {
        if (!elements.toast || !elements.toastMessage) return;

        elements.toastMessage.textContent = message;
        elements.toast.classList.add('show');

        // 自动隐藏
        setTimeout(() => {
            elements.toast.classList.remove('show');
        }, duration);
    };

    // 公开 API
    return { init };
})();

// DOM 就绪后初始化
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        CheckoutModule.init();
    });
} else {
    CheckoutModule.init();
}
