/* ماهر البحيري — سلوك الواجهة المشترك */
(function () {
    'use strict';
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

    // قائمة الموبايل
    var menuBtn = $('#menuBtn'), sidebar = $('#sidebar');
    if (menuBtn && sidebar) {
        menuBtn.addEventListener('click', function () { sidebar.classList.toggle('open'); });
        document.addEventListener('click', function (e) {
            if (sidebar.classList.contains('open') && !sidebar.contains(e.target) && !menuBtn.contains(e.target)) sidebar.classList.remove('open');
        });
    }

    // عدّاد تصاعدي لأرقام لوحة الرئيسية (تأثير بصري خفيف)
    if (!window.matchMedia || !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        $$('[data-count]').forEach(function (el) {
            var tgt = el.querySelector('.num') || el, to = parseFloat(el.getAttribute('data-count')), isInt = el.hasAttribute('data-int');
            if (isNaN(to)) return;
            var start = null, dur = 700;
            var fmt = function (n) { return isInt ? String(Math.round(n)) : n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
            var step = function (ts) {
                if (start === null) start = ts;
                var k = Math.min(1, (ts - start) / dur), e = 1 - Math.pow(1 - k, 3);
                tgt.textContent = fmt(to * e);
                if (k < 1) requestAnimationFrame(step); else tgt.textContent = fmt(to);
            };
            tgt.textContent = fmt(0); requestAnimationFrame(step);
        });
    }

    // إخفاء رسائل التنبيه تلقائياً
    setTimeout(function () {
        $$('#flashWrap .alert').forEach(function (a) { a.classList.remove('show'); setTimeout(function () { a.remove(); }, 400); });
    }, 7000);

    // تأكيد قبل العمليات الحساسة: <form data-confirm="..."> أو <button data-confirm="...">
    document.addEventListener('submit', function (e) {
        var msg = e.target.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) e.preventDefault();
    });
    document.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-confirm],a[data-confirm]');
        if (b && b.tagName !== 'FORM' && !b.form?.hasAttribute('data-confirm') && !window.confirm(b.getAttribute('data-confirm'))) e.preventDefault();
    });

    // منع الضغط المزدوج على زر الحفظ
    document.addEventListener('submit', function (e) {
        if (e.defaultPrevented) return;
        $$('button[type=submit]', e.target).forEach(function (b) { setTimeout(function () { b.disabled = true; }, 0); });
    });

    // ── شاشة الفاتورة (مشتريات/مبيعات) ───────────────────────────────────
    var form = $('#invoiceForm');
    if (form) {
        var tbody = $('#linesBody'), tpl = $('#lineTpl');
        var num = function (v) { v = String(v || '').replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }).replace(/,/g, ''); var n = parseFloat(v); return isNaN(n) ? 0 : n; };
        var fmt = function (n) { return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
        var recalc = function () {
            var sub = 0;
            $$('tr', tbody).forEach(function (tr) {
                var t = num($('.q', tr).value) * num($('.p', tr).value);
                $('.lt', tr).textContent = fmt(t); sub += t;
            });
            var total = sub - num($('[name=discount]').value) + num($('[name=transport]').value) + num($('[name=vat]').value) + num($('[name=other_fees]').value);
            $('#tSub').textContent = fmt(sub); $('#tTotal').textContent = fmt(total);
            var paid = num($('[name=paid_amount]').value);
            $('#tRemain').textContent = fmt(total - paid);
            var payBox = $('#payBox'); if (payBox) payBox.style.display = paid > 0 ? '' : 'none';
            var dueBox = $('#dueBox'); if (dueBox) dueBox.style.display = (total - paid) > 0.004 ? '' : 'none';
        };
        var addLine = function () {
            var node = tpl.content.firstElementChild.cloneNode(true);
            tbody.appendChild(node); recalc(); return node;
        };
        $('#addLine').addEventListener('click', function () { addLine().querySelector('select').focus(); });
        tbody.addEventListener('click', function (e) {
            var del = e.target.closest('.del-line');
            if (del) { if ($$('tr', tbody).length > 1) del.closest('tr').remove(); recalc(); }
        });
        tbody.addEventListener('change', function (e) {
            if (e.target.matches('select')) { var o = e.target.selectedOptions[0]; var u = e.target.closest('tr').querySelector('.unit'); if (u) u.textContent = o ? (o.getAttribute('data-unit') || '') : ''; }
        });
        form.addEventListener('input', recalc);
        // مساعد ضريبة القيمة المضافة: بيحسب النسبة على (الأصناف − الخصم) لو المستخدم ضغط الزر
        var vatBtn = $('#calcVat');
        if (vatBtn) vatBtn.addEventListener('click', function () {
            var sub = num($('#tSub').textContent) - num($('[name=discount]').value);
            $('[name=vat]').value = (sub * num(vatBtn.getAttribute('data-rate')) / 100).toFixed(2); recalc();
        });
        var payAll = $('#payAll');
        if (payAll) payAll.addEventListener('click', function () { $('[name=paid_amount]').value = num($('#tTotal').textContent).toFixed(2); recalc(); });
        if (!$$('tr', tbody).length) addLine(); else recalc();
    }
})();
