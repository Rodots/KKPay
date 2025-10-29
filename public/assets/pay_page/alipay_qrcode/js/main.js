'use strict';

let pollTimer = null;
let isPolling = true;

const toggleOrderInfo = () => {
    const orderInfo = document.getElementById('orderInfo');
    const toggleButton = document.querySelector('.order-toggle');
    const statusText = toggleButton?.querySelector('span:last-child');
    if (!orderInfo || !toggleButton || !statusText) return;

    orderInfo.classList.toggle('expanded');
    toggleButton.classList.toggle('expanded');
    statusText.textContent = orderInfo.classList.contains('expanded') ? '点击收起' : '';
};

const showToast = (message, type = 'info') => {
    const toastContainer = document.getElementById('toastContainer');
    if (!toastContainer) return;

    const toast = document.createElement('div');
    toast.className = 'toast';
    const icon = document.createElement('div');
    icon.className = `toast-icon ${type}`;
    icon.textContent = type === 'success' ? '✓' : type === 'error' ? '✕' : 'i';
    const messageEl = document.createElement('div');
    messageEl.className = 'toast-message';
    messageEl.textContent = message;

    toast.appendChild(icon);
    toast.appendChild(messageEl);
    toastContainer.appendChild(toast);

    setTimeout(() => {
        toast.classList.add('hiding');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
};

const handleAlipayRedirect = () => {
    const alipayUrl = 'alipays://platformapi/startapp?appId=20000067&url=' + encodeURIComponent(paymentUrl);
    const iframe = document.createElement('iframe');
    iframe.style.display = 'none';
    iframe.src = alipayUrl;
    document.body.appendChild(iframe);
    showToast('正在尝试打开支付宝...', 'info');

    // 防止 iframe 泄漏
    setTimeout(() => {
        if (iframe.parentNode) iframe.remove();
    }, 3000);
};

const clearTimers = () => {
    if (pollTimer) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
    isPolling = false;
};

const queryPaymentStatus = async () => {
    try {
        const url = '/api/v1/standard/queryQRStatus?trade_no=' + tradeNo;
        const response = await fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        if (!response.ok) {
            showToast('网络异常，请检查连接', 'error');
            return {terminal: false, networkError: true};
        }

        const data = await response.json();

        // 支付成功
        if (data.code === 20000 && data.state === true && data.data?.redirect_url) {
            showToast('支付成功！正在跳转...', 'success');
            setTimeout(() => window.location.href = data.data.redirect_url, 1000);
            return {terminal: true};
        }

        // 订单已结束（超时/关闭等）
        if (data.code === 40423 && data.state === true && data.data?.redirect_url) {
            showToast('交易已结束，正在跳转...', 'error');
            setTimeout(() => window.location.href = data.data.redirect_url, 1000);
            return {terminal: true};
        }

        // 正常未支付状态
        if (data.code === 20000 && data.state === false) {
            return {terminal: false};
        }

        // 未知响应（视为异常）
        console.warn('未知API响应:', data);
        showToast('状态异常，请稍后重试', 'error');
        return {terminal: false, networkError: true};

    } catch (error) {
        console.error('查询支付状态失败:', error);
        showToast('网络错误，无法获取支付结果', 'error');
        return {terminal: false, networkError: true};
    }
};

const scheduleNextPoll = (delayMs) => {
    if (!isPolling) return;

    pollTimer = setTimeout(async () => {
        const result = await queryPaymentStatus();

        if (!result.terminal) {
            // 网络异常时延长重试时间
            const nextDelay = result.networkError ? 10000 : 5000;
            scheduleNextPoll(nextDelay);
        } else {
            isPolling = false;
        }
    }, delayMs);
};

document.addEventListener('DOMContentLoaded', () => {
    const qrcodeContainer = document.getElementById('qrcode');
    const loadingIndicator = document.getElementById('paymentLoading');
    const alipayButton = document.getElementById('alipayButton');

    const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    if (isMobile && alipayButton) {
        alipayButton.style.display = 'block';
    }

    if (loadingIndicator) loadingIndicator.classList.add('active');

    QRCode.toCanvas(paymentUrl, {
        width: 280,
        height: 280,
        margin: 1
    }, (error, canvas) => {
        if (loadingIndicator) loadingIndicator.classList.remove('active');

        if (error) {
            console.error('二维码生成失败:', error);
            if (qrcodeContainer) {
                qrcodeContainer.innerHTML = '<p style="color: red;">二维码生成失败</p>';
            }
            return;
        }

        if (qrcodeContainer) qrcodeContainer.appendChild(canvas);

        scheduleNextPoll(8000);

        if (isMobile) {
            handleAlipayRedirect();
        }
    });
});

const cleanup = () => clearTimers();
window.addEventListener('beforeunload', cleanup);
window.addEventListener('pagehide', cleanup); // 更兼容移动端（如 iOS Safari）
