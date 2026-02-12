/**
 * 收银台交互模块
 * 支持动态主题色切换、订单过期检查、手风琴展开/收起、加载动画
 * 基于 ES2020 语言规范
 */

const CheckoutModule = (() => {
    'use strict';

    // DOM 查询辅助
    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => [...document.querySelectorAll(sel)];

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

    // DOM 元素缓存
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
        dismissLoader();
    };

    /**
     * 缓存 DOM 元素引用
     */
    const cacheElements = () => {
        Object.assign(elements, {
            root: document.documentElement,
            container: $('.checkout-container'),
            payBtn: $('#payBtn'),
            modalOverlay: $('#modalOverlay'),
            modalClose: $('#modalClose'),
            cancelBtn: $('#cancelBtn'),
            confirmBtn: $('#confirmBtn'),
            selectedMethodName: $('#selectedMethodName'),
            toast: $('#toast'),
            toastMessage: $('.toast-message'),
            paymentOptions: $$('.payment-option'),
            expireTimeEl: $('#expireTime'),
            orderStatus: $('#orderStatus'),
            paymentSection: $('#paymentSection'),
            checkoutFooter: $('#checkoutFooter'),
            expiredNotice: $('#expiredNotice'),
            formContainer: $('#formContainer'),
            loadingOverlay: $('#loadingOverlay'),
            detailsToggle: $('#detailsToggle'),
            orderDetails: $('#orderDetails')
        });
    };

    // ==================== Loading 控制 ====================

    /**
     * 释放 Loading 遮罩
     * 使用双 rAF 确保主题色渲染完成后再淡出
     */
    const dismissLoader = () => {
        const loader = elements.loadingOverlay;
        if (!loader) return;

        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                loader.classList.add('fade-out');
                loader.addEventListener('transitionend', () => loader.remove(), { once: true });
            });
        });
    };

    // ==================== 手风琴（订单详情折叠） ====================

    /**
     * 切换订单详情展开/收起
     */
    const toggleDetails = () => {
        const { orderDetails, detailsToggle } = elements;
        if (!orderDetails || !detailsToggle) return;

        const isExpanded = detailsToggle.getAttribute('aria-expanded') === 'true';
        detailsToggle.setAttribute('aria-expanded', String(!isExpanded));
        orderDetails.classList.toggle('expanded');
    };

    // ==================== SessionStorage 支付方式记忆 ====================

    /**
     * 从 sessionStorage 加载上次使用的支付方式
     */
    const loadLastUsedMethod = () => {
        try {
            const lastMethod = sessionStorage.getItem(STORAGE_KEY);
            if (!lastMethod) return;

            const targetOption = elements.paymentOptions?.find(
                option => option.dataset.method === lastMethod
            );
            const input = targetOption?.querySelector('input[type="radio"]');
            if (!input) return;

            // 取消所有选中，选中目标
            elements.paymentOptions?.forEach(opt => {
                opt.querySelector('input[type="radio"]').checked = false;
            });
            input.checked = true;

            state.selectedMethod = lastMethod;
            state.selectedThemeColor = targetOption.dataset.themeColor ?? '#1677FF';
            showLastUsedTag(lastMethod);
        } catch {
            // sessionStorage 不可用时静默忽略
        }
    };

    /**
     * 显示 "上次使用" 标识
     */
    const showLastUsedTag = (methodName) => {
        // 清除已有标识
        elements.paymentOptions?.forEach(option => {
            option.querySelector('.payment-name .tag.last-used')?.remove();
        });

        // 为目标支付方式添加标识
        const nameContainer = elements.paymentOptions
            ?.find(option => option.dataset.method === methodName)
            ?.querySelector('.payment-name');

        if (nameContainer) {
            const tag = document.createElement('span');
            tag.className = 'tag last-used';
            tag.textContent = '上次使用';
            nameContainer.appendChild(tag);
        }
    };

    /**
     * 保存支付方式到 sessionStorage
     */
    const saveLastUsedMethod = (methodName) => {
        try {
            sessionStorage.setItem(STORAGE_KEY, methodName);
        } catch {
            // 静默忽略
        }
    };

    // ==================== 主题色 ====================

    /**
     * 初始化主题色（基于当前选中的支付方式）
     */
    const initTheme = () => {
        if (state.isExpired) return;

        const checkedOption = $('.payment-option input[type="radio"]:checked')?.closest('.payment-option');
        const methodName = checkedOption?.dataset.method;
        const themeColor = checkedOption?.dataset.themeColor;

        if (methodName && themeColor) {
            state.selectedMethod = methodName;
            state.selectedThemeColor = themeColor;
            applyThemeColor(themeColor);
            updateSelectedMethod(methodName);
        }
    };

    /**
     * 应用主题色到 CSS 变量
     */
    const applyThemeColor = (hexColor) => {
        const { r, g, b } = hexToRgb(hexColor);
        const style = elements.root.style;

        style.setProperty('--color-primary', hexColor);
        style.setProperty('--color-primary-rgb', `${r}, ${g}, ${b}`);
        style.setProperty('--color-primary-hover', adjustBrightness(hexColor, 20));
        style.setProperty('--color-primary-active', adjustBrightness(hexColor, -20));
        style.setProperty('--color-primary-light', `rgba(${r}, ${g}, ${b}, 0.06)`);
    };

    /**
     * 十六进制转 RGB
     */
    const hexToRgb = (hex) => {
        const n = parseInt(hex.replace('#', ''), 16);
        return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
    };

    /**
     * 调整颜色亮度
     */
    const adjustBrightness = (hex, percent) => {
        const { r, g, b } = hexToRgb(hex);
        const clamp = (v) => Math.max(0, Math.min(255, Math.round(v + percent * 2.55)));
        return `#${((1 << 24) + (clamp(r) << 16) + (clamp(g) << 8) + clamp(b)).toString(16).slice(1)}`;
    };

    /**
     * 更新显示的支付方式名称
     */
    const updateSelectedMethod = (methodName) => {
        if (elements.selectedMethodName) {
            elements.selectedMethodName.textContent = methodName;
        }
    };

    // ==================== 订单过期 ====================

    /**
     * 初始化订单过期检查
     */
    const initOrderExpiry = () => {
        const expireTimeStr = elements.expireTimeEl?.textContent?.trim();
        if (!expireTimeStr) return;

        state.expireTime = new Date(expireTimeStr.replace(/-/g, '/'));
        checkExpiry();

        if (!state.isExpired) {
            state.countdownInterval = setInterval(checkExpiry, 1000);
        }
    };

    /**
     * 检查订单是否过期
     */
    const checkExpiry = () => {
        if (state.isExpired || !state.expireTime) return;

        const timeLeft = state.expireTime.getTime() - Date.now();
        timeLeft <= 0 ? handleOrderExpired() : updateCountdown(timeLeft);
    };

    /**
     * 更新倒计时显示
     */
    const updateCountdown = (timeLeft) => {
        const minutes = Math.floor(timeLeft / 60000);
        const seconds = Math.floor((timeLeft % 60000) / 1000);
        const timeStr = `${minutes}分${String(seconds).padStart(2, '0')}秒`;

        if (minutes < 5) {
            elements.expireTimeEl?.classList.add('expired');
        }

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

        if (elements.expireTimeEl) {
            elements.expireTimeEl.textContent = '已过期';
            elements.expireTimeEl.classList.add('expired');
        }

        if (elements.orderStatus) {
            elements.orderStatus.textContent = '订单已过期';
        }

        // 隐藏支付区域
        if (elements.paymentSection) elements.paymentSection.style.display = 'none';
        if (elements.checkoutFooter) elements.checkoutFooter.style.display = 'none';
        if (elements.expiredNotice) elements.expiredNotice.style.display = 'flex';

        elements.container?.classList.add('is-expired');
        closeModal();

        // 禁用所有支付方式
        elements.paymentOptions?.forEach(option => {
            const input = option.querySelector('input[type="radio"]');
            if (input) input.disabled = true;
            option.style.pointerEvents = 'none';
            option.style.opacity = '0.5';
        });
    };

    // ==================== 事件绑定 ====================

    /**
     * 绑定所有事件监听
     */
    const bindEvents = () => {
        // 手风琴折叠
        elements.detailsToggle?.addEventListener('click', toggleDetails);

        if (state.isExpired) return;

        // 支付方式选择
        elements.paymentOptions?.forEach(option => {
            option.addEventListener('change', handlePaymentChange);
        });

        // 支付按钮 & 弹窗控制
        elements.payBtn?.addEventListener('click', showConfirmModal);
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

        const { method: methodName, themeColor } = e.currentTarget.dataset;

        if (methodName && themeColor) {
            state.selectedMethod = methodName;
            state.selectedThemeColor = themeColor;
            updateSelectedMethod(methodName);
            applyThemeColor(themeColor);

            // 选中动画反馈
            e.currentTarget.querySelector('.option-card')?.animate([
                { transform: 'scale(1)' },
                { transform: 'scale(0.98)' },
                { transform: 'scale(1)' }
            ], { duration: 200, easing: 'cubic-bezier(0.4, 0, 0.2, 1)' });
        }
    };

    // ==================== 弹窗 & 支付 ====================

    /**
     * 显示确认弹窗
     */
    const showConfirmModal = () => {
        if (state.isProcessing || state.isExpired || !elements.modalOverlay) return;

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
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" style="display:inline-block;vertical-align:middle;margin-right:0.375rem;animation:spin 1s linear infinite"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-dasharray="60" stroke-dashoffset="20"/></svg>处理中...';
            }

            const checkedRadio = document.querySelector('input[name="payment"]:checked');
            if (!checkedRadio) {
                showToast('请选择支付方式');
                return;
            }

            const tradeNo = window.location.pathname.match(/\/checkout\/([^/]+)\.html/)?.[1];
            if (!tradeNo) {
                showToast('订单信息异常');
                return;
            }

            const resp = await fetch(`/checkout/${tradeNo}/pay`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `payment_type=${encodeURIComponent(checkedRadio.value)}`
            });
            const result = await resp.json();

            if (result.state && result.data) {
                saveLastUsedMethod(state.selectedMethod);
                closeModal();
                handlePaymentResult(result.data);
            } else {
                showToast(result.message || '支付失败');
            }
        } catch (error) {
            showToast('网络错误，请重试');
            console.error('Payment error:', error);
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = originalText;
            }
            state.isProcessing = false;
        }
    };

    /**
     * 处理支付结果
     */
    const handlePaymentResult = (data) => {
        const { pay_type, pay_info } = data;

        if (pay_type === 'redirect' || pay_type === 'qrcode') {
            window.location.href = pay_info;
        } else if (pay_type === 'html') {
            const fc = elements.formContainer;
            if (fc) {
                fc.innerHTML = pay_info;
                fc.querySelector('form')?.submit();
            }
        } else {
            showToast('请完成支付');
        }
    };

    /**
     * 显示 Toast 提示
     */
    const showToast = (message, duration = 3000) => {
        if (!elements.toast || !elements.toastMessage) return;

        elements.toastMessage.textContent = message;
        elements.toast.classList.add('show');

        setTimeout(() => {
            elements.toast.classList.remove('show');
        }, duration);
    };

    return { init };
})();

// DOM 就绪后初始化
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => CheckoutModule.init());
} else {
    CheckoutModule.init();
}
