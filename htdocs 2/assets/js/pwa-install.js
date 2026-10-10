/**
 * pwa-install.js
 * يعرض بنر ترويجي لتثبيت الموقع كتطبيق PWA مجرد دخول المستخدم
 */

let deferredPrompt = null;

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    showPWAInstallBanner();
});

function showPWAInstallBanner() {
    if (document.getElementById('pwaInstallBanner')) return;

    const appName = localStorage.getItem('pos_app_name') || 'متجر POS';
    
    const banner = document.createElement('div');
    banner.id = 'pwaInstallBanner';
    banner.style.cssText = `
        position: fixed;
        bottom: 20px;
        left: 20px;
        right: 20px;
        max-width: 480px;
        margin: 0 auto;
        background: #0f1117;
        color: #ffffff;
        border: 1px solid #2563eb;
        border-radius: 14px;
        padding: 16px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        z-index: 99999;
        font-family: 'Cairo', sans-serif;
        direction: rtl;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        animation: slideUpPwa 0.4s ease-out;
    `;

    banner.innerHTML = `
        <style>
            @keyframes slideUpPwa {
                from { transform: translateY(100px); opacity: 0; }
                to { transform: translateY(0); opacity: 1; }
            }
        </style>
        <div style="display:flex; align-items:center; gap:12px;">
            <div style="width:45px; height:45px; background:#2563eb; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:24px; color:#fff;">
                <i class="bi bi-phone"></i>
            </div>
            <div>
                <strong style="font-size:15px; display:block; line-height:1.2;">تثبيت تطبيق ${appName}</strong>
                <small style="color:#94a3b8; font-size:12px;">احصل على تجربة تطبيق وسرعة أوفلاين</small>
            </div>
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
            <button id="btnPwaInstall" style="background:#2563eb; color:#fff; border:none; padding:8px 16px; border-radius:8px; font-weight:700; font-family:'Cairo',sans-serif; cursor:pointer; font-size:13px;">
                تثبيت الآن
            </button>
            <button id="btnPwaClose" style="background:transparent; color:#94a3b8; border:none; font-size:18px; cursor:pointer; padding:4px;">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    `;

    document.body.appendChild(banner);

    document.getElementById('btnPwaInstall').addEventListener('click', async () => {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            if (outcome === 'accepted') {
                console.log('User accepted the PWA install prompt');
            }
            deferredPrompt = null;
        }
        banner.remove();
    });

    document.getElementById('btnPwaClose').addEventListener('click', () => {
        banner.remove();
    });
}
