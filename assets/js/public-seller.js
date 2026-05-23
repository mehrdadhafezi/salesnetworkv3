/* Sales Network - Public JS */
(function ($) {
  'use strict';

  // Full-width portal helpers: add body class and mark panels without sidebar/tabs
  $(function(){
    if ($('.sn-panel, .sn-invoice-page, .sn-auth-wrap').length) {
      $('body').addClass('sn-portal-page');
    }
    $('.sn-panel').each(function(){
      var $p = $(this);
      if (!$p.children('.sn-tabs').length) {
        $p.addClass('sn-no-sidebar');
      }
      // Make direct content blocks stretch even when the active tab changes dynamically
      $p.children('.sn-tab-content').css({width:'100%', maxWidth:'100%'});
    });
  });


  window.snAjax = window.snAjax || window.snData || {};
  window.snData = window.snData || window.snAjax;
  const ajax = snAjax.ajaxurl;
  const nonce = snAjax.nonce;
  // admins browsing public supervisor panel may have an admin nonce
  const adminNonce = (snAjax.admin_nonce && snAjax.admin_nonce.length) ? snAjax.admin_nonce : nonce;

  // ============================================================
  // TAB SWITCHER (universal)
  // ============================================================
  // تب‌های پنل (seller panel, supervisor, invoice)
  $(document).on('click', '.sn-tab', function () {
    var $panel = $(this).closest('.sn-panel, .sn-invoice-page');
    var target = $(this).data('tab');
    $panel.find('.sn-tab').removeClass('active');
    $panel.find('.sn-tab-content').removeClass('active').hide();
    $(this).addClass('active');
    $panel.find('#sn-tab-' + target).addClass('active').show();
  });

  // تب‌های فرم ورود/ثبت‌نام — handler مجزا
  $(document).on('click', '.sn-auth-tab', function () {
    const $card = $(this).closest('.sn-auth-card');
    const target = $(this).data('tab');
    $card.find('.sn-auth-tab').removeClass('active');
    $card.find('.sn-tab-content').removeClass('active');
    $(this).addClass('active');
    $card.find('#sn-tab-' + target).addClass('active');
  });



  // ============================================================
  // UI ENHANCEMENTS: KPI, Dark mode, Skeleton, live filters
  // ============================================================
  function snFormatNumber(value) {
    var n = Number(value || 0);
    try { return n.toLocaleString('fa-IR'); } catch(e) { return String(n); }
  }

  function snFormatMoney(value) {
    var n = Number(value || 0);
    try { return n.toLocaleString('fa-IR') + ' تومان'; } catch(e) { return String(n) + ' تومان'; }
  }

  function snToFaDigits(value) { return String(value || '').replace(/\d/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
  function snToEnDigits(value) { return String(value || '').replace(/[۰-۹]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);}).replace(/[٠-٩]/g,function(d){return '٠١٢٣٤٥٦٧٨٩'.indexOf(d);}); }
  function snPad(value) { return String(value).padStart(2, '0'); }
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
    var raw = snToEnDigits(value || '').replace(/[-. ]/g, '/');
    var m = raw.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/);
    if (!m) return null;
    return { jy: Number(m[1]), jm: Number(m[2]), jd: Number(m[3]) };
  }
  function snTodayJalali() {
    var now = new Date();
    var j = snGregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
    return { jy: j[0], jm: j[1], jd: j[2] };
  }
  function snSetJalaliInput($input, jy, jm, jd) {
    var val = jy + '/' + snPad(jm) + '/' + snPad(jd);
    $input.val(snToFaDigits(val)).trigger('change');
  }
  function snRenderJalaliPicker($picker, selected) {
    var state = $picker.data('state') || selected || snTodayJalali();
    var monthNames = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
    var week = ['ش','ی','د','س','چ','پ','ج'];
    var html = '<div class="sn-jalali-head"><button type="button" class="sn-jalali-prev" aria-label="ماه قبل">‹</button><strong>' + monthNames[state.jm - 1] + ' ' + snToFaDigits(state.jy) + '</strong><button type="button" class="sn-jalali-next" aria-label="ماه بعد">›</button></div><div class="sn-jalali-week">';
    week.forEach(function(w){ html += '<span>' + w + '</span>'; });
    html += '</div><div class="sn-jalali-days">';
    var firstOffset = snJalaliWeekdayOffset(state.jy, state.jm, 1);
    for (var blank = 0; blank < firstOffset; blank++) {
      html += '<span class="sn-jalali-empty" aria-hidden="true"></span>';
    }
    for (var i = 1, len = snJalaliMonthLength(state.jy, state.jm); i <= len; i++) {
      html += '<button type="button" class="sn-jalali-day' + (selected && selected.jy === state.jy && selected.jm === state.jm && selected.jd === i ? ' is-selected' : '') + '" data-day="' + i + '">' + snToFaDigits(i) + '</button>';
    }
    html += '</div><div class="sn-jalali-actions"><button type="button" class="sn-jalali-today">امروز</button><button type="button" class="sn-jalali-clear">پاک کردن</button></div>';
    $picker.data('state', state).html(html);
  }
  function snAttachJalaliPicker($input) {
    if (!$input.length || $input.data('snPickerReady')) return;
    $input.attr({ autocomplete: 'off', inputmode: 'none', readonly: true }).wrap('<span class="sn-jalali-wrap"></span>');
    $input.after('<button type="button" class="sn-jalali-trigger" aria-label="باز کردن تقویم">تقویم</button><div class="sn-jalali-picker" hidden></div>');
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

  function snPercent(part, total) {
    part = Number(part || 0); total = Number(total || 0);
    if (!total) return '۰٪';
    try { return Math.round((part / total) * 100).toLocaleString('fa-IR') + '٪'; } catch(e) { return Math.round((part / total) * 100) + '%'; }
  }

  function snSkeletonRows(count, cols) {
    count = count || 5; cols = cols || 4;
    var html = '<div class="sn-skeleton-card"><table class="sn-table sn-skeleton-table"><tbody>';
    for (var r = 0; r < count; r++) {
      html += '<tr>';
      for (var c = 0; c < cols; c++) html += '<td><span class="sn-skeleton-line"></span></td>';
      html += '</tr>';
    }
    html += '</tbody></table></div>';
    return html;
  }

  function snEnsureDarkToggle($scope) {
    $scope = $scope && $scope.length ? $scope : $('.sn-panel, .sn-invoice-page').first();
    if (!$scope.length || $scope.find('.sn-dark-toggle').length) return;
    var btn = '<button type="button" class="sn-dark-toggle" title="تغییر حالت روشن/تاریک">🌙 حالت تاریک</button>';
    var $header = $scope.find('.sn-panel-header').first();
    if ($header.length) $header.append(btn);
    else $scope.prepend('<div class="sn-ui-toolbar">' + btn + '</div>');
  }

  function snApplyTheme() {
    if (localStorage.getItem('sn_dark_mode') === '1') {
      $('body').addClass('sn-dark-mode');
      $('.sn-dark-toggle').text('☀️ حالت روشن');
    } else {
      $('body').removeClass('sn-dark-mode');
      $('.sn-dark-toggle').text('🌙 حالت تاریک');
    }
  }

  $(document).on('click', '.sn-dark-toggle', function() {
    localStorage.setItem('sn_dark_mode', $('body').hasClass('sn-dark-mode') ? '0' : '1');
    snApplyTheme();
  });

  $(function(){ snApplyTheme(); snEnsureDarkToggle($('.sn-panel, .sn-invoice-page').first()); });

  // ============================================================
  // SELLER PANEL
  // ============================================================
  var SN_CITIES = {"آذربایجان شرقی": ["تبریز", "مراغه", "مرند", "اهر", "بناب", "میانه", "سراب", "شبستر", "هشترود", "عجب‌شیر", "ملکان", "اسکو", "بستان‌آباد", "هریس", "کلیبر", "ورزقان", "خداآفرین", "چاراویماق"], "آذربایجان غربی": ["ارومیه", "خوی", "مهاباد", "بوکان", "میاندوآب", "اشنویه", "نقده", "سلماس", "پیرانشهر", "سردشت", "تکاب", "چالدران", "شاهین‌دژ", "ماکو", "پلدشت", "چایپاره"], "اردبیل": ["اردبیل", "پارس‌آباد", "خلخال", "مشگین‌شهر", "گرمی", "بیله‌سوار", "نمین", "نیر", "کوثر", "سرعین"], "اصفهان": ["اصفهان", "کاشان", "خمینی‌شهر", "نجف‌آباد", "شاهین‌شهر", "فلاورجان", "لنجان", "آران و بیدگل", "شهرضا", "مبارکه", "گلپایگان", "برخوار", "تیران و کرون", "سمیرم", "اردستان", "نائین", "خوانسار", "فریدن", "فریدونشهر", "دهاقان", "چادگان"], "البرز": ["کرج", "فردیس", "نظرآباد", "ساوجبلاغ", "طالقان", "محمدشهر", "هشتگرد"], "ایلام": ["ایلام", "دهلران", "ایوان", "مهران", "آبدانان", "دره‌شهر", "چرداول", "بدره", "ملکشاهی"], "بوشهر": ["بوشهر", "بندر گناوه", "برازجان", "بندر دیر", "خورموج", "کنگان", "جم", "دیلم"], "تهران": ["تهران", "شهریار", "پاکدشت", "ورامین", "دماوند", "فیروزکوه", "اسلامشهر", "رباط‌کریم", "قرچک", "ری", "ملارد", "بهارستان", "پردیس", "قدس"], "چهارمحال و بختیاری": ["شهرکرد", "بروجن", "فارسان", "لردگان", "اردل", "کوهرنگ", "سامان", "بن"], "خراسان جنوبی": ["بیرجند", "قاین", "نهبندان", "طبس", "سرایان", "فردوس", "درمیان", "سربیشه", "خوسف", "زیرکوه", "بشرویه"], "خراسان رضوی": ["مشهد", "سبزوار", "نیشابور", "تربت حیدریه", "کاشمر", "قوچان", "تربت جام", "چناران", "فریمان", "درگز", "تایباد", "خواف", "گناباد", "بردسکن", "جوین", "جغتای", "خلیل‌آباد", "مه‌ولات"], "خراسان شمالی": ["بجنورد", "شیروان", "اسفراین", "مانه و سملقان", "جاجرم", "گرمه", "فاروج"], "خوزستان": ["اهواز", "آبادان", "خرمشهر", "دزفول", "مسجدسلیمان", "بهبهان", "اندیمشک", "شوشتر", "شوش", "ماهشهر", "رامهرمز", "امیدیه", "ایذه", "باوی", "لالی", "هندیجان", "دشت آزادگان"], "زنجان": ["زنجان", "ابهر", "خدابنده", "قیدار", "ماهنشان", "سلطانیه", "طارم", "ایجرود"], "سمنان": ["سمنان", "شاهرود", "گرمسار", "دامغان", "مهدیشهر", "آرادان", "سرخه", "میامی"], "سیستان و بلوچستان": ["زاهدان", "چابهار", "زابل", "ایرانشهر", "خاش", "سراوان", "نیکشهر", "کنارک", "دلگان", "میرجاوه", "هیرمند", "قصرقند"], "فارس": ["شیراز", "مرودشت", "کازرون", "جهرم", "فسا", "لارستان", "داراب", "آباده", "نی‌ریز", "فیروزآباد", "استهبان", "اقلید", "ممسنی", "خرم‌بید", "پاسارگاد", "بوانات", "لامرد", "سپیدان", "گراش", "خنج"], "قزوین": ["قزوین", "البرز", "بویین‌زهرا", "تاکستان", "آوج"], "قم": ["قم"], "کردستان": ["سنندج", "سقز", "مریوان", "بانه", "قروه", "کامیاران", "بیجار", "دیواندره", "سروآباد", "دهگلان"], "کرمان": ["کرمان", "رفسنجان", "سیرجان", "جیرفت", "زرند", "شهربابک", "بافت", "بردسیر", "عنبرآباد", "کهنوج", "قلعه‌گنج", "منوجان", "نرماشیر", "فهرج"], "کرمانشاه": ["کرمانشاه", "اسلام‌آباد غرب", "کنگاور", "هرسین", "صحنه", "سنقر", "پاوه", "جوانرود", "روانسر", "دالاهو"], "کهگیلویه و بویراحمد": ["یاسوج", "گچساران", "دهدشت", "کهگیلویه", "بهمئی", "لنده", "باشت", "چرام"], "گلستان": ["گرگان", "گنبدکاووس", "آزادشهر", "علی‌آباد", "کردکوی", "بندرترکمن", "مینودشت", "رامیان", "گالیکش", "مراوه‌تپه", "کلاله", "آق‌قلا", "گمیشان"], "گیلان": ["رشت", "بندر انزلی", "لاهیجان", "لنگرود", "آستارا", "صومعه‌سرا", "رودبار", "رودسر", "تالش", "فومن", "شفت", "سیاهکل", "ماسال", "رضوانشهر"], "لرستان": ["خرم‌آباد", "بروجرد", "کوهدشت", "الیگودرز", "نورآباد", "ازنا", "دلفان", "سلسله", "رومشکان", "پلدختر"], "مازندران": ["ساری", "بابل", "آمل", "قائمشهر", "نوشهر", "بابلسر", "نکا", "چالوس", "تنکابن", "رامسر", "جویبار", "محمودآباد", "فریدونکنار", "بهشهر", "نور", "میاندورود", "سوادکوه", "کلاردشت"], "مرکزی": ["اراک", "ساوه", "خمین", "محلات", "دلیجان", "آشتیان", "شازند", "تفرش", "کمیجان", "زرندیه"], "هرمزگان": ["بندرعباس", "بندر لنگه", "قشم", "میناب", "حاجی‌آباد", "خمیر", "ابوموسی", "بستک", "پارسیان", "جاسک", "رودان"], "همدان": ["همدان", "ملایر", "نهاوند", "تویسرکان", "بهار", "اسدآباد", "کبودراهنگ", "رزن", "فامنین"], "یزد": ["یزد", "میبد", "اردکان", "بافق", "ابرکوه", "طبس", "مهریز", "خاتم", "تفت", "صدوق"]};

  // تبدیل تاریخ میلادی به شمسی
  function toJalali(dateStr) {
    if (!dateStr) return '—';
    try {
      var d = new Date(dateStr.replace(' ', 'T'));
      if (isNaN(d.getTime())) return '—';
      var gy = d.getFullYear(), gm = d.getMonth()+1, gd = d.getDate();
      // الگوریتم صحیح تبدیل گریگوری به جلالی
      var g_y = gy - 1600, g_m = gm - 1, g_d = gd - 1;
      var g_d_no = 365*g_y + Math.floor((g_y+3)/4) - Math.floor((g_y+99)/100) + Math.floor((g_y+399)/400);
      var gDays = [31,28,31,30,31,30,31,31,30,31,30,31];
      if ((gy%4==0 && gy%100!=0) || gy%400==0) gDays[1] = 29;
      for (var i=0; i<g_m; i++) g_d_no += gDays[i];
      g_d_no += g_d;
      var j_d_no = g_d_no - 79;
      var j_np = Math.floor(j_d_no/12053); j_d_no %= 12053;
      var j_y = 979 + 33*j_np + 4*Math.floor(j_d_no/1461);
      j_d_no %= 1461;
      if (j_d_no >= 366) { j_y += Math.floor((j_d_no-1)/365); j_d_no = (j_d_no-1)%365; }
      var jDays = [31,31,31,31,31,31,30,30,30,30,30,29];
      var j_m = 0;
      for (var i=0; i<12; i++) { if (j_d_no >= jDays[i]) { j_d_no -= jDays[i]; j_m++; } else break; }
      var j_d = j_d_no + 1;
      var hh = String(d.getHours()).padStart(2,'0');
      var mm = String(d.getMinutes()).padStart(2,'0');
      return j_y + '/' + String(j_m+1).padStart(2,'0') + '/' + String(j_d).padStart(2,'0') + ' ' + hh + ':' + mm;
    } catch(e) { return '—'; }
  }

  function snBuildCityOptions(province, selectedCity) {
    var opts = '<option value="">انتخاب شهر</option>';
    if (province && SN_CITIES[province]) {
      SN_CITIES[province].forEach(function(c) {
        var normalizedSelected = String(selectedCity || '').trim();
        opts += '<option value="' + c + '"' + (normalizedSelected===String(c).trim()?' selected':'') + '>' + c + '</option>';
      });
    }
    return opts;
  }

  const $sellerPanel = $('#sn-seller-panel');
  if ($sellerPanel.length) {
    snEnsureDarkToggle($sellerPanel);
    let allLeadStatuses = [];
    let allLeads        = [];
    let activeFilter    = 'no-status';
    let expandedLeadId  = null;
    let saveTimers      = {};
    let pendingSaves    = {};
    let dirtyLeadIds    = {};
    let sellerInvoices  = [];

    // ---- بارگذاری وضعیت‌ها از DB ادمین ----
    $.post(ajax, { action: 'sn_get_lead_statuses', nonce: nonce }, function (res) {
      if (res.success && res.statuses && res.statuses.length) {
        allLeadStatuses = res.statuses;
      }
      renderLeadFilterBar();
      loadLeads();
    });

    loadInvoices();

    // ---- رندر نوار فیلتر (همیشه از DB میخونه) ----
    function renderLeadFilterBar() {
      var bar = '<div class="sn-lead-filters" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;align-items:center">';
      // بدون وضعیت اول
      var nsActive = activeFilter === 'no-status';
      bar += '<button class="sn-btn sn-btn-sm ' + (nsActive ? 'sn-btn-primary' : 'sn-btn-ghost') + '" data-filter="no-status">📋 بدون وضعیت</button>';
      // وضعیت‌های DB — حتی اگه کسی اون وضعیت رو نداشته باشه، تب نمایش داده میشه
      allLeadStatuses.forEach(function(s) {
        var active = activeFilter === s.label;
        var style  = active
          ? 'background:' + s.color + ';color:#fff;border:none;box-shadow:0 2px 6px ' + s.color + '55'
          : 'background:transparent;border:1.5px solid ' + s.color + ';color:' + s.color;
        bar += '<button class="sn-btn sn-btn-sm" style="' + style + '" data-filter="' + s.label + '">' + s.label + '</button>';
      });
      if (allLeads.some(function(l){ return !!l.has_recontact; })) {
        bar += '<button class="sn-btn sn-btn-sm ' + (activeFilter === 'recontact' ? 'sn-btn-primary' : 'sn-btn-ghost') + '" data-filter="recontact">ارتباط مجدد با کارشناس</button>';
      }
      // همه آخر
      bar += '<button class="sn-btn sn-btn-sm ' + (activeFilter === 'all' ? 'sn-btn-primary' : 'sn-btn-ghost') + '" data-filter="all">همه</button>';
      bar += '</div>';
      $('#sn-leads-filter-bar').html(bar);
    }

    $(document).on('click', '.sn-lead-filters button', function () {
      activeFilter = $(this).data('filter');
      renderLeadFilterBar();
      renderLeadsTable();
    });

    // ---- بارگذاری leads از سرور ----
    function loadLeads() {
      $('#sn-leads-loading').show();
      $('#sn-leads-list').html(snSkeletonRows(5, 3));
      $.post(ajax, { action: 'sn_seller_leads', nonce: nonce }, function (res) {
        $('#sn-leads-loading').hide();
        if (!res.success || !res.leads || !res.leads.length) {
          allLeads = [];
          renderSellerKpis();
          $('#sn-leads-list').html('<p class="sn-notice">هنوز شماره‌ای به شما تخصیص نیافته.</p>');
          return;
        }
        allLeads = res.leads;
        renderLeadFilterBar();
        renderSellerKpis();
        renderLeadsTable();
        fillLeadDropdown();
      });
    }

    function fillLeadDropdown() {
      var $sel = $('#sn-lead-select');
      $sel.empty().append('<option value="">— بدون تخصیص —</option>');
      allLeads.forEach(function(l) {
        if (l.status !== 'invoiced') {
          $sel.append('<option value="' + l.id + '" data-phone="' + l.phone + '">' + l.phone + '</option>');
        }
      });
    }


    function renderSellerKpis() {
      var totalLeads = allLeads.length;
      var noStatus = allLeads.filter(function(l){ return !l.lead_status; }).length;
      var statusDone = totalLeads - noStatus;
      var invoiceCount = sellerInvoices.length;
      var paidCount = sellerInvoices.filter(function(i){ return ['paid','approved'].indexOf(String(i.status || i.payment_status || i.invoice_status || '')) !== -1; }).length;
      var revenue = sellerInvoices.reduce(function(sum, i){
        var st = String(i.status || i.payment_status || i.invoice_status || '');
        if (['paid','approved'].indexOf(st) !== -1) return sum + Number(i.product_price || i.amount || 0);
        return sum;
      }, 0);
      var html = '<div class="sn-kpi-grid sn-seller-kpis">' +
        '<div class="sn-kpi-card"><span class="sn-kpi-icon">📞</span><small>کل لیدها</small><strong>' + snFormatNumber(totalLeads) + '</strong><em>شماره‌های تخصیص‌یافته</em></div>' +
        '<div class="sn-kpi-card"><span class="sn-kpi-icon">✅</span><small>پیگیری‌شده</small><strong>' + snFormatNumber(statusDone) + '</strong><em>' + snPercent(statusDone, totalLeads) + ' از کل لیدها</em></div>' +
        '<div class="sn-kpi-card"><span class="sn-kpi-icon">🧾</span><small>پیش‌فاکتور</small><strong>' + snFormatNumber(invoiceCount) + '</strong><em>تبدیل: ' + snPercent(invoiceCount, totalLeads) + '</em></div>' +
        '<div class="sn-kpi-card sn-kpi-money"><span class="sn-kpi-icon">💰</span><small>فروش تاییدشده</small><strong>' + snFormatMoney(revenue) + '</strong><em>' + snFormatNumber(paidCount) + ' پرداخت موفق</em></div>' +
      '</div>';
      var $target = $('#sn-seller-kpi-cards');
      if (!$target.length) {
        $target = $('<div id="sn-seller-kpi-cards" class="sn-kpi-host"></div>');
        var $tab = $('#sn-tab-leads');
        if ($tab.length) $tab.prepend($target); else $sellerPanel.prepend($target);
      }
      $target.html(html);
    }

    // ---- رندر لیست leads — کارت‌های کلیک‌پذیر ----
    function renderLeadsTable() {
      var filtered = allLeads.filter(function(l) {
        if (activeFilter === 'all')       return true;
        if (activeFilter === 'no-status') return !l.lead_status || l.lead_status === '';
        if (activeFilter === 'recontact') return !!l.has_recontact;
        return l.lead_status === activeFilter;
      });

      if (!filtered.length) {
        $('#sn-leads-list').html('<div class="sn-notice sn-info" style="text-align:center;padding:24px">شماره‌ای با این وضعیت یافت نشد.</div>');
        return;
      }

      var statusOptions = allLeadStatuses.map(function(s) {
        return '<option value="' + s.label + '">' + s.label + '</option>';
      }).join('');

      var html = '<table class="sn-table" style="border-collapse:collapse;width:100%">' +
        '<thead><tr>' +
        '<th style="width:180px">شماره</th>' +
        '<th style="width:160px">تاریخ تخصیص</th>' +
        '<th>اطلاعات مشتری</th>' +
        '</tr></thead><tbody>';

      filtered.forEach(function(l, idx) {
        var rowBg = idx % 2 === 0 ? '#fff' : '#f8fafc';

        // badge وضعیت تماس
        var statusBadge = '';
        if (l.lead_status) {
          var found = allLeadStatuses.find(function(s){ return s.label === l.lead_status; });
          statusBadge = found
            ? '<span style="background:' + found.color + ';color:#fff;padding:1px 7px;border-radius:8px;font-size:.75rem;margin-right:4px">' + found.label + '</span>'
            : '<span style="background:#e2e8f0;color:#475569;padding:1px 7px;border-radius:8px;font-size:.75rem">' + l.lead_status + '</span>';
        }

        // خلاصه اطلاعات مشتری
        var custInfo = [];
        if (l.customer_name) custInfo.push('<strong>' + l.customer_name + '</strong>');
        if (l.province && l.city) custInfo.push(l.province + ' — ' + l.city);
        else if (l.province) custInfo.push(l.province);
        if (l.sales_prediction) custInfo.push('احتمال: ' + l.sales_prediction);
        if (l.note) custInfo.push('📝 ' + l.note);
        if (l.has_recontact) {
          custInfo.push('<span class="sn-recontact-lead-chip">درخواست ارتباط مجدد' + (l.recontact_invoice_code ? ' — ' + l.recontact_invoice_code : '') + '</span>');
        }
        var custHtml = custInfo.length
          ? custInfo.join(' | ')
          : '<span style="color:#94a3b8;font-size:.78rem">اطلاعاتی ثبت نشده</span>';

        html += '<tr style="background:' + rowBg + '" id="sn-lead-row-' + l.id + '">' +
          // ستون شماره — کلیک‌پذیر
          '<td>' +
            '<button type="button" class="sn-phone-copy" data-phone="' + l.phone + '" title="کپی شماره">' + l.phone + '</button>' +
            '<button type="button" class="sn-phone-toggle sn-btn sn-btn-sm sn-btn-ghost" data-id="' + l.id + '">مشاهده/ویرایش</button>' +
            '<div class="sn-copy-msg" data-phone="' + l.phone + '"></div>' +
            (statusBadge ? '<div style="margin-top:3px">' + statusBadge + '</div>' : '') +
          '</td>' +
          // تاریخ تخصیص
          '<td style="font-size:.78rem;color:#64748b;white-space:nowrap;vertical-align:top;padding-top:6px">' + toJalali(l.assigned_at) + '</td>' +
          // اطلاعات مشتری خلاصه
          '<td style="font-size:.82rem;color:#475569;vertical-align:top;padding-top:6px">' + custHtml + '</td>' +
        '</tr>' +

        // ---- dropdown row ----
        '<tr id="sn-expand-' + l.id + '" style="display:none">' +
          '<td colspan="3" style="padding:0;border-top:2px solid #3b82f6">' +
            '<div class="sn-lead-editor">' +

              // ردیف اول: اطلاعات مشتری
              '<div class="sn-lead-editor-grid">' +
                '<div class="sn-lead-field sn-lead-field-name"><label>نام مشتری</label>' +
                  '<input type="text" class="sn-cust-name" data-id="' + l.id + '" value="' + (l.customer_name||'').replace(/"/g,'&quot;') + '" placeholder="نام و نام خانوادگی"></div>' +
                '<div class="sn-lead-field"><label>استان</label>' +
                  '<select class="sn-cust-prov" data-id="' + l.id + '">' +
                    '<option value="">انتخاب استان</option>' +
                    Object.keys(SN_CITIES).map(function(p){ return '<option value="' + p + '"' + (l.province===p?' selected':'') + '>' + p + '</option>'; }).join('') +
                  '</select></div>' +
                '<div class="sn-lead-field"><label>شهر</label>' +
                  '<select class="sn-cust-city" data-id="' + l.id + '">' +
                    snBuildCityOptions(l.province, l.city) +
                  '</select></div>' +
                '<div class="sn-lead-field"><label>احتمال فروش</label>' +
                  '<select class="sn-cust-pred sn-auto-save" data-id="' + l.id + '">' +
                    '<option value="">انتخاب کنید</option>' +
                    ['ضعیف','متوسط','بالا','١٠٠٪'].map(function(v){ return '<option value="' + v + '"' + (l.sales_prediction===v?' selected':'') + '>' + v + '</option>'; }).join('') +
                  '</select></div>' +
                '<div class="sn-lead-field"><label>وضعیت تماس</label>' +
                  '<select class="sn-lead-status-select sn-auto-save" data-id="' + l.id + '">' +
                    '<option value="">— تعیین وضعیت —</option>' + statusOptions +
                  '</select></div>' +
                '<div class="sn-lead-field sn-lead-field-note"><label>یادداشت</label>' +
                  '<textarea class="sn-lead-note sn-auto-save" data-id="' + l.id + '" placeholder="خلاصه مکالمه، نیاز مشتری، زمان پیگیری...">' + (l.note||'').replace(/</g,'&lt;') + '</textarea></div>' +
                '<div class="sn-inline-save-state" data-id="' + l.id + '"></div>' +
              '</div>' +

              // ردیف دوم: فقط صدور پیش‌فاکتور
              '<div class="sn-lead-editor-actions">' +
                '<div><button class="sn-btn sn-btn-sm sn-btn-primary sn-use-lead" data-phone="' + l.phone + '" data-id="' + l.id + '" ' +
                  'style="background:#7c3aed;border-color:#7c3aed">📄 صدور پیش‌فاکتور</button></div>' +
              '</div>' +
            '</div>' +
          '</td>' +
        '</tr>';
      });

      html += '</tbody></table>';
      $('#sn-leads-list').html(html);

      // ست کردن مقدار فعلی وضعیت تماس
      filtered.forEach(function(l) {
        if (l.lead_status) {
          $('select.sn-lead-status-select[data-id="' + l.id + '"]').val(l.lead_status);
        }
      });

      if (expandedLeadId) {
        $('#sn-expand-' + expandedLeadId).show();
      }
    }

    function getLeadById(id) {
      return allLeads.find(function(l){ return String(l.id) === String(id); }) || null;
    }

    function collectLeadFormData(id) {
      return {
        customer_name: $('.sn-cust-name[data-id="' + id + '"]').val().trim(),
        province: $('.sn-cust-prov[data-id="' + id + '"]').val(),
        city: $('.sn-cust-city[data-id="' + id + '"]').val(),
        sales_prediction: $('.sn-cust-pred[data-id="' + id + '"]').val(),
        note: $('.sn-lead-note[data-id="' + id + '"]').val(),
        lead_status: $('select.sn-lead-status-select[data-id="' + id + '"]').val()
      };
    }

    function setLeadSaveState(id, state, message) {
      var $box = $('.sn-inline-save-state[data-id="' + id + '"]');
      if (!$box.length) return;
      if (state === 'saving') {
        $box.text(message || 'در حال ذخیره...').css('color', '#2563eb');
      } else if (state === 'saved') {
        $box.text(message || 'ذخیره شد').css('color', '#16a34a');
      } else if (state === 'error') {
        $box.text(message || 'خطا در ذخیره').css('color', '#dc2626');
      } else {
        $box.text(message || '').css('color', '#64748b');
      }
    }

    function syncLeadToMemory(id, data) {
      var lead = getLeadById(id);
      if (!lead) return;
      lead.customer_name = data.customer_name;
      lead.province = data.province;
      lead.city = data.city;
      lead.sales_prediction = data.sales_prediction;
      lead.note = data.note;
      lead.lead_status = data.lead_status;
    }

    function markLeadDirty(id, message) {
      if (!id) return;
      dirtyLeadIds[String(id)] = true;
      setLeadSaveState(id, 'idle', message || 'تغییرات ذخیره نشده؛ با تغییر تب ذخیره می‌شود');
    }

    function flushDirtyLeadSaves(options) {
      options = options || {};
      var ids = Object.keys(dirtyLeadIds || {});
      var requests = [];
      ids.forEach(function(id) {
        if (!dirtyLeadIds[id]) return;
        clearTimeout(saveTimers[id]);
        delete dirtyLeadIds[id];
        var req = saveLeadData(id, {
          savingText: options.savingText || 'در حال ذخیره تغییرات...',
          savedText: options.savedText || 'تغییرات ذخیره شد',
          noRender: options.noRender === true
        });
        if (req && req.then) requests.push(req);
      });
      return requests;
    }

    // ذخیره deferred هنگام تغییر تب، بدون گیر انداختن UI و بدون re-render سنگین جدول.
    $(document).on('click', '#sn-seller-panel .sn-tab', function() {
      flushDirtyLeadSaves({ savingText: 'در حال ذخیره قبل از تغییر تب...', savedText: 'تغییرات ذخیره شد', noRender: true });
    });

    function saveLeadData(id, options) {
      options = options || {};
      if (pendingSaves[id]) {
        pendingSaves[id].abort();
      }
      var payload = collectLeadFormData(id);
      setLeadSaveState(id, 'saving', options.savingText || 'در حال ذخیره...');
      pendingSaves[id] = $.ajax({
        url: ajax,
        type: 'POST',
        data: {
          action: 'sn_save_customer_info',
          nonce: nonce,
          lead_id: id,
          customer_name: payload.customer_name,
          province: payload.province,
          city: payload.city,
          sales_prediction: payload.sales_prediction,
          note: payload.note,
          lead_status: payload.lead_status
        }
      }).done(function(r) {
        if (r && r.success) {
          syncLeadToMemory(id, payload);
          if (!options.noRender) {
            renderLeadFilterBar();
            renderLeadsTable();
          }
          setLeadSaveState(id, 'saved', options.savedText || 'ذخیره شد');
          setTimeout(function(){ setLeadSaveState(id, 'idle', ''); }, 1500);
        } else {
          setLeadSaveState(id, 'error', (r && r.message) ? r.message : 'خطا در ذخیره');
        }
      }).fail(function(xhr, status) {
        if (status !== 'abort') {
          setLeadSaveState(id, 'error', 'خطا در ارتباط با سرور');
        }
      }).always(function() {
        delete pendingSaves[id];
      });
      return pendingSaves[id];
    }

    function queueLeadAutoSave(id, delay) {
      // از این نسخه، برای کاهش فشار AJAX روی سیستم‌های ضعیف،
      // تغییرات فرم فروشنده با تایپ/تغییر فیلد ذخیره نمی‌شود.
      // ذخیره فقط هنگام کلیک فروشنده روی تب دیگر یا قبل از صدور پیش‌فاکتور انجام می‌شود.
      clearTimeout(saveTimers[id]);
      markLeadDirty(id);
    }

    // toggle dropdown شماره
    $(document).on('click', '.sn-phone-toggle', function() {
      var id = $(this).data('id');
      var $expand = $('#sn-expand-' + id);
      var $allExpands = $('[id^="sn-expand-"]').not($expand);
      $allExpands.hide();
      if ($expand.is(':visible')) {
        $expand.hide();
        expandedLeadId = null;
      } else {
        $expand.show();
        expandedLeadId = String(id);
      }
    });

    $(document).on('click', '.sn-phone-copy', function(e) {
      e.preventDefault();
      var phone = String($(this).data('phone') || '');
      var $msg = $('.sn-copy-msg[data-phone="' + phone + '"]');
      function done() {
        $msg.text('شماره کپی شد').show();
        setTimeout(function(){ $msg.fadeOut(150); }, 1400);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(phone).then(done).catch(done);
      } else {
        var input = $('<input>').val(phone).appendTo('body').select();
        try { document.execCommand('copy'); } catch(e) {}
        input.remove();
        done();
      }
    });

    // تغییر استان → فقط آپدیت شهرها و علامت‌گذاری تغییرات
    $(document).on('change', '.sn-cust-prov', function() {
      var id = $(this).data('id');
      var prov = $(this).val();
      $('.sn-cust-city[data-id="' + id + '"]').html(snBuildCityOptions(prov, ''));
      queueLeadAutoSave(id, 200);
    });

    // تغییر شهر / پیش‌بینی فروش / وضعیت تماس = علامت‌گذاری برای ذخیره هنگام تغییر تب
    $(document).on('change', '.sn-cust-city, .sn-cust-pred, .sn-lead-status-select', function() {
      var id = $(this).data('id');
      queueLeadAutoSave(id, 200);
    });

    // نام و یادداشت = فقط علامت‌گذاری؛ بدون AJAX هنگام تایپ
    $(document).on('input', '.sn-cust-name, .sn-lead-note', function() {
      var id = $(this).data('id');
      markLeadDirty(id, 'تغییرات ذخیره نشده؛ با تغییر تب ذخیره می‌شود');
    });

    $(document).on('keydown', '.sn-lead-note, .sn-cust-name', function (e) {
      if (e.key === 'Enter' && (!$(this).hasClass('sn-lead-note') || e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        var id = $(this).data('id');
        clearTimeout(saveTimers[id]);
        delete dirtyLeadIds[id];
        saveLeadData(id, { savingText: 'در حال ذخیره...', savedText: 'ذخیره شد' });
      }
    });

    // دکمه صدور پیش‌فاکتور در لیست شماره‌ها
    $(document).on('click', '.sn-use-lead', function () {
      var phone = String($(this).data('phone') || '');
      var id    = String($(this).data('id')    || '');
      var lead  = getLeadById(id);

      if (saveTimers[id]) {
        clearTimeout(saveTimers[id]);
      }

      var currentFormSnapshot = null;
      if ($('.sn-cust-name[data-id="' + id + '"]').length) {
        currentFormSnapshot = {
          customer_name: $('.sn-cust-name[data-id="' + id + '"]').val() || '',
          phone: phone,
          province: $('.sn-cust-prov[data-id="' + id + '"]').val() || '',
          city: $('.sn-cust-city[data-id="' + id + '"]').val() || '',
          sales_prediction: $('.sn-cust-pred[data-id="' + id + '"]').val() || '',
          note: $('.sn-lead-note[data-id="' + id + '"]').val() || '',
          lead_status: $('select.sn-lead-status-select[data-id="' + id + '"]').val() || ''
        };
      }

      var openInvoiceForm = function() {
        var latestLead = $.extend({}, lead || {}, getLeadById(id) || {}, currentFormSnapshot || {});
        $('#sn-cust-name').val(latestLead.customer_name || '');
        $('#sn-cust-phone').val(latestLead.phone || phone);
        $('#sn-cust-prov').val(latestLead.province || '');
        var $cityField = $('#sn-cust-city');
        if ($cityField.is('select')) {
          $cityField.html(snBuildCityOptions(latestLead.province || '', latestLead.city || ''));
          $cityField.val((latestLead.city || '').trim());
          if (!latestLead.city) {
            $cityField.val('');
          }
        } else {
          $cityField.val(latestLead.city || '');
        }

        if ($('#sn-lead-select option[value="' + id + '"]').length === 0) {
          $('#sn-lead-select').append('<option value="' + id + '" data-phone="' + (latestLead.phone || phone) + '">' + (latestLead.phone || phone) + '</option>');
        }
        $('#sn-lead-select').val(id);
        $('#sn-product').val('');

        var $tabBtn = $('.sn-panel#sn-seller-panel .sn-tab[data-tab="new-invoice"]');
        if ($tabBtn.length) {
          $tabBtn.trigger('click');
        } else {
          $('.sn-panel#sn-seller-panel .sn-tab').removeClass('active');
          $('.sn-panel#sn-seller-panel .sn-tab-content').removeClass('active').hide();
          $('.sn-panel#sn-seller-panel .sn-tab[data-tab="new-invoice"]').addClass('active');
          $('#sn-tab-new-invoice').addClass('active').show();
        }

        setTimeout(function() {
          var $form = $('#sn-tab-new-invoice');
          if ($form.length) {
            $('html,body').animate({ scrollTop: $form.offset().top - 80 }, 400);
          }
        }, 150);
      };

      var hasOpenEditor = $('.sn-cust-name[data-id="' + id + '"]').length > 0;
      if (hasOpenEditor) {
        saveLeadData(id, { savingText: 'در حال ذخیره قبل از صدور...', savedText: 'اطلاعات ذخیره شد' });
        setTimeout(openInvoiceForm, 350);
      } else {
        openInvoiceForm();
      }
    });

    // autofill فرم فاکتور از lead انتخاب‌شده
    $(document).on('change', '#sn-lead-select', function () {
      var selectedId = $(this).val();
      var lead = getLeadById(selectedId);
      var phone = $(this).find(':selected').data('phone');
      if (lead) {
        $('#sn-cust-name').val(lead.customer_name || '');
        $('#sn-cust-phone').val(lead.phone || phone || '');
        $('#sn-cust-prov').val(lead.province || '');
        var $cityField = $('#sn-cust-city');
        if ($cityField.is('select')) {
          $cityField.html(snBuildCityOptions(lead.province || '', lead.city || ''));
          $cityField.val((lead.city || '').trim());
          if (!lead.city) {
            $cityField.val('');
          }
        } else {
          $cityField.val(lead.city || '');
        }
      } else if (phone) {
        $('#sn-cust-phone').val(phone);
      }
    });

    $(document).on('change', '#sn-cust-prov', function () {
      var prov = $(this).val() || '';
      var $cityField = $('#sn-cust-city');
      if ($cityField.is('select')) {
        var currentCity = $cityField.val() || '';
        $cityField.html(snBuildCityOptions(prov, currentCity));
        if (currentCity) {
          $cityField.val(currentCity);
        }
      }
    });

    function loadInvoices() {
      $('#sn-invoices-loading').show();
      $('#sn-invoices-list').html(snSkeletonRows(5, 7));
      $.post(ajax, { action: 'sn_seller_invoices', nonce: nonce }, function (res) {
        $('#sn-invoices-loading').hide();
        if (!res.success || !res.invoices || !res.invoices.length) {
          sellerInvoices = [];
          renderSellerKpis();
          $('#sn-invoices-list').html('<p class="sn-notice">هنوز فاکتوری صادر نشده.</p>');
          return;
        }
        sellerInvoices = res.invoices || [];
        renderSellerKpis();
        const statusMap = { pending: 'در انتظار پرداخت', pre_invoice: 'پیش‌فاکتور', receipt_uploaded: 'نیاز به بررسی فیش', pending_financial_approval: 'نیاز به بررسی فیش', paid: 'پرداخت‌شده', approved: 'تایید شده', rejected: 'رد شده', cancelled: 'لغو' };
        let html = '<div class="sn-table-wrap"><table class="sn-table"><thead><tr><th>کد</th><th>مشتری</th><th>موبایل</th><th>محصول</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ</th></tr></thead><tbody>';
        res.invoices.forEach(function (inv) {
          html += `<tr>
            <td><code>${inv.invoice_code}</code></td>
            <td>${inv.customer_name}</td>
            <td>${inv.customer_phone}</td>
            <td>${inv.product_name || inv.product_id}</td>
            <td>${Number(inv.product_price).toLocaleString('fa-IR')} ت</td>
            <td><span class="sn-status sn-status-${inv.status}">${statusMap[inv.status] || inv.status}</span></td>
            <td>${inv.created_at}</td>
          </tr>`;
        });
        html += '</tbody></table></div>';
        $('#sn-sellers-table').html(html);
        $('#sn-invoices-list').html(html);
      });
    }

    // Create invoice
    $(document).on('click', '#sn-create-invoice', function () {
      const $btn = $(this);
      const lead_id = $('#sn-lead-select').val();
      const selectedLead = lead_id ? getLeadById(lead_id) : null;
      const name    = ($('#sn-cust-name').val() || '').trim() || (selectedLead && selectedLead.customer_name ? selectedLead.customer_name : '');
      const phone   = ($('#sn-cust-phone').val() || '').trim() || (selectedLead && selectedLead.phone ? selectedLead.phone : '');
      let prov      = $('#sn-cust-prov').val() || (selectedLead && selectedLead.province ? selectedLead.province : '');
      let city      = ($('#sn-cust-city').val() || '').trim() || (selectedLead && selectedLead.city ? String(selectedLead.city).trim() : '');
      const prod    = $('#sn-product').val();

      if (!city && lead_id && $('.sn-cust-city[data-id="' + lead_id + '"]').length) {
        city = ($('.sn-cust-city[data-id="' + lead_id + '"]').val() || '').trim();
      }
      if (!prov && lead_id && $('.sn-cust-prov[data-id="' + lead_id + '"]').length) {
        prov = $('.sn-cust-prov[data-id="' + lead_id + '"]').val() || '';
      }

      if (!name || !phone || !prod) {
        showNotice('#sn-invoice-notice', 'لطفاً نام، موبایل و محصول را وارد کنید.', 'error');
        return;
      }

      $btn.prop('disabled', true).text('در حال صدور...');
      
      // XHR مستقیم — jQuery ممکنه response خراب رو parse نکنه
      var xhrObj = new XMLHttpRequest();
      xhrObj.open('POST', ajax, true);
      xhrObj.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
      xhrObj.onload = function() {
        $btn.prop('disabled', false).text('صدور پیش‌فاکتور و ارسال پیامک');
        var raw = xhrObj.responseText || '';
        // پیدا کردن JSON حتی اگه قبلش garbage باشه
        var res = null;
        var m = raw.match(/\{[\s\S]*"success"[\s\S]*\}/);
        if (m) { try { res = JSON.parse(m[0]); } catch(e) {} }
        if (!res) { try { res = JSON.parse(raw); } catch(e) {} }
        
        if (res && res.success) {
          $('#sn-cust-name,#sn-cust-phone,#sn-cust-city').val('');
          $('#sn-product,#sn-lead-select,#sn-cust-prov').val('');
          showNotice('#sn-invoice-notice',
            '✅ پیش‌فاکتور <strong>' + (res.invoice_code||'') + '</strong> صادر شد — پیامک برای مشتری ارسال گردید.',
            'success');
          loadLeads();
          loadInvoices();
          setTimeout(function() {
            $sellerPanel.find('.sn-tab[data-tab="invoices"]').trigger('click');
          }, 2000);
        } else {
          var msg = (res && res.message) ? res.message : ('کد HTTP: ' + xhrObj.status);
          showNotice('#sn-invoice-notice', '❌ ' + msg, 'error');
          console.error('Invoice fail. Raw:', raw.substring(0,400));
        }
      };
      xhrObj.onerror = function() {
        $btn.prop('disabled', false).text('صدور پیش‌فاکتور و ارسال پیامک');
        showNotice('#sn-invoice-notice', '❌ خطای شبکه', 'error');
      };
      xhrObj.send('action=sn_create_invoice&nonce=' + encodeURIComponent(nonce)
        + '&customer_name=' + encodeURIComponent(name)
        + '&customer_phone=' + encodeURIComponent(phone)
        + '&province=' + encodeURIComponent(prov)
        + '&city=' + encodeURIComponent(city)
        + '&product_id=' + encodeURIComponent(prod)
        + '&lead_id=' + encodeURIComponent(lead_id||'')
      );
    });
  }

  function snEsc(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }



  // ============================================================
  // HELPERS
  // ============================================================
  function showNotice(selector, msg, type) {
    $(selector).html(`<div class="sn-notice sn-${type}">${msg}</div>`);
    setTimeout(function () { $(selector).empty(); }, 6000);
  }

}(jQuery));

/* SN 1.0.8 complete workflow-lite additions */
(function($){
  'use strict';
  if (!$('#sn-seller-panel').length) return;
  var ajax = (window.snAjax||window.snData||{}).ajaxurl;
  var nonce = (window.snAjax||window.snData||{}).nonce;
  function money(v){ try { return Number(v||0).toLocaleString('fa-IR') + ' تومان'; } catch(e){ return (v||0)+' تومان'; } }
  function esc(v){ return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];}); }
  function productOptions(){ return $('#sn-product').html() || ''; }
  function recalcProducts(){ var total=0; $('.sn-product-row').each(function(){ var $r=$(this), price=Number($r.find('option:selected').data('price')||0), q=Math.max(1,Number($r.find('.sn-product-qty').val()||1)); total += price*q; }); $('#sn-products-total').text('جمع: '+money(total)); $('.sn-remove-product').toggle($('.sn-product-row').length>1); }
  $(document).on('click','#sn-add-product-row',function(){ $('#sn-products-multi').append('<div class="sn-product-row"><select class="sn-product-select">'+productOptions()+'</select><input type="number" class="sn-product-qty" min="1" value="1"><button type="button" class="sn-btn sn-btn-ghost sn-remove-product">حذف</button></div>'); recalcProducts(); });
  $(document).on('click','.sn-remove-product',function(){ $(this).closest('.sn-product-row').remove(); recalcProducts(); });
  $(document).on('change input','.sn-product-select,.sn-product-qty',recalcProducts);
  $(recalcProducts);

  var activeInvoiceTab = 'all';
  function loadSellerInvoices(){
    $('#sn-invoices-loading').show(); $('#sn-invoices-list').html('<div class="sn-loading">در حال بارگذاری...</div>');
    $.post(ajax,{action:'sn_seller_invoices',nonce:nonce,tab:activeInvoiceTab,limit:30},function(res){
      $('#sn-invoices-loading').hide();
      var rows = (res && (res.invoices || res.items)) || [];
      if(!res || !res.success || !rows.length){ $('#sn-invoices-list').html('<p class="sn-notice">موردی در این تب وجود ندارد.</p>'); return; }
      var html='<div class="sn-table-wrap"><table class="sn-table"><thead><tr><th>کد</th><th>مشتری</th><th>موبایل</th><th>محصول‌ها</th><th>مبلغ</th><th>وضعیت</th><th>دلیل رد/عملیات</th></tr></thead><tbody>';
      rows.forEach(function(inv){
        var ops='';
        if(String(inv.status)==='rejected') ops='<div class="sn-reject-reason">'+esc(inv.financial_reject_reason||inv.rejected_reason||'بدون دلیل ثبت‌شده')+'</div><button type="button" class="sn-btn sn-btn-sm sn-resend-financial" data-id="'+esc(inv.id)+'">ارجاع مجدد به مالی</button>';
        html+='<tr><td><code>'+esc(inv.invoice_code)+'</code></td><td>'+esc(inv.customer_name)+'</td><td>'+esc(inv.customer_phone)+'</td><td>'+esc(inv.product_name||inv.product_id)+'</td><td>'+esc(inv.amount_fmt||money(inv.product_price))+'</td><td><span class="sn-status sn-status-'+esc(inv.status)+'">'+esc(inv.status_label||inv.status)+'</span></td><td>'+ops+'</td></tr>';
      });
      html+='</tbody></table></div>'; $('#sn-invoices-list').html(html);
    });
  }
  $(document).on('click','.sn-invoice-status-tabs .sn-subtab',function(){ $('.sn-invoice-status-tabs .sn-subtab').removeClass('active'); $(this).addClass('active'); activeInvoiceTab=$(this).data('status')||'all'; loadSellerInvoices(); });
  $(document).on('click','#sn-seller-panel .sn-tab[data-tab="invoices"]',function(){ setTimeout(loadSellerInvoices,20); });
  $(document).on('click','.sn-resend-financial',function(){ var id=$(this).data('id'), note=prompt('توضیح پیگیری برای مالی:')||''; $.post(ajax,{action:'sn_seller_resend_financial',nonce:nonce,invoice_id:id,note:note},function(res){ alert((res&&res.message)||'انجام شد'); loadSellerInvoices(); }); });

  var customerActionsPage = 1, customerActionsLoading = false, customerActionsLoaded = false;
  function actionLabel(key, desc){
    var m={customer_invoice_view:'مشاهده لینک فاکتور',customer_product_info:'مطالعه اطلاعات محصول',customer_lottery_info:'مشاهده شانس قرعه‌کشی',customer_wheel_open:'باز کردن گردونه',customer_wheel_spin:'چرخاندن گردونه',customer_reward_apply:'اعمال جایزه',customer_reward_decline:'عدم استفاده از جایزه',customer_coupon_open:'باز کردن کد تخفیف',customer_coupon_apply:'اعمال کد تخفیف',customer_coupon_remove:'لغو کد تخفیف',customer_recontact:'درخواست ارتباط مجدد',customer_pay_online:'انتخاب پرداخت آنلاین',customer_pay_card:'انتخاب کارت‌به‌کارت',customer_receipt_upload:'آپلود فیش',customer_manual_payment:'ثبت اطلاعات واریزی'};
    return desc || m[key] || key || '—';
  }
  function ensureActionsModal(){
    if($('#sn-customer-actions-modal').length) return;
    $('body').append('<div id="sn-customer-actions-modal" class="sn-modal sn-lite-modal" aria-hidden="true"><div class="sn-modal-backdrop sn-actions-close"></div><div class="sn-modal-card"><div class="sn-modal-head"><h3>جزئیات رفتار مشتری</h3><button type="button" class="sn-modal-x sn-actions-close">×</button></div><div id="sn-customer-actions-detail" class="sn-modal-body"><div class="sn-loading">در حال بارگذاری...</div></div></div></div>');
  }
  function loadCustomerActions(reset){
    var $box=$('#sn-customer-actions-list'), $loading=$('#sn-customer-actions-loading');
    if(!$box.length || customerActionsLoading) return;
    if(reset){ customerActionsPage=1; customerActionsLoaded=false; $box.empty(); }
    customerActionsLoading=true;
    if(customerActionsPage===1){ $loading.show(); $box.html('<div class="sn-loading">در حال بارگذاری...</div>'); }
    if(!ajax || !nonce){ customerActionsLoading=false; $loading.hide(); $box.html('<p class="sn-notice sn-error">تنظیمات AJAX پیدا نشد. صفحه را رفرش کنید.</p>'); return; }
    $.ajax({url:ajax,type:'POST',timeout:15000,data:{action:'sn_seller_customer_actions',nonce:nonce,page:customerActionsPage,limit:20}}).done(function(res){
      $loading.hide(); customerActionsLoading=false;
      if(!res||!res.success){ $box.html('<p class="sn-notice sn-error">خطا در دریافت رفتار مشتریان.</p>'); return; }
      var rows=(res.items||[]);
      if(!rows.length && customerActionsPage===1){ $box.html('<p class="sn-notice">هنوز پیش‌فاکتوری برای نمایش رفتار مشتری وجود ندارد.</p>'); return; }
      var html = customerActionsPage===1 ? '<div class="sn-table-wrap"><table class="sn-table sn-customer-actions-table"><thead><tr><th>شماره مشتری</th><th>اسم</th><th>شماره فاکتور</th><th>آخرین فعالیت</th><th>مشاهده کامل</th></tr></thead><tbody>' : '';
      rows.forEach(function(r){
        var latest=actionLabel(r.latest_action_key,r.latest_action);
        if(!r.action_count || Number(r.action_count)===0) latest='هیچ فعالیتی ثبت نشده';
        var time=r.latest_at_jalali ? '<br><small>'+esc(r.latest_at_jalali)+'</small>' : '';
        html+='<tr><td>'+esc(r.customer_phone||'—')+'</td><td>'+esc(r.customer_name||'—')+'</td><td><code>'+esc(r.invoice_code||'—')+'</code></td><td><span class="sn-action-badge">'+esc(latest)+'</span>'+time+'</td><td><button type="button" class="sn-btn sn-btn-sm sn-view-customer-actions" data-id="'+esc(r.invoice_id)+'" data-code="'+esc(r.invoice_code||'')+'">مشاهده کامل</button></td></tr>';
      });
      if(customerActionsPage===1) html+='</tbody></table></div><div class="sn-actions-more-wrap"></div>';
      if(customerActionsPage===1){ $box.html(html); } else { $box.find('tbody').append($(html)); }
      customerActionsLoaded=true;
      var $more=$box.find('.sn-actions-more-wrap');
      if(res.has_more){ $more.html('<button type="button" class="sn-btn sn-btn-secondary sn-load-more-actions">نمایش بیشتر</button>'); customerActionsPage++; }
      else { $more.html(res.total ? '<small class="sn-muted">همه ردیف‌ها نمایش داده شد.</small>' : ''); }
    }).fail(function(xhr){ customerActionsLoading=false; $loading.hide(); $box.html('<p class="sn-notice sn-error">خطای ارتباط با سرور یا زمان‌بر شدن درخواست. دوباره تلاش کنید.</p>'); });
  }
  function loadCustomerActionDetail(invoiceId, code){
    ensureActionsModal();
    $('#sn-customer-actions-modal').fadeIn(120).attr('aria-hidden','false');
    $('#sn-customer-actions-detail').html('<div class="sn-loading">در حال بارگذاری...</div>');
    $.ajax({url:ajax,type:'POST',timeout:15000,data:{action:'sn_seller_customer_actions',nonce:nonce,invoice_id:invoiceId,limit:200}}).done(function(res){
      if(!res||!res.success){ $('#sn-customer-actions-detail').html('<p class="sn-notice sn-error">خطا در دریافت جزئیات.</p>'); return; }
      var rows=res.items||[];
      var html='<div class="sn-profile-card"><h4>فاکتور '+esc(code||'')+'</h4>';
      if(!rows.length){ html+='<p class="sn-notice">برای این فاکتور هنوز فعالیتی ثبت نشده است.</p>'; }
      else{
        html+='<div class="sn-timeline">';
        rows.forEach(function(r){
          var ctx=''; try{var parsed=JSON.parse(r.context||'{}'); ctx=parsed.label||parsed.product||parsed.coupon||parsed.reward||'';}catch(e){ctx='';}
          html+='<div class="sn-timeline-item"><div class="sn-timeline-date">'+esc(r.created_at_jalali||r.created_at||'')+'</div><strong>'+esc(actionLabel(r.action,r.description))+'</strong>'+(ctx?'<p>'+esc(ctx)+'</p>':'')+'</div>';
        });
        html+='</div>';
      }
      html+='</div>';
      $('#sn-customer-actions-detail').html(html);
    }).fail(function(xhr){ $('#sn-customer-actions-detail').html('<p class="sn-notice sn-error">خطای سرور: '+xhr.status+'</p>'); });
  }
  $(document).on('click','#sn-seller-panel .sn-tab[data-tab="customer-actions"]',function(){ if(!customerActionsLoaded) setTimeout(function(){loadCustomerActions(true);},20); });
  $(document).on('click','.sn-load-more-actions',function(){ loadCustomerActions(false); });
  $(document).on('click','.sn-view-customer-actions',function(){ loadCustomerActionDetail($(this).data('id'),$(this).data('code')); });
  $(document).on('click','.sn-actions-close',function(){ $('#sn-customer-actions-modal').fadeOut(120).attr('aria-hidden','true'); });

  // override create invoice button to submit multi products (keeps old endpoint and SMS flow)
  $(document).off('click.snMulti','#sn-create-invoice').on('click.snMulti','#sn-create-invoice',function(e){
    e.preventDefault(); e.stopImmediatePropagation();
    var $btn=$(this), lead_id=$('#sn-lead-select').val()||'', name=($('#sn-cust-name').val()||'').trim(), phone=($('#sn-cust-phone').val()||'').trim(), prov=$('#sn-cust-prov').val()||'', city=($('#sn-cust-city').val()||'').trim();
    var ids=[], qtys=[]; $('.sn-product-row').each(function(){ var pid=$(this).find('.sn-product-select').val(); if(pid){ ids.push(pid); qtys.push(Math.max(1,Number($(this).find('.sn-product-qty').val()||1))); } });
    if(!name || !phone || !ids.length){ $('#sn-invoice-notice').html('<div class="sn-notice sn-error">نام، موبایل و حداقل یک محصول الزامی است.</div>'); return; }
    $btn.prop('disabled',true).text('در حال صدور...');
    var data={action:'sn_create_invoice',nonce:nonce,customer_name:name,customer_phone:phone,province:prov,city:city,lead_id:lead_id,product_id:ids[0]};
    ids.forEach(function(v,i){ data['product_ids['+i+']']=v; data['product_qtys['+i+']']=qtys[i]; });
    $.post(ajax,data,function(res){ $btn.prop('disabled',false).text('صدور پیش‌فاکتور و ارسال پیامک'); if(res&&res.success){ $('#sn-invoice-notice').html('<div class="sn-notice sn-success">'+res.message+'</div>'); $('#sn-cust-name,#sn-cust-phone,#sn-cust-city').val(''); $('.sn-product-row:not(:first)').remove(); $('.sn-product-select').val(''); $('.sn-product-qty').val(1); recalcProducts(); loadSellerInvoices(); } else { $('#sn-invoice-notice').html('<div class="sn-notice sn-error">'+((res&&res.message)||'خطا')+'</div>'); } });
  });

  $(document).on('click','.sn-wallet-tabs .sn-subtab',function(){ $('.sn-wallet-tabs .sn-subtab').removeClass('active'); $(this).addClass('active'); var f=$(this).data('wallet-filter'); var $rows=$('.sn-wallet-box tbody tr,.sn-wallet-box .sn-wallet-transaction'); $rows.show(); if(f!=='all'){ $rows.each(function(){ var pm=String($(this).data('payment-method')||''), t=$(this).text(); if(f==='online' && pm!=='online' && t.indexOf('آنلاین')===-1 && t.indexOf('online')===-1) $(this).hide(); if(f==='card_to_card' && pm!=='card_to_card' && pm!=='card' && t.indexOf('کارت')===-1 && t.indexOf('card')===-1) $(this).hide(); }); } });
})(jQuery);

/* SN 1.0.8 final override: remove legacy single-product invoice click */
(function($){
  if(!$('#sn-seller-panel').length) return;
  var ajax=(window.snAjax||window.snData||{}).ajaxurl, nonce=(window.snAjax||window.snData||{}).nonce;
  function recalc(){ var total=0; $('.sn-product-row').each(function(){ total += Number($(this).find('option:selected').data('price')||0) * Math.max(1,Number($(this).find('.sn-product-qty').val()||1)); }); try{$('#sn-products-total').text('جمع: '+total.toLocaleString('fa-IR')+' تومان');}catch(e){$('#sn-products-total').text('جمع: '+total+' تومان');} }
  $(document).off('click','#sn-create-invoice').on('click','#sn-create-invoice',function(e){
    e.preventDefault();
    var $btn=$(this), lead_id=$('#sn-lead-select').val()||'', name=($('#sn-cust-name').val()||'').trim(), phone=($('#sn-cust-phone').val()||'').trim(), prov=$('#sn-cust-prov').val()||'', city=($('#sn-cust-city').val()||'').trim();
    var ids=[], qtys=[]; $('.sn-product-row').each(function(){ var pid=$(this).find('.sn-product-select').val(); if(pid){ids.push(pid); qtys.push(Math.max(1,Number($(this).find('.sn-product-qty').val()||1)));} });
    if(!name || !phone || !ids.length){ $('#sn-invoice-notice').html('<div class="sn-notice sn-error">نام، موبایل و حداقل یک محصول الزامی است.</div>'); return; }
    var data={action:'sn_create_invoice',nonce:nonce,customer_name:name,customer_phone:phone,province:prov,city:city,lead_id:lead_id,product_id:ids[0]}; ids.forEach(function(v,i){data['product_ids['+i+']']=v; data['product_qtys['+i+']']=qtys[i];});
    $btn.prop('disabled',true).text('در حال صدور...'); $.post(ajax,data,function(res){$btn.prop('disabled',false).text('صدور پیش‌فاکتور و ارسال پیامک'); if(res&&res.success){$('#sn-invoice-notice').html('<div class="sn-notice sn-success">'+res.message+'</div>'); $('#sn-cust-name,#sn-cust-phone,#sn-cust-city').val(''); $('.sn-product-row:not(:first)').remove(); $('.sn-product-select').val(''); $('.sn-product-qty').val(1); recalc(); setTimeout(function(){ $('.sn-tab[data-tab="invoices"]').trigger('click'); }, 1800);} else {$('#sn-invoice-notice').html('<div class="sn-notice sn-error">'+((res&&res.message)||'خطا')+'</div>');}});
  });
})(jQuery);
