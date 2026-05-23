/* Sales Network - Admin JS */
(function ($) {
  'use strict';

  const ajax  = snAdmin.ajaxurl;
  const nonce = snAdmin.nonce;

  function showResult(sel, ok, msg) {
    $(sel).css('color', ok ? 'green' : 'red').text((ok ? '✅ ' : '❌ ') + (msg || ''));
  }

  // Import CSV
  $(document).on('click', '#sn-do-import', function (e) {
    e.preventDefault();
    const input = $('#sn-import-file')[0];
    const file = input && input.files ? input.files[0] : null;
    if (!file) { showResult('#sn-import-result', false, 'فایل انتخاب نشده'); return; }

    const fd = new FormData();
    fd.append('action', 'sn_import_leads');
    fd.append('nonce', nonce);
    fd.append('file', file);
    fd.append('import_code', $('#sn-import-code').val() || '');

    const $btn = $(this);
    $btn.prop('disabled', true).text('در حال پردازش...');
    $('#sn-import-result').text('');

    $.ajax({
      url: ajax,
      type: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      timeout: 120000,
      success: function (res) {
        $btn.prop('disabled', false).text('آپلود و وارد کردن');
        if (res && res.success) {
          showResult('#sn-import-result', true, res.message || 'ایمپورت انجام شد');
          setTimeout(function () { location.reload(); }, 1200);
        } else {
          showResult('#sn-import-result', false, (res && res.message) ? res.message : 'خطا در ایمپورت');
        }
      },
      error: function (xhr) {
        $btn.prop('disabled', false).text('آپلود و وارد کردن');
        showResult('#sn-import-result', false, 'خطای ارتباط با سرور: ' + (xhr.status || ''));
        console.error('SN import error:', xhr.responseText);
      }
    });
  });

  function snFaDigits(v) { return String(v).replace(/\d/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
  function snEnDigits(v) { return String(v).replace(/[۰-۹]/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }).replace(/[٠-٩]/g, function(d){ return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }); }
  function snPad(n) { return String(n).padStart(2, '0'); }
  function snGregorianToJalali(gy, gm, gd) {
    var gdm=[0,31,59,90,120,151,181,212,243,273,304,334], gy2=(gm>2)?gy+1:gy;
    var days=355666+(365*gy)+Math.floor((gy2+3)/4)-Math.floor((gy2+99)/100)+Math.floor((gy2+399)/400)+gd+gdm[gm-1];
    var jy=-1595+33*Math.floor(days/12053); days%=12053; jy+=4*Math.floor(days/1461); days%=1461;
    if(days>365){jy+=Math.floor((days-1)/365); days=(days-1)%365;}
    var jm=(days<186)?1+Math.floor(days/31):7+Math.floor((days-186)/30);
    var jd=1+((days<186)?days%31:(days-186)%30);
    return [jy,jm,jd];
  }
  function snJalaliToGregorian(jy, jm, jd) {
    jy = Number(jy); jm = Number(jm); jd = Number(jd);
    jy += 1595;
    var days = -355668 + (365 * jy) + Math.floor(jy / 33) * 8 + Math.floor(((jy % 33) + 3) / 4) + jd + (jm < 7 ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
    var gy = 400 * Math.floor(days / 146097);
    days = days % 146097;
    if (days > 36524) {
      gy += 100 * Math.floor(--days / 36524);
      days = days % 36524;
      if (days >= 365) days++;
    }
    gy += 4 * Math.floor(days / 1461);
    days = days % 1461;
    if (days > 365) {
      gy += Math.floor((days - 1) / 365);
      days = (days - 1) % 365;
    }
    var gd = days + 1;
    var sal = [0,31,((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28,31,30,31,30,31,31,30,31,30,31];
    var gm = 1;
    while (gm <= 12 && gd > sal[gm]) { gd -= sal[gm]; gm++; }
    return { gy: gy, gm: gm, gd: gd };
  }
  function snJalaliWeekdayOffset(jy, jm, jd) {
    // Persian calendar grid starts on Saturday: شنبه=0 ... جمعه=6.
    // JS getDay(): Sunday=0 ... Saturday=6, so (getDay()+1)%7 maps correctly.
    var g = snJalaliToGregorian(jy, jm, jd);
    var d = new Date(g.gy, g.gm - 1, g.gd, 12, 0, 0);
    return (d.getDay() + 1) % 7;
  }
  function snJalaliMonthLength(jy, jm) {
    if (jm <= 6) return 31;
    if (jm <= 11) return 30;
    // Leap year by converting Esfand 30: if it stays in same Jalali year before Farvardin 1 next year.
    return (((jy - 474) % 2820 + 474 + 38) * 682 % 2816) < 682 ? 30 : 29;
  }
  function snParseJalali(value) {
    var raw = snEnDigits(value || '').replace(/[-. ]/g, '/');
    var m = raw.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/);
    if (!m) return null;
    return { jy: Number(m[1]), jm: Number(m[2]), jd: Number(m[3]) };
  }
  function snTodayJalali() {
    var now = new Date(), j = snGregorianToJalali(now.getFullYear(), now.getMonth()+1, now.getDate());
    return { jy: j[0], jm: j[1], jd: j[2] };
  }
  function snSetJalaliInput($input, jy, jm, jd) {
    $input.val(snFaDigits(jy + '/' + snPad(jm) + '/' + snPad(jd))).trigger('change');
  }
  function snRenderJalaliPicker($picker, selected) {
    var state = $picker.data('state') || selected || snTodayJalali();
    var monthNames = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
    var week = ['ش','ی','د','س','چ','پ','ج'];
    var html = '<div class="sn-jalali-head"><button type="button" class="button sn-jalali-prev">‹</button><strong>' + monthNames[state.jm - 1] + ' ' + snFaDigits(state.jy) + '</strong><button type="button" class="button sn-jalali-next">›</button></div><div class="sn-jalali-week">';
    week.forEach(function(w){ html += '<span>' + w + '</span>'; });
    html += '</div><div class="sn-jalali-days">';
    var firstOffset = snJalaliWeekdayOffset(state.jy, state.jm, 1);
    for (var blank = 0; blank < firstOffset; blank++) {
      html += '<span class="sn-jalali-empty" aria-hidden="true"></span>';
    }
    for (var i = 1, len = snJalaliMonthLength(state.jy, state.jm); i <= len; i++) {
      html += '<button type="button" class="sn-jalali-day' + (selected && selected.jy === state.jy && selected.jm === state.jm && selected.jd === i ? ' is-selected' : '') + '" data-day="' + i + '">' + snFaDigits(i) + '</button>';
    }
    html += '</div><div class="sn-jalali-actions"><button type="button" class="button sn-jalali-today">امروز</button><button type="button" class="button sn-jalali-clear">پاک کردن</button></div>';
    $picker.data('state', state).html(html);
  }
  function snAttachJalaliPicker($input) {
    if (!$input.length || $input.data('snPickerReady')) return;
    $input.attr({ autocomplete: 'off', inputmode: 'none', readonly: true }).wrap('<span class="sn-jalali-wrap"></span>');
    $input.after('<button type="button" class="button sn-jalali-trigger">تقویم</button><div class="sn-jalali-picker" hidden></div>');
    $input.data('snPickerReady', 1);
  }
  function snOpenJalaliPicker($input) {
    snAttachJalaliPicker($input);
    var selected = snParseJalali($input.val()) || snTodayJalali();
    var $picker = $input.siblings('.sn-jalali-picker');
    snRenderJalaliPicker($picker, selected);
    $('.sn-jalali-picker').not($picker).attr('hidden', true);
    $picker.removeAttr('hidden');
  }
  $(function(){ $('.sn-jalali-date').each(function(){ snAttachJalaliPicker($(this)); }); });
  $(document).on('focus click', '.sn-jalali-date', function(){ snOpenJalaliPicker($(this)); });
  $(document).on('click', '.sn-jalali-trigger', function(){ snOpenJalaliPicker($(this).siblings('.sn-jalali-date')); });
  $(document).on('click', '.sn-jalali-prev,.sn-jalali-next', function(){
    var $picker = $(this).closest('.sn-jalali-picker');
    var state = $picker.data('state') || snTodayJalali();
    state.jm += $(this).hasClass('sn-jalali-next') ? 1 : -1;
    if (state.jm < 1) { state.jm = 12; state.jy--; }
    if (state.jm > 12) { state.jm = 1; state.jy++; }
    state.jd = Math.min(state.jd || 1, snJalaliMonthLength(state.jy, state.jm));
    snRenderJalaliPicker($picker, snParseJalali($picker.siblings('.sn-jalali-date').val()));
  });
  $(document).on('click', '.sn-jalali-day', function(){
    var $picker = $(this).closest('.sn-jalali-picker');
    var state = $picker.data('state') || snTodayJalali();
    snSetJalaliInput($picker.siblings('.sn-jalali-date'), state.jy, state.jm, Number($(this).data('day')));
    $picker.attr('hidden', true);
  });
  $(document).on('click', '.sn-jalali-today', function(){
    var $picker = $(this).closest('.sn-jalali-picker');
    var today = snTodayJalali();
    snSetJalaliInput($picker.siblings('.sn-jalali-date'), today.jy, today.jm, today.jd);
    $picker.attr('hidden', true);
  });
  $(document).on('click', '.sn-jalali-clear', function(){
    var $picker = $(this).closest('.sn-jalali-picker');
    $picker.siblings('.sn-jalali-date').val('').trigger('change');
    $picker.attr('hidden', true);
  });
  $(document).on('click', function(e){
    if (!$(e.target).closest('.sn-jalali-wrap').length) $('.sn-jalali-picker').attr('hidden', true);
  });

  // Settings form
  $(document).on('submit', '#sn-settings-form', function (e) {
    e.preventDefault();
    const data = $(this).serializeArray();
    data.push({ name: 'action', value: 'sn_save_settings' });
    data.push({ name: 'nonce', value: nonce });
    if (!$(this).find('[name="sn_zarinpal_sandbox"]').is(':checked')) {
      data.push({ name: 'sn_zarinpal_sandbox', value: '0' });
    }
    $.post(ajax, data, function (res) {
      const $n = $('#sn-settings-notice');
      $n.html('<div class="notice notice-' + (res.success ? 'success' : 'error') + '"><p>' + (res.message || '') + '</p></div>');
      setTimeout(function () { $n.empty(); }, 4000);
    });
  });

  $(document).on('click', '#sn-repair-pages', function (e) {
    e.preventDefault();
    const $btn = $(this);
    const $result = $('#sn-repair-pages-result');
    $btn.prop('disabled', true).text('در حال بررسی...');
    $result.css('color', '#555').text('لطفاً صبر کنید');
    $.post(ajax, { action: 'sn_repair_pages', nonce: nonce }, function (res) {
      $btn.prop('disabled', false).text('بررسی و اصلاح صفحات سیستم');
      if (res && res.success) {
        $result.css('color', 'green').text('✅ ' + (res.message || 'انجام شد'));
        setTimeout(function () { location.reload(); }, 1200);
      } else {
        $result.css('color', 'red').text('❌ ' + ((res && res.message) || 'خطا در بررسی صفحات'));
      }
    }).fail(function (xhr) {
      $btn.prop('disabled', false).text('بررسی و اصلاح صفحات سیستم');
      $result.css('color', 'red').text('❌ خطای سرور: ' + (xhr.status || ''));
    });
  });

  // Assign seller to supervisor
  $(document).on('change', '.sn-seller-supervisor-select', function () {
    const $sel = $(this);
    $sel.prop('disabled', true);
    $.post(ajax, {
      action: 'sn_save_seller_supervisor',
      nonce: nonce,
      seller_id: $sel.data('seller-id'),
      supervisor_id: $sel.val()
    }, function (res) {
      $sel.prop('disabled', false);
      if (!res || !res.success) {
        alert('❌ ' + ((res && res.message) || 'خطا در ذخیره سرپرست'));
      }
    }).fail(function (xhr) {
      $sel.prop('disabled', false);
      alert('❌ خطای سرور: ' + xhr.status);
    });
  });

  // Allocate raw leads to supervisor
  $(document).on('click', '#sn-assign-supervisor-leads', function (e) {
    e.preventDefault();
    const $btn = $(this);
    const supervisor_id = $('#sn-supervisor-select').val();
    const count = $('#sn-supervisor-lead-count').val();
    if (!supervisor_id || !count || Number(count) < 1) {
      alert('سرپرست و تعداد را انتخاب کنید');
      return;
    }
    $btn.prop('disabled', true).text('در حال تخصیص...');
    $.post(ajax, {
      action: 'sn_assign_supervisor_leads',
      nonce: nonce,
      supervisor_id: supervisor_id,
      count: count,
      import_code: $('#sn-supervisor-import-code').val() || ''
    }, function (res) {
      $btn.prop('disabled', false).text('انتقال به پنل سرپرست');
      if (res && res.success) {
        alert(res.message || 'انجام شد');
        location.reload();
      } else {
        alert('❌ ' + ((res && res.message) || 'خطا در تخصیص'));
      }
    }).fail(function (xhr) {
      $btn.prop('disabled', false).text('انتقال به پنل سرپرست');
      alert('❌ خطای سرور: ' + xhr.status);
    });
  });

  // تایید مالی فیش
  $(document).on('click', '.sn-confirm-payment', function (e) {
    e.preventDefault();
    const id = $(this).data('id');
    const $btn = $(this);
    if (!confirm('تایید مالی و صدور فاکتور رسمی؟\nاین عملیات برگشت‌پذیر نیست.')) return;
    $btn.prop('disabled', true).text('در حال پردازش...');
    $.post(ajax, { action: 'sn_confirm_card_payment', nonce: nonce, invoice_id: id }, function (res) {
      if (res.success) {
        $btn.closest('tr').css('background', '#f0fdf4');
        $btn.replaceWith('<span style="color:#16a34a;font-weight:bold">✅ فاکتور صادر شد</span>');
        setTimeout(function(){ location.reload(); }, 1200);
      } else {
        $btn.prop('disabled', false).text('✅ تایید مالی — تبدیل به فاکتور');
        alert('❌ ' + res.message);
      }
    });
  });

  // رد کردن فیش
  $(document).on('click', '.sn-cancel-payment', function (e) {
    e.preventDefault();
    const id = $(this).data('id');
    const $btn = $(this);
    if (!confirm('فیش این پیش‌فاکتور رد شود و به حالت اولیه برگردد؟')) return;
    $btn.prop('disabled', true).text('در حال رد کردن...');
    $.post(ajax, { action: 'sn_reject_receipt', nonce: nonce, invoice_id: id }, function (res) {
      if (res.success) {
        alert(res.message);
        location.reload();
      } else {
        $btn.prop('disabled', false).text('❌ رد کردن فیش');
        alert('❌ ' + res.message);
      }
    });
  });

  // تغییر وضعیت دستی پیش‌فاکتور از dropdown
  $(document).on('change', '.sn-admin-status-sel', function () {
    var $sel  = $(this);
    var id    = $sel.data('id');
    var status = $sel.val();
    if (!status) return;

    var labels = { pre_invoice: 'پیش‌فاکتور (ریست)', paid: 'فاکتور تایید‌شده', cancelled: 'لغوشده' };
    if (!confirm('وضعیت این پیش‌فاکتور به «' + (labels[status]||status) + '» تغییر کند؟')) {
      $sel.val('');
      return;
    }
    $sel.prop('disabled', true);
    $.post(ajax, {
      action: 'sn_admin_change_status',
      nonce: nonce,
      invoice_id: id,
      status: status
    }, function (res) {
      $sel.prop('disabled', false);
      if (res.success) {
        var $row = $sel.closest('tr');
        $row.css('background', '#f0fdf4');
        $sel.after('<span style="color:#16a34a;font-size:11px;display:block;margin-top:3px">✅ ' + res.message + '</span>');
        setTimeout(function(){ location.reload(); }, 1500);
      } else {
        alert('❌ ' + res.message);
        $sel.val('');
      }
    });
  });

}(jQuery));

// Sales Network - bulk sellers
(function ($) {
  'use strict';
  if (typeof snAdmin === 'undefined') return;

  $(document).on('change', '#sn-select-all-sellers', function () {
    $('.sn-seller-checkbox').prop('checked', $(this).is(':checked'));
  });

  function selectedSellerIds() {
    return $('.sn-seller-checkbox:checked').map(function () { return $(this).val(); }).get();
  }

  $(document).on('change', '#sn-bulk-seller-action', function () {
    var action = $(this).val();
    $('#sn-bulk-seller-supervisor').toggle(action === 'assign_supervisor');
  });

  $(document).on('click', '#sn-run-bulk-seller', function (e) {
    e.preventDefault();
    var ids = selectedSellerIds();
    var action = $('#sn-bulk-seller-action').val();
    var supervisor = $('#sn-bulk-seller-supervisor').val();
    var $result = $('#sn-bulk-seller-result');

    if (!ids.length) { $result.css('color', 'red').text('❌ هیچ فروشنده‌ای انتخاب نشده'); return; }
    if (!action) { $result.css('color', 'red').text('❌ عملیات را انتخاب کنید'); return; }
    if (action === 'assign_supervisor' && (!supervisor || supervisor === '0')) { $result.css('color', 'red').text('❌ سرپرست را انتخاب کنید'); return; }

    var $btn = $(this);
    $btn.prop('disabled', true).text('در حال انجام...');
    $result.css('color', '#555').text('در حال ارسال...');

    $.post(snAdmin.ajaxurl, {
      action: 'sn_bulk_seller_action',
      nonce: snAdmin.nonce,
      seller_ids: ids,
      bulk_action: action,
      supervisor_id: supervisor
    }, function (res) {
      $btn.prop('disabled', false).text('اجرای عملیات');
      if (res && res.success) {
        $result.css('color', 'green').text('✅ ' + (res.message || 'انجام شد'));
        setTimeout(function () { location.reload(); }, 900);
      } else {
        $result.css('color', 'red').text('❌ ' + ((res && res.message) || 'خطا در عملیات'));
      }
    }).fail(function (xhr) {
      $btn.prop('disabled', false).text('اجرای عملیات');
      $result.css('color', 'red').text('❌ خطای سرور: ' + (xhr.status || ''));
    });
  });

  $(function(){ $('#sn-bulk-seller-supervisor').hide(); });
}(jQuery));

/* SN vNext Financial Approval */
(function($){
  'use strict';
  if (typeof snAdmin === 'undefined') return;
  var ajax = snAdmin.ajaxurl, nonce = snAdmin.nonce;
  $(document).on('click', '.sn-fin-approve', function(e){
    e.preventDefault();
    if (!confirm('پرداخت تایید شود؟')) return;
    var $btn = $(this), id = $btn.data('id');
    $btn.prop('disabled', true).text('در حال تایید...');
    $.post(ajax, {action:'sn_financial_approve_payment', nonce:nonce, invoice_id:id}, function(res){
      if (res && res.success) { $btn.closest('tr').find('td').eq(5).text((res.status_label || 'تایید شده')); $btn.closest('td').html('<span class="sn-notice sn-success">✅ تایید شد</span>'); }
      else { $btn.prop('disabled', false).text('تایید'); alert((res && res.message) || 'خطا در تایید'); }
    }).fail(function(xhr){ $btn.prop('disabled', false).text('تایید'); alert('خطای سرور: '+xhr.status); });
  });
  $(document).on('click', '.sn-fin-reject', function(e){
    e.preventDefault();
    var reason = prompt('دلیل رد پرداخت را وارد کنید:');
    if (!reason) return;
    var $btn = $(this), id = $btn.data('id');
    $btn.prop('disabled', true).text('در حال رد...');
    $.post(ajax, {action:'sn_financial_reject_payment', nonce:nonce, invoice_id:id, reason:reason}, function(res){
      if (res && res.success) { $btn.closest('tr').find('td').eq(5).text((res.status_label || 'رد شده')); $btn.closest('td').html('<span class="sn-notice sn-error">رد شد</span>'); }
      else { $btn.prop('disabled', false).text('رد'); alert((res && res.message) || 'خطا در رد'); }
    }).fail(function(xhr){ $btn.prop('disabled', false).text('رد'); alert('خطای سرور: '+xhr.status); });
  });
})(jQuery);
