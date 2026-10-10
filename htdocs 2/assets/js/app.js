// App JS Utilities

// حماية CSRF: كل طلبات fetch() اللي بتتبعت لنفس الموقع (POST خصوصًا) بيتضاف
// لها هيدر X-CSRF-Token تلقائيًا من غير ما أي كود تاني في الموقع يحتاج
// يتعدّل — التوكن نفسه متولّد من السيرفر وموجود في <meta name="csrf-token">
// اللي بتتحط في كل صفحة بعد تسجيل الدخول (header.php).
(function() {
    const tokenMeta = document.querySelector('meta[name="csrf-token"]');
    if (!tokenMeta) return; // صفحة مالهاش سيشن (زي شاشة تسجيل الدخول نفسها) — من غير هيدر
    const token = tokenMeta.getAttribute('content');
    const originalFetch = window.fetch;
    window.fetch = function(input, init) {
        init = init || {};
        const method = (init.method || (input instanceof Request ? input.method : 'GET') || 'GET').toUpperCase();
        if (method !== 'GET' && method !== 'HEAD') {
            const headers = new Headers(init.headers || (input instanceof Request ? input.headers : undefined));
            headers.set('X-CSRF-Token', token);
            init = Object.assign({}, init, { headers });
        }
        return originalFetch.call(this, input, init);
    };
})();

document.addEventListener('DOMContentLoaded', function() {
    // Sidebar Toggle for ALL screens (Desktop, Laptop, Tablet, Mobile)
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    
    // Restore collapsed preference on Desktop
    if (window.innerWidth >= 992) {
        if (localStorage.getItem('pos_sidebar_collapsed') === '1') {
            document.body.classList.add('sidebar-collapsed');
        }
    }

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function() {
            if (window.innerWidth < 992) {
                // Mobile/Tablet: Slide overlay in/out
                sidebar.classList.toggle('show');
                if (overlay) overlay.classList.toggle('show');
            } else {
                // Desktop/Computer: Collapse or expand sidebar to maximize screen space
                document.body.classList.toggle('sidebar-collapsed');
                const isCollapsed = document.body.classList.contains('sidebar-collapsed');
                localStorage.setItem('pos_sidebar_collapsed', isCollapsed ? '1' : '0');
            }
        });
    }

    if (overlay && sidebar) {
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });
    }

    // Auto-dismiss alerts after 4 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 4000);
});

// Format Currency
function formatMoney(amount) {
    return 'AED ' + parseFloat(amount || 0).toFixed(2);
}
