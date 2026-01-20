'use strict';

// 状态管理
const state = {
    pollTimer: null,
    isPolling: true,
    pollStartTime: null,
    manualQueryShown: false,
    buttonLocks: new Map() // 按钮防抖锁
};

// 常量配置
const CONFIG = {
    AUTO_POLL_TIMEOUT: 180000, // 3分钟后暂停自动轮询
    NORMAL_POLL_DELAY: 5000,
    ERROR_POLL_DELAY: 10000,
    INITIAL_POLL_DELAY: 8000,
    TOAST_DURATION: 3000,
    DEBOUNCE_DELAY: 1000 // 按钮防抖延迟
};

// 工具函数
const $ = (selector) => document.querySelector(selector);
const $id = (id) => document.getElementById(id);

/**
 * 按钮防抖包装器
 */
const debounceButton = (buttonId, handler) => {
    if (state.buttonLocks.get(buttonId)) return;

    state.buttonLocks.set(buttonId, true);

    try {
        handler();
    } finally {
        setTimeout(() => state.buttonLocks.set(buttonId, false), CONFIG.DEBOUNCE_DELAY);
    }
};

/**
 * 异步按钮防抖包装器
 */
const debounceButtonAsync = async (buttonId, handler) => {
    if (state.buttonLocks.get(buttonId)) return;

    state.buttonLocks.set(buttonId, true);

    try {
        await handler();
    } finally {
        setTimeout(() => state.buttonLocks.set(buttonId, false), CONFIG.DEBOUNCE_DELAY);
    }
};

/**
 * 切换订单详情展开/收起
 */
let toggleOrderInfo = () => {
    const orderInfo = $id('orderInfo');
    const toggleButton = $('.order-toggle');
    const statusText = toggleButton?.querySelector('span:last-child');

    if (!orderInfo || !toggleButton || !statusText) return;

    const isExpanded = orderInfo.classList.toggle('expanded');
    toggleButton.classList.toggle('expanded', isExpanded);
    statusText.textContent = isExpanded ? '点击收起' : '';
};

/**
 * 显示Toast提示
 */
const showToast = (message, type = 'info') => {
    const toastContainer = $id('toastContainer');
    if (!toastContainer) return;

    const iconMap = { success: '✓', error: '✕', info: 'i' };

    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.innerHTML = `
        <div class="toast-icon ${type}">${iconMap[type] ?? 'i'}</div>
        <div class="toast-message">${message}</div>
    `;

    toastContainer.appendChild(toast);

    setTimeout(() => {
        toast.classList.add('hiding');
        setTimeout(() => toast.remove(), 300);
    }, CONFIG.TOAST_DURATION);
};

/**
 * 跳转支付宝App（带防抖）
 */
let handleAlipayRedirect = () => {
    debounceButton('alipayButton', () => {
        const alipayUrl = `alipays://platformapi/startapp?appId=20000067&url=${encodeURIComponent(paymentUrl)}`;
        const iframe = Object.assign(document.createElement('iframe'), {
            style: 'display:none',
            src: alipayUrl
        });

        document.body.appendChild(iframe);
        showToast('正在尝试打开支付宝...', 'info');

        // 防止iframe泄漏
        setTimeout(() => iframe.remove(), 3000);
    });
};

/**
 * 清理定时器
 */
const clearTimers = () => {
    if (state.pollTimer) {
        clearTimeout(state.pollTimer);
        state.pollTimer = null;
    }
    state.isPolling = false;
};

/**
 * 显示手动查询按钮
 */
const showManualQueryButton = () => {
    if (state.manualQueryShown) return;

    const manualBtn = $id('manualQueryButton');
    if (manualBtn) {
        manualBtn.style.display = 'flex';
        state.manualQueryShown = true;
        showToast('因您长时间未支付，已停止自动查询支付结果，请手动查询', 'info');
    }
};

/**
 * 手动查询按钮点击处理（带防抖）
 */
let handleManualQuery = () => {
    void debounceButtonAsync('manualQueryButton', async () => {
        const manualBtn = $id('manualQueryButton');
        if (manualBtn) {
            manualBtn.disabled = true;
            manualBtn.querySelector('.button-text').textContent = '查询中...';
        }

        showToast('正在查询支付状态...', 'info');
        const result = await queryPaymentStatus();

        if (manualBtn) {
            manualBtn.disabled = false;
            manualBtn.querySelector('.button-text').textContent = '手动查询支付结果';
        }

        if (!result.terminal) {
            showToast('暂未支付，请完成支付后重试', 'info');
        }
    });
};

/**
 * 复制支付链接到剪贴板（静默复制，不显示提示）
 */
const copyPaymentUrl = async () => {
    // 优先使用现代Clipboard API
    if (navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(paymentUrl);
            return;
        } catch {
            // Clipboard API失败，降级处理
        }
    }

    // 降级方案：使用Selection API（避免弃用的execCommand）
    const textArea = Object.assign(document.createElement('textarea'), {
        value: paymentUrl,
        style: 'position:fixed;left:-9999px;top:-9999px;opacity:0'
    });
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    try {
        const selection = document.getSelection();
        if (selection) {
            selection.removeAllRanges();
            const range = document.createRange();
            range.selectNodeContents(textArea);
            selection.addRange(range);
        }
    } catch {
        // 静默失败
    }

    textArea.remove();
};

/**
 * 查询支付状态
 */
const queryPaymentStatus = async () => {
    try {
        const response = await fetch(`/api/standard/queryQRStatus?trade_no=${tradeNo}`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        // 处理非2xx响应
        if (!response.ok) {
            try {
                const errorData = await response.json();
                showToast(errorData.message || '网络异常，请检查连接', 'error');
            } catch {
                showToast('网络异常，请检查连接', 'error');
            }
            return { terminal: false, networkError: true };
        }

        const data = await response.json();
        const { code, state: status, data: respData } = data;

        // 支付成功
        if (code === 20000 && status && respData?.redirect_url) {
            showToast('支付成功！正在跳转...', 'success');
            setTimeout(() => window.location.href = respData.redirect_url, 1000);
            return { terminal: true };
        }

        // 订单已结束（超时/关闭等）
        if (code === 40423 && status && respData?.redirect_url) {
            showToast('交易已结束，正在跳转...', 'error');
            setTimeout(() => window.location.href = respData.redirect_url, 1000);
            return { terminal: true };
        }

        // 正常未支付状态
        if (code === 20000 && !status) {
            return { terminal: false };
        }

        // 未知响应
        console.warn('未知API响应:', data);
        showToast(data.message || '状态异常，请稍后重试', 'error');
        return { terminal: false, networkError: true };

    } catch (error) {
        console.error('查询支付状态失败:', error);
        showToast('网络错误，无法获取支付结果', 'error');
        return { terminal: false, networkError: true };
    }
};

/**
 * 调度下一次轮询
 */
const scheduleNextPoll = (delayMs) => {
    if (!state.isPolling) return;

    // 检查是否超过3分钟
    if (state.pollStartTime && Date.now() - state.pollStartTime >= CONFIG.AUTO_POLL_TIMEOUT) {
        clearTimers();
        showManualQueryButton();
        return;
    }

    state.pollTimer = setTimeout(async () => {
        const result = await queryPaymentStatus();

        if (result.terminal) {
            state.isPolling = false;
            return;
        }

        // 网络异常时延长重试时间
        const nextDelay = result.networkError ? CONFIG.ERROR_POLL_DELAY : CONFIG.NORMAL_POLL_DELAY;
        scheduleNextPoll(nextDelay);
    }, delayMs);
};

/**
 * 检测设备类型
 */
const isMobileDevice = () => /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

/**
 * 初始化页面
 */
const initPage = () => {
    const qrcodeContainer = $id('qrcode');
    const loadingIndicator = $id('paymentLoading');
    const alipayButton = $id('alipayButton');
    const isMobile = isMobileDevice();

    // 移动端显示跳转按钮
    if (isMobile && alipayButton) {
        alipayButton.style.display = 'flex';
    }

    // 显示加载指示器
    loadingIndicator?.classList.add('active');

    // 生成二维码
    QRCode.toCanvas(paymentUrl, {
        width: 280,
        height: 280,
        margin: 1
    }, (error, canvas) => {
        loadingIndicator?.classList.remove('active');

        if (error) {
            console.error('二维码生成失败:', error);
            if (qrcodeContainer) {
                qrcodeContainer.innerHTML = '<p style="color:red;">二维码生成失败</p>';
            }
            return;
        }

        qrcodeContainer?.appendChild(canvas);

        // 绑定双击复制事件（静默复制）
        qrcodeContainer?.addEventListener('dblclick', copyPaymentUrl);

        // 开始轮询
        state.pollStartTime = Date.now();
        scheduleNextPoll(CONFIG.INITIAL_POLL_DELAY);

        // 移动端自动尝试拉起支付宝
        if (isMobile) {
            handleAlipayRedirect();
        }
    });
};

// 绑定全局函数供HTML调用
window.toggleOrderInfo = toggleOrderInfo;
window.handleAlipayRedirect = handleAlipayRedirect;
window.handleManualQuery = handleManualQuery;

// DOM加载完成后初始化
document.addEventListener('DOMContentLoaded', initPage);

// 页面卸载清理
const cleanup = () => clearTimers();
window.addEventListener('beforeunload', cleanup);
window.addEventListener('pagehide', cleanup);
