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
    if ($panel.is('#sn-seller-panel') && typeof flushDirtyLeadSaves === 'function') {
      flushDirtyLeadSaves({ savingText: 'در حال ذخیره قبل از تغییر تب...', savedText: 'تغییرات ذخیره شد' });
    }
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
      ids.forEach(function(id) {
        if (!dirtyLeadIds[id]) return;
        clearTimeout(saveTimers[id]);
        delete dirtyLeadIds[id];
        saveLeadData(id, {
          savingText: options.savingText || 'در حال ذخیره تغییرات...',
          savedText: options.savedText || 'تغییرات ذخیره شد'
        });
      });
    }

    $(document).on('click', '#sn-seller-panel .sn-tab', function() {
      flushDirtyLeadSaves({ savingText: 'در حال ذخیره قبل از تغییر تب...', savedText: 'تغییرات ذخیره شد' });
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
          renderLeadFilterBar();
          renderLeadsTable();
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
  // SUPERVISOR PANEL
  // ============================================================
  const $supPanel = $('#sn-supervisor-panel');
  if ($supPanel.length) {
    snEnsureDarkToggle($supPanel);

    function loadSupervisorData() {
      $('#sn-sellers-loading').show();
      $('#sn-sellers-table').html(snSkeletonRows(5, 7));
      var params = {
        action:     'sn_supervisor_data',
        nonce:      nonce,
        search:     $('#sn-seller-search').val() || '',
        filter_act: $('#sn-seller-filter-act').val() || 'all',
        date_from:  $('#sn-date-from').val() || '',
        date_to:    $('#sn-date-to').val() || '',
        time_from:  $('#sn-time-from').val() || '',
        time_to:    $('#sn-time-to').val() || '',
        seller_id:  $('#sn-summary-seller').val() || '',
        lead_status: $('#sn-summary-lead-status').val() || '',
        import_code: $('#sn-summary-import-code').val() || '',
        assignment: $('#sn-summary-assignment').val() || ''
      };
      $.post(ajax, params, function (res) {
        $('#sn-sellers-loading').hide();
        if (!res || !res.success) {
          var msg = (res && res.message) ? res.message : 'خطا در دریافت اطلاعات فروشنده‌ها';
          $('#sn-sellers-table').html('<div class="sn-notice sn-error">' + snEsc(msg) + '</div>');
          $('#sn-sellers-checkboxes').html('<div class="sn-notice sn-error">' + snEsc(msg) + '</div>');
          return;
        }
        renderSupervisorKpis(res);
        res.sellers = Array.isArray(res.sellers) ? res.sellers : [];

        $('#sn-unassigned-count').text(res.unassigned);
        if (res.summary) {
          $('#sn-sum-total').text(res.summary.total || 0);
          $('#sn-sum-assigned').text(res.summary.assigned || 0);
          $('#sn-sum-unassigned').text(res.summary.unassigned || 0);
          $('#sn-sum-range').text(res.summary.range_assigned || 0);
          $('#sn-sum-invoiced').text(res.summary.invoiced || 0);
          $('#sn-sum-paid').text(res.summary.paid || 0);
        }

        // جدول فروشندگان
        var html = '<div class="sn-table-wrap"><table class="sn-table sn-supervisor-sellers-table" style="width:100%">' +
          '<thead><tr><th>نام</th><th>شماره</th><th>تخصیص‌یافته</th><th>فاکتور</th><th>پرداخت‌شده</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
        res.sellers.forEach(function(s, i) {
          var bg = i%2===0 ? '#fff' : '#f8fafc';
          var activeLabel = s.is_active
            ? '<span style="background:#dcfce7;color:#16a34a;padding:2px 8px;border-radius:10px;font-size:.78rem">فعال</span>'
            : '<span style="background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:10px;font-size:.78rem">غیرفعال</span>';
          html += '<tr class="sn-seller-row" id="sn-seller-row-' + s.id + '" style="background:' + bg + '">' +
            '<td><strong>' + snEsc(s.name) + '</strong></td>' +
            '<td>' + snEsc(s.phone) + '</td>' +
            '<td>' + (s.lead_count || 0) + '</td>' +
            '<td>' + (s.invoice_count || 0) + '</td>' +
            '<td>' + (s.paid_count||0) + '</td>' +
            '<td>' + activeLabel + '</td>' +
            '<td><button type="button" class="sn-btn sn-btn-sm sn-seller-profile" data-id="' + s.id + '" aria-expanded="false">پروفایل</button> <button type="button" class="sn-btn sn-btn-sm sn-toggle-seller" data-id="' + s.id + '" data-active="' + (s.is_active?1:0) + '">' +
              (s.is_active ? '🔴 غیرفعال کن' : '🟢 فعال کن') + '</button></td>' +
          '</tr>' +
          '<tr class="sn-seller-profile-row" id="sn-seller-profile-row-' + s.id + '" style="display:none;background:#f0f9ff">' +
            '<td colspan="7"><div class="sn-seller-profile-panel" data-loaded="0"></div></td>' +
          '</tr>';
        });
        if (!res.sellers.length) {
          html += '<tr><td colspan="7"><div class="sn-notice">هیچ فروشنده‌ای برای این سرپرست پیدا نشد. از بخش مدیریت فروشنده‌ها، فروشنده‌ها را به این سرپرست تخصیص دهید یا مطمئن شوید نقش کاربر sn_seller است.</div></td></tr>';
        }
        html += '</tbody></table></div>';
        $("#sn-sellers-table").html(html);
        // checkbox های تخصیص
        var cbHtml = '<label style="display:block;margin-bottom:6px">' +
          '<input type="checkbox" id="sn-select-all-sellers"> <strong>انتخاب همه</strong></label><hr style="margin:6px 0">';
        res.sellers.filter(function(s){ return s.is_active; }).forEach(function(s) {
          cbHtml += '<label style="display:block;padding:3px 0"><input type="checkbox" class="sn-seller-cb" value="' + s.id + '"> ' + s.name + ' (' + s.phone + ')</label>';
        });
        $('#sn-sellers-checkboxes').html(cbHtml);

        // select فروشنده برای حالت دستی
        var optHtml = '<option value="">انتخاب فروشنده</option>';
        res.sellers.filter(function(s){ return s.is_active; }).forEach(function(s) {
          optHtml += '<option value="' + s.id + '">' + s.name + ' — ' + s.phone + '</option>';
        });
        $('#sn-manual-seller').html(optHtml);
        var currentSummarySeller = $('#sn-summary-seller').val() || '';
        $('#sn-summary-seller').html('<option value="">همه</option>' + optHtml.replace('<option value="">انتخاب فروشنده</option>', '')).val(currentSummarySeller);
        $('#sn-unassign-seller').html('<option value="">همه فروشنده‌ها</option>' + optHtml.replace('<option value="">انتخاب فروشنده</option>', ''));
        var statusOpts = '<option value="">همه</option>';
        (res.lead_statuses || []).forEach(function(st){ statusOpts += '<option value="' + snEsc(st) + '">' + snEsc(st) + '</option>'; });
        var currentStatus = $('#sn-summary-lead-status').val() || '';
        var currentUnStatus = $('#sn-unassign-lead-status').val() || '';
        $('#sn-summary-lead-status').html(statusOpts).val(currentStatus);
        $('#sn-unassign-lead-status').html(statusOpts).val(currentUnStatus);
      });
    }


    function renderSupervisorKpis(res) {
      var sellers = res.sellers || [];
      var summary = res.summary || {};
      var totalLeads = Number(summary.total_leads || 0);
      var assigned = Number(summary.assigned || 0);
      var unassigned = Number(summary.unassigned || summary.pool_count || 0);
      var invoices = Number(summary.invoices || 0);
      var sales = Number(summary.sales || summary.revenue || 0);
      if (!totalLeads && sellers.length) {
        totalLeads = sellers.reduce(function(sum, s){ return sum + Number(s.lead_count || 0); }, 0);
        invoices = sellers.reduce(function(sum, s){ return sum + Number(s.invoice_count || s.invoices || 0); }, 0);
        sales = sellers.reduce(function(sum, s){ return sum + Number(s.revenue || s.sales || 0); }, 0);
        assigned = totalLeads;
      }
      var html = '<div class="sn-kpi-grid sn-supervisor-kpis">' +
        '<div class="sn-kpi-card"><span class="sn-kpi-icon">👥</span><small>فروشنده‌های فعال</small><strong>' + snFormatNumber(sellers.filter(function(s){return !!s.is_active;}).length) + '</strong><em>از ' + snFormatNumber(sellers.length) + ' نیرو</em></div>' +
        '<div class="sn-kpi-card"><span class="sn-kpi-icon">📞</span><small>کل شماره‌ها</small><strong>' + snFormatNumber(totalLeads) + '</strong><em>در بازه انتخابی</em></div>' +
        '<div class="sn-kpi-card"><span class="sn-kpi-icon">📌</span><small>تخصیص‌داده‌شده</small><strong>' + snFormatNumber(assigned) + '</strong><em>خام/آزاد: ' + snFormatNumber(unassigned) + '</em></div>' +
        '<div class="sn-kpi-card sn-kpi-money"><span class="sn-kpi-icon">💳</span><small>فروش ثبت‌شده</small><strong>' + snFormatMoney(sales) + '</strong><em>' + snFormatNumber(invoices) + ' پیش‌فاکتور</em></div>' +
      '</div>';
      var $target = $('#sn-supervisor-kpi-cards');
      if (!$target.length) {
        $target = $('<div id="sn-supervisor-kpi-cards" class="sn-kpi-host"></div>');
        var $tab = $('#sn-tab-sellers');
        if ($tab.length) $tab.prepend($target); else $supPanel.prepend($target);
      }
      $target.html(html);
    }

    loadSupervisorData();

    // جستجو
    $('#sn-seller-search-btn').on('click', loadSupervisorData);
    var snSupFilterTimer = null;
    $('#sn-seller-search').on('input', function(){ clearTimeout(snSupFilterTimer); snSupFilterTimer = setTimeout(loadSupervisorData, 450); });
    $('#sn-seller-search').on('keydown', function(e){ if(e.key==='Enter') loadSupervisorData(); });
    $('#sn-seller-filter-act,#sn-date-from,#sn-date-to,#sn-time-from,#sn-time-to,#sn-summary-seller,#sn-summary-lead-status,#sn-summary-assignment').on('change', loadSupervisorData);
    $('#sn-summary-import-code').on('input', function(){ clearTimeout(snSupFilterTimer); snSupFilterTimer = setTimeout(loadSupervisorData, 450); });
    $(document).on('click', '#sn-summary-filter', loadSupervisorData);
    $(document).on('click', '#sn-summary-export', function(){
      var rows = [['فروشنده','موبایل','لیدها','فاکتورها','پرداخت شده','وضعیت']];
      $('#sn-sellers-table tbody tr.sn-seller-row').each(function(){
        var cols = $(this).children('td').map(function(){ return $(this).text().replace(/\s+/g, ' ').trim(); }).get();
        rows.push(cols.slice(0, 6));
      });
      var csv = rows.map(function(r){ return r.map(function(c){ return '"' + String(c).replace(/"/g,'""') + '"'; }).join(','); }).join('\n');
      var blob = new Blob(["\ufeff" + csv], {type:'text/csv;charset=utf-8;'});
      var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'supervisor-report.csv'; a.click(); URL.revokeObjectURL(a.href);
    });

    // select all
    $(document).on('change', '#sn-select-all-sellers', function() {
      $('.sn-seller-cb').prop('checked', $(this).is(':checked'));
    });

    // toggle فعال/غیرفعال
    $(document).on('click', '.sn-toggle-seller', function() {
      var $btn = $(this);
      var id = $btn.data('id');
      var isActive = $btn.data('active');
      var label = isActive ? 'غیرفعال' : 'فعال';
      if (!confirm('فروشنده ' + label + ' شود؟')) return;
      $.post(ajax, { action: 'sn_toggle_seller_active', nonce: nonce, seller_id: id }, function(res) {
        if (res.success) { loadSupervisorData(); }
        else alert('❌ ' + res.message);
      });
    });

    // پروفایل فروشنده برای سرپرست - کشویی باز/بسته می‌شود
    $(document).on("click", ".sn-seller-profile", function() {
      var $btn = $(this);
      var id = $btn.data("id");
      var $row = $('#sn-seller-profile-row-' + id);
      var $panel = $row.find('.sn-seller-profile-panel');

      // اگر باز است، با کلیک مجدد بسته شود
      if ($row.is(':visible')) {
        $row.slideUp(160);
        $btn.attr('aria-expanded', 'false').removeClass('active').text('پروفایل');
        return;
      }

      // فقط یک پروفایل همزمان باز باشد
      $('.sn-seller-profile-row:visible').slideUp(160);
      $('.sn-seller-profile').attr('aria-expanded', 'false').removeClass('active').text('پروفایل');
      $btn.attr('aria-expanded', 'true').addClass('active').text('بستن پروفایل');
      $row.slideDown(160);

      if ($panel.data('loaded') === 1) {
        return;
      }

      $panel.html('<div class="sn-loading">در حال بارگذاری پروفایل...</div>');
      $.post(ajax, { action: "sn_seller_profile", nonce: nonce, seller_id: id }, function(res) {
        if (!res.success) {
          $panel.html('<div class="sn-notice sn-error">❌ ' + snEsc(res.message || 'خطا در دریافت پروفایل') + '</div>');
          return;
        }
        var s = res.seller || {}, st = res.stats || {};
        var html = '<div class="sn-card sn-seller-profile-card">' +
          '<div class="sn-profile-head"><h3>پروفایل فروشنده: ' + snEsc(s.name || '—') + '</h3>' +
          '<span class="sn-badge">' + snEsc(s.phone || '—') + '</span></div>' +
          '<p><b>تاریخ عضویت:</b> ' + snEsc(s.registered || '—') + '</p>' +
          '<div class="sn-grid sn-seller-profile-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px">' +
          '<div class="sn-mini-stat"><b>لیدها</b><span>' + (st.leads || 0) + '</span></div>' +
          '<div class="sn-mini-stat"><b>فاکتورها</b><span>' + (st.invoices || 0) + '</span></div>' +
          '<div class="sn-mini-stat"><b>پرداخت‌شده</b><span>' + (st.paid || 0) + '</span></div>' +
          '<div class="sn-mini-stat"><b>فروش</b><span>' + (st.revenue || 0) + '</span></div></div>';
        html += '<h4>آخرین لیدها</h4><table class="sn-table"><thead><tr><th>شماره</th><th>شهر</th><th>وضعیت</th><th>تخصیص</th></tr></thead><tbody>';
        if ((res.recent_leads || []).length) {
          (res.recent_leads || []).forEach(function(l){
            html += '<tr><td>'+snEsc(l.phone||'')+'</td><td>'+snEsc((l.province||'')+' / '+(l.city||''))+'</td><td>'+snEsc(l.lead_status||l.status||'')+'</td><td>'+snEsc(l.assigned_at||'—')+'</td></tr>';
          });
        } else {
          html += '<tr><td colspan="4">لیدی ثبت نشده است.</td></tr>';
        }
        html += '</tbody></table><h4>آخرین فاکتورها</h4><table class="sn-table"><thead><tr><th>کد</th><th>مشتری</th><th>مبلغ</th><th>وضعیت</th></tr></thead><tbody>';
        if ((res.recent_invoices || []).length) {
          (res.recent_invoices || []).forEach(function(i){
            html += '<tr><td>'+snEsc(i.invoice_code||'')+'</td><td>'+snEsc(i.customer_name||'')+'</td><td>'+snEsc(i.product_price||0)+'</td><td>'+snEsc(i.status||'')+'</td></tr>';
          });
        } else {
          html += '<tr><td colspan="4">فاکتوری ثبت نشده است.</td></tr>';
        }
        html += '</tbody></table></div>';
        $panel.html(html).data('loaded', 1);
      }).fail(function() {
        $panel.html('<div class="sn-notice sn-error">❌ خطای ارتباط با سرور</div>');
      });
    });

    // Load unassigned phones for manual mode
    function loadUnassigned() {
      $.post(ajax, { action: 'sn_get_unassigned', nonce: nonce }, function (res) {
        if (!res.success || !res.leads) return;
        var html = '<label style="display:block;margin-bottom:6px"><input type="checkbox" id="sn-select-all-leads"> <strong>انتخاب همه</strong></label><hr style="margin:6px 0">';
        res.leads.forEach(function (l) {
          html += '<div class="sn-phone-item"><label><input type="checkbox" class="sn-lead-cb" value="' + l.id + '"> ' + l.phone + '</label></div>';
        });
        $('#sn-unassigned-list').html(html || '<p>شماره‌ای یافت نشد</p>');
      });
    }

    // select all leads
    $(document).on('change', '#sn-select-all-leads', function() {
      $('.sn-lead-cb').prop('checked', $(this).is(':checked'));
    });

    // Mode toggle
    $('input[name="assign_mode"]').on('change', function () {
      if ($(this).val() === 'manual') {
        $('#sn-assign-count-mode').hide();
        $('#sn-assign-manual-mode').show();
        loadUnassigned();
      } else {
        $('#sn-assign-count-mode').show();
        $('#sn-assign-manual-mode').hide();
      }
    });

    // Do assign
    $('#sn-do-assign').on('click', function () {
      var $btn = $(this);
      var mode = $('input[name="assign_mode"]:checked').val();
      var data = { action: 'sn_assign_leads', nonce: nonce, mode: mode };

      if (mode === 'count') {
        var sellerIds = [];
        $('.sn-seller-cb:checked').each(function () { sellerIds.push($(this).val()); });
        if (!sellerIds.length) { showNotice('#sn-assign-notice', 'فروشنده‌ای انتخاب نشده', 'error'); return; }
        data['seller_ids[]'] = sellerIds;
        data.count_per_seller = $("#sn-count-per-seller").val();
        if (!data.count_per_seller || Number(data.count_per_seller) < 1) { showNotice('#sn-assign-notice', 'تعداد را وارد کنید', 'error'); return; }
      } else {
        var leadIds = [];
        $('.sn-lead-cb:checked').each(function () { leadIds.push($(this).val()); });
        var sellerId = $('#sn-manual-seller').val();
        if (!sellerId || !leadIds.length) { showNotice('#sn-assign-notice', 'فروشنده و شماره را انتخاب کنید', 'error'); return; }
        data['seller_ids[]'] = [sellerId];
        data['lead_ids[]']   = leadIds;
      }

      $btn.prop('disabled', true).text('در حال پردازش...');
      $.post(ajax, data, function (res) {
        $btn.prop('disabled', false).text('اعمال تخصیص');
        if (res.success) {
          showNotice('#sn-assign-notice', '✅ ' + res.message, 'success');
          loadSupervisorData();
          if (mode === 'manual') loadUnassigned();
        } else {
          showNotice('#sn-assign-notice', '❌ ' + res.message, 'error');
        }
      });
    });
  }

  // ============================================================
  const $invPage = $('#sn-invoice-page');
  if ($invPage.length) {
    const initCode   = $invPage.data('code');
    const initResult = $invPage.data('result');

    if (initCode) {
      $('#sn-inv-code').val(initCode);
      if (initResult !== 'success' && initResult !== 'failed') {
        loadInvoice(initCode);
      } else if (initResult !== 'success') {
        loadInvoice(initCode);
      }
    }

    $('#sn-load-invoice').on('click', function () {
      const code = $('#sn-inv-code').val().trim();
      if (!code) return;
      loadInvoice(code);
    });

    function loadInvoice(code) {
      $.post(ajax, { action: 'sn_invoice_info', nonce: nonce, invoice_code: code }, function (res) {
        if (!res.success) {
          alert(res.message || 'فاکتور یافت نشد');
          return;
        }
        const inv = res.invoice;
        const card = res.card;

        $('#sn-inv-display-code').text(inv.code);
        $('#sn-inv-name').text(inv.customer_name);
        $('#sn-inv-phone').text(inv.customer_phone);
        const loc = [inv.province, inv.city].filter(Boolean).join(' — ');
        $('#sn-inv-location').text(loc || '—');
        $('#sn-inv-product').text(inv.product_name);
        $('#sn-inv-price').text(inv.price_fmt);
        $('#sn-inv-status').text(inv.status_label || snFaStatus(inv.status));
        $('#sn-card-number').text(card.number || '—');
        $('#sn-card-owner').text(card.owner || '—');

        if (inv.status === 'paid' || inv.status === 'approved') {
          $('#sn-payment-section').hide();
          $('#sn-inv-paid-msg').show();
        } else if (inv.status === 'receipt_uploaded' || inv.status === 'pending_financial_approval') {
          $('#sn-payment-section').hide();
          $('#sn-inv-paid-msg').show().removeClass('sn-success').addClass('sn-info').text('پرداخت/فیش شما ثبت شده و در انتظار بررسی مالی است.');
        } else if (inv.status === 'rejected') {
          $('#sn-payment-section').show();
          $('#sn-inv-paid-msg').show().removeClass('sn-success').addClass('sn-error').text('پرداخت قبلی رد شده است. می‌توانید دوباره فیش یا اطلاعات واریز را ثبت کنید.');
        } else {
          $('#sn-payment-section').show();
          $('#sn-inv-paid-msg').hide();
        }

        $('#sn-invoice-lookup').hide();
        $('#sn-invoice-detail').show();

        // store code for payment actions
        $invPage.data('active-code', code);
        $invPage.data('active-price', inv.product_price);
      });
    }

    // Online payment
    $('#sn-pay-online').on('click', function () {
      const code = $invPage.data('active-code');
      const $btn = $(this);
      $btn.prop('disabled', true).text('در حال اتصال به درگاه...');
      $.post(ajax, { action: 'sn_pay_online', nonce: nonce, invoice_code: code }, function (res) {
        $btn.prop('disabled', false).text('💳 پرداخت آنلاین (درگاه)');
        if (res.success && res.redirect) {
          window.location.href = res.redirect;
        } else {
          alert(res.message || 'خطا در اتصال به درگاه');
        }
      });
    });

    // Card payment
    $('#sn-pay-card').on('click', function () {
      $('#sn-card-info').slideDown();
      $('#sn-card-manual-fields').slideDown(); if(!$('#sn-card-paid-at').val()) $('#sn-card-paid-at').val(snCurrentJalaliDateTime());
    });


    function snToPersianDigits(v) {
      return String(v).replace(/\d/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[d]; });
    }
    function snGregorianToJalali(gy, gm, gd) {
      var g_d_m=[0,31,59,90,120,151,181,212,243,273,304,334];
      var gy2=(gm>2)?(gy+1):gy;
      var days=355666+(365*gy)+Math.floor((gy2+3)/4)-Math.floor((gy2+99)/100)+Math.floor((gy2+399)/400)+gd+g_d_m[gm-1];
      var jy=-1595+(33*Math.floor(days/12053)); days%=12053;
      jy+=4*Math.floor(days/1461); days%=1461;
      if(days>365){jy+=Math.floor((days-1)/365); days=(days-1)%365;}
      var jm=(days<186)?1+Math.floor(days/31):7+Math.floor((days-186)/30);
      var jd=1+((days<186)?(days%31):((days-186)%30));
      return [jy,jm,jd];
    }
    function snCurrentJalaliDateTime() {
      var d=new Date(), j=snGregorianToJalali(d.getFullYear(), d.getMonth()+1, d.getDate());
      var pad=function(n){return String(n).padStart(2,'0');};
      return snToPersianDigits(j[0]+'/'+pad(j[1])+'/'+pad(j[2])+' '+pad(d.getHours())+':'+pad(d.getMinutes()));
    }

    // Upload receipt
    $('#sn-upload-receipt').on('click', function () {
      const code = $invPage.data('active-code');
      const file = $('#sn-receipt-file')[0].files[0];
      if (!file) { alert('لطفاً فایل فیش را انتخاب کنید'); return; }

      const fd = new FormData();
      fd.append('action', 'sn_upload_receipt');
      fd.append('nonce', nonce);
      fd.append('invoice_code', code);
      fd.append('receipt', file);

      const $btn = $(this);
      $btn.prop('disabled', true).text('در حال ارسال...');
      $.ajax({
        url: ajax, type: 'POST', data: fd,
        processData: false, contentType: false,
        success: function (res) {
          $btn.prop('disabled', false).text('ارسال فیش');
          if (res.success) {
            $('#sn-card-info').hide();
            $('#sn-payment-section').hide();
            $('<div class="sn-notice sn-success">✅ ' + res.message + '</div>').insertBefore('#sn-payment-section');
            loadInvoice(code);
          } else {
            alert(res.message || 'خطا در آپلود');
          }
        },
      });
    });
  }

  // ============================================================
  // HELPERS
  // ============================================================
  function showNotice(selector, msg, type) {
    $(selector).html(`<div class="sn-notice sn-${type}">${msg}</div>`);
    setTimeout(function () { $(selector).empty(); }, 6000);
  }

}(jQuery));

/* SN vNext: supervisor assignment guard, unassign, manual card payment */
(function($){
  'use strict';
  if (typeof snAjax === 'undefined') return;
  var ajax = snAjax.ajaxurl, nonce = snAjax.nonce;
  var adminNonce = (snAjax.admin_nonce && snAjax.admin_nonce.length) ? snAjax.admin_nonce : nonce;

  function snNotice(sel, msg, type) {
    $(sel).html('<div class="sn-notice sn-' + (type || 'info') + '">' + msg + '</div>');
  }

  function updateAssignButtonState(){
    var mode = $('input[name="assign_mode"]:checked').val();
    var ok = true;
    if (mode === 'count') {
      var c = $('#sn-count-per-seller').val();
      ok = !!c && Number(c) > 0;
    }
    $('#sn-do-assign').prop('disabled', !ok);
  }
  $(document).on('input change', '#sn-count-per-seller, input[name="assign_mode"]', updateAssignButtonState);
  $(updateAssignButtonState);

  $(document).on('click', '#sn-do-unassign', function(e){
    e.preventDefault();
    if (!confirm('لیدهای مطابق فیلتر از فروشنده جدا شوند؟')) return;
    var $btn = $(this);
    $btn.prop('disabled', true).text('در حال انجام...');
    $.post(ajax, {
      action: 'sn_supervisor_unassign_leads', nonce: nonce,
      seller_id: $('#sn-unassign-seller').val() || '',
      count: $('#sn-unassign-count').val() || '',
      date_from: $('#sn-unassign-date-from').val() || '',
      date_to: $('#sn-unassign-date-to').val() || '',
      time_from: $('#sn-unassign-time-from').val() || '',
      time_to: $('#sn-unassign-time-to').val() || '',
      lead_status: $('#sn-unassign-lead-status').val() || '',
      import_code: $('#sn-unassign-import-code').val() || ''
    }, function(res){
      $btn.prop('disabled', false).text('جدا کردن و برگشت به لیست قابل تخصیص');
      if (res && res.success) snNotice('#sn-unassign-notice', '✅ ' + (res.message || 'انجام شد'), 'success');
      else snNotice('#sn-unassign-notice', '❌ ' + ((res && res.message) || 'خطا در عملیات'), 'error');
    }).fail(function(xhr){
      $btn.prop('disabled', false).text('جدا کردن و برگشت به لیست قابل تخصیص');
      snNotice('#sn-unassign-notice', '❌ خطای سرور: ' + xhr.status, 'error');
    });
  });

  $(document).on('click', '#sn-card-manual-toggle', function(){
    $('#sn-card-manual-fields').slideToggle();
  });

  $(document).on('click', '#sn-submit-manual-payment', function(e){
    e.preventDefault();
    var code = $('#sn-invoice-page').data('active-code') || $('#sn-inv-code').val();
    var from = $('#sn-card-from4').val();
    var to = $('#sn-card-to4').val();
    var amount = $('#sn-card-amount').val();
    var paidAt = $('#sn-card-paid-at').val();
    if (!/^\d{4}$/.test(from) || !/^\d{4}$/.test(to)) { alert('۴ رقم کارت باید عددی باشد'); return; }
    if (!amount || isNaN(String(amount).replace(/,/g,''))) { alert('مبلغ باید عدد باشد'); return; }
    var $btn = $(this).prop('disabled', true).text('در حال ثبت...');
    $.post(ajax, {action:'sn_submit_manual_payment', nonce: nonce, invoice_code: code, card_from: from, card_to: to, amount: amount, paid_at: paidAt}, function(res){
      $btn.prop('disabled', false).text('ثبت اطلاعات واریز');
      if (res && res.success) { $('#sn-payment-section').hide(); $('<div class="sn-notice sn-success">✅ '+res.message+'</div>').insertBefore('#sn-payment-section'); if (typeof loadInvoice === 'function') loadInvoice(code); }
      else alert((res && res.message) || 'خطا در ثبت اطلاعات واریز');
    }).fail(function(xhr){ $btn.prop('disabled', false).text('ثبت اطلاعات واریز'); alert('خطای سرور: '+xhr.status); });
  });

  function snFaStatus(st){ var m={pre_invoice:'پیش‌فاکتور',pending:'در انتظار پرداخت',pending_payment:'در انتظار پرداخت',receipt_uploaded:'نیاز به بررسی فیش',pending_financial_approval:'در انتظار تایید مالی',approved:'تایید شده',paid:'پرداخت‌شده',rejected:'رد شده',cancelled:'لغوشده',assigned:'تخصیص داده شده'}; return m[st]||st||'—'; }
  function loadSupervisorInvoices(q){
    if(!$('#sn-supervisor-invoice-list').length) return;
    $('#sn-supervisor-invoice-list').html('در حال بارگذاری پیش‌فاکتورها...');
    $.post(ajax,{action:'sn_supervisor_invoices',nonce:nonce,q:q||''},function(res){
      if(!res||!res.success){ $('#sn-supervisor-invoice-list').html('❌ '+((res&&res.message)||'خطا در دریافت پیش‌فاکتورها')); return; }
      var rows=res.items||[];
      var html='<table class="sn-table"><thead><tr><th>کد</th><th>مشتری</th><th>فروشنده</th><th>مبلغ</th><th>پرداخت</th><th>وضعیت</th><th>فیش سرپرست</th></tr></thead><tbody>';
      if(!rows.length) html+='<tr><td colspan="7">موردی یافت نشد.</td></tr>';
      rows.forEach(function(i){ html+='<tr><td><code>'+snEsc(i.invoice_code||'')+'</code></td><td>'+snEsc(i.customer_name||'')+'<br><small>'+snEsc(i.customer_phone||'')+'</small></td><td>'+snEsc(i.seller_name||'—')+'</td><td>'+snEsc(i.product_price||0)+'</td><td>'+snEsc(i.pay_method_label||'—')+'</td><td>'+snEsc(i.status_label||snFaStatus(i.status))+'</td><td><input type="file" class="sn-supervisor-receipt-file" data-id="'+i.id+'" accept="image/*,application/pdf"><button type="button" class="sn-btn sn-btn-sm sn-supervisor-upload-receipt" data-id="'+i.id+'">آپلود فیش</button><div class="sn-supervisor-upload-msg" data-id="'+i.id+'"></div></td></tr>'; });
      html+='</tbody></table>'; $('#sn-supervisor-invoice-list').html(html);
    }).fail(function(xhr){ $('#sn-supervisor-invoice-list').html('❌ خطای سرور: '+xhr.status); });
  }
  $(document).on('click','.sn-tab[data-tab="invoices"]',function(){loadSupervisorInvoices($('#sn-supervisor-invoice-search').val());});
  $(document).on('input','#sn-supervisor-invoice-search',function(){clearTimeout(window.snSupInvTimer);var q=$(this).val();window.snSupInvTimer=setTimeout(function(){loadSupervisorInvoices(q);},450);});
  if($('#sn-supervisor-invoice-list').length) loadSupervisorInvoices('');
  function snManagerFilters(){
    return {
      search: $('#sn-manager-search').val() || '',
      import_code: $('#sn-manager-import-code-filter').val() || $('#sn-manager-import-code').val() || '',
      date_from: $('#sn-manager-date-from').val() || '',
      date_to: $('#sn-manager-date-to').val() || '',
      time_from: $('#sn-manager-time-from').val() || '',
      time_to: $('#sn-manager-time-to').val() || '',
      status: $('#sn-manager-status').val() || 'all',
      assignment: $('#sn-manager-assignment').val() || 'all',
      supervisor_id: $('#sn-manager-supervisor-filter').val() || '',
      seller_id: $('#sn-manager-seller-filter').val() || '',
      lead_status: $('#sn-manager-lead-status').val() || ''
    };
  }
  function snLoadManagerLeads(){
    if(!$('#sn-manager-leads-list').length) return;
    $('#sn-manager-leads-list').html('در حال بارگذاری...');
    var data = snManagerFilters();
    data.action = 'sn_sales_manager_leads';
    data.nonce = nonce;
    $.post(ajax, data, function(res){
      if(!res || !res.success){
        $('#sn-manager-leads-list').html('❌ ' + ((res && res.message) || 'خطا در دریافت گزارش'));
        return;
      }
      $('#sn-manager-total').text(res.total || 0);
      var rows = res.items || [];
      var html = '<table class="sn-table sn-manager-table"><thead><tr><th>شماره</th><th>کد</th><th>موقعیت</th><th>وضعیت</th><th>سرپرست</th><th>فروشنده</th><th>ورود</th><th>تخصیص</th></tr></thead><tbody>';
      if(!rows.length) html += '<tr><td colspan="8">موردی با این فیلتر پیدا نشد.</td></tr>';
      rows.forEach(function(l){
        var loc = l.province && l.city ? (l.province + ' / ' + l.city) : (l.province || l.city || '—');
        html += '<tr><td><code>' + snEsc(l.phone || '') + '</code></td><td>' + snEsc(l.import_code || '—') + '</td><td>' + snEsc(loc) + '</td><td>' + snEsc(l.lead_status || l.status_label || l.status || '—') + '</td><td>' + snEsc(l.supervisor_name || '—') + '</td><td>' + snEsc(l.seller_name || '—') + '</td><td>' + snEsc(l.imported_at || '—') + '</td><td>' + snEsc(l.assigned_at || '—') + '</td></tr>';
      });
      html += '</tbody></table>';
      $('#sn-manager-leads-list').html(html);
    }).fail(function(xhr){ $('#sn-manager-leads-list').html('❌ خطای سرور: ' + xhr.status); });
  }
  $(document).on('click', '#sn-manager-filter', function(e){ e.preventDefault(); snLoadManagerLeads(); });
  $(document).on('change', '#sn-manager-status,#sn-manager-assignment,#sn-manager-supervisor-filter,#sn-manager-seller-filter,#sn-manager-lead-status,#sn-manager-date-from,#sn-manager-date-to,#sn-manager-time-from,#sn-manager-time-to', function(){ snLoadManagerLeads(); });
  $(document).on('input', '#sn-manager-search,#sn-manager-import-code-filter', function(){ clearTimeout(window.snManagerFilterTimer); window.snManagerFilterTimer = setTimeout(snLoadManagerLeads, 450); });
  $(document).on('click', '#sn-manager-export', function(e){
    e.preventDefault();
    var base = $(this).data('export-base');
    if(!base) return;
    var data = snManagerFilters();
    data.nonce = nonce;
    window.location.href = base + '&' + $.param(data);
  });
  if($('#sn-sales-manager-panel').length) snLoadManagerLeads();
  $(document).on('click', '#sn-manager-assign', function(e){
    e.preventDefault();
    var $btn = $(this);
    var sup = $('#sn-manager-supervisor').val();
    var count = $('#sn-manager-count').val();
    if(!sup || !count || Number(count) < 1){ snNotice('#sn-manager-assign-notice', 'سرپرست و تعداد را انتخاب کنید', 'error'); return; }
    $btn.prop('disabled', true).text('در حال انتقال...');
    var data = snManagerFilters();
    data.action = 'sn_assign_supervisor_leads';
    data.nonce = adminNonce || nonce;
    data.supervisor_id = sup;
    data.count = count;
    data.import_code = $('#sn-manager-import-code').val() || data.import_code || '';
    $.post(ajax, data, function(res){
      $btn.prop('disabled', false).text('انتقال به سرپرست');
      if(res && res.success){ snNotice('#sn-manager-assign-notice', '✅ ' + (res.message || 'انجام شد'), 'success'); snLoadManagerLeads(); }
      else snNotice('#sn-manager-assign-notice', '❌ ' + ((res && res.message) || 'خطا در انتقال'), 'error');
    }).fail(function(xhr){ $btn.prop('disabled', false).text('انتقال به سرپرست'); snNotice('#sn-manager-assign-notice', '❌ خطای سرور: '+xhr.status, 'error'); });
  });
  $(document).on('click','.sn-supervisor-upload-receipt',function(e){ e.preventDefault(); var id=$(this).data('id'), file=$('.sn-supervisor-receipt-file[data-id="'+id+'"]').get(0).files[0]; if(!file){alert('لطفاً فایل فیش را انتخاب کنید');return;} var fd=new FormData(); fd.append('action','sn_supervisor_upload_receipt'); fd.append('nonce',nonce); fd.append('invoice_id',id); fd.append('receipt',file); var $btn=$(this).prop('disabled',true).text('در حال آپلود...'); $.ajax({url:ajax,type:'POST',data:fd,processData:false,contentType:false,success:function(res){$btn.prop('disabled',false).text('آپلود فیش');$('.sn-supervisor-upload-msg[data-id="'+id+'"]').html(res&&res.success?'✅ '+res.message:'❌ '+((res&&res.message)||'خطا')); if(res&&res.success) loadSupervisorInvoices($('#sn-supervisor-invoice-search').val());},error:function(xhr){$btn.prop('disabled',false).text('آپلود فیش');alert('خطای سرور: '+xhr.status);}}); });
  $(document).on('click','.sn-fin-approve',function(e){e.preventDefault();var id=$(this).data('id'),$btn=$(this),$row=$btn.closest('tr');$btn.prop('disabled',true).text('در حال تایید...');$.post(ajax,{action:'sn_financial_approve_payment',nonce:nonce,invoice_id:id},function(res){if(res&&res.success){$row.find('td').eq(5).text((res.status_label||'تایید شده'));$row.find('td').last().html('<span class="sn-notice sn-success">تایید شد</span>');}else{$btn.prop('disabled',false).text('تایید');alert((res&&res.message)||'خطا');}}).fail(function(xhr){$btn.prop('disabled',false).text('تایید');alert('خطای سرور: '+xhr.status);});});
  $(document).on('click','.sn-fin-reject',function(e){e.preventDefault();var id=$(this).data('id'),reason=prompt('دلیل رد پرداخت را وارد کنید:');if(!reason)return;var $btn=$(this),$row=$btn.closest('tr');$btn.prop('disabled',true).text('در حال رد...');$.post(ajax,{action:'sn_financial_reject_payment',nonce:nonce,invoice_id:id,reason:reason},function(res){if(res&&res.success){$row.find('td').eq(5).text((res.status_label||'رد شده'));$row.find('td').last().html('<span class="sn-notice sn-error">رد شد</span>');}else{$btn.prop('disabled',false).text('رد');alert((res&&res.message)||'خطا');}}).fail(function(xhr){$btn.prop('disabled',false).text('رد');alert('خطای سرور: '+xhr.status);});});
})(jQuery);


/* SN Final AJAX/Persian/Jalali fixes - no refresh operations */
(function($){
  'use strict';
  window.snAjax = window.snAjax || window.snData || {};
  window.snData = window.snData || window.snAjax;
  var ajax = window.snAjax.ajaxurl || (window.ajaxurl || '/wp-admin/admin-ajax.php');
  var nonce = window.snAjax.nonce || window.snData.nonce || '';
  $(function(){ $('#sn-submit-manual-payment,#sn-card-manual-toggle').off('click'); });

  function esc(v){ return $('<div>').text(v == null || v === '' ? '—' : v).html(); }
  function toFaDigits(v){ return String(v).replace(/\d/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'[d];}); }
  function toEnDigits(v){ return String(v).replace(/[۰-۹]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);}).replace(/[٠-٩]/g,function(d){return '٠١٢٣٤٥٦٧٨٩'.indexOf(d);}); }
  function gregorianToJalali(gy, gm, gd) { var gdm=[0,31,59,90,120,151,181,212,243,273,304,334], gy2=(gm>2)?gy+1:gy; var days=355666+(365*gy)+Math.floor((gy2+3)/4)-Math.floor((gy2+99)/100)+Math.floor((gy2+399)/400)+gd+gdm[gm-1]; var jy=-1595+33*Math.floor(days/12053); days%=12053; jy+=4*Math.floor(days/1461); days%=1461; if(days>365){jy+=Math.floor((days-1)/365); days=(days-1)%365;} var jm=(days<186)?1+Math.floor(days/31):7+Math.floor((days-186)/30); var jd=1+((days<186)?days%31:(days-186)%30); return [jy,jm,jd]; }
  function currentJalali(){ var d=new Date(), j=gregorianToJalali(d.getFullYear(),d.getMonth()+1,d.getDate()); return {jy:j[0], jm:j[1], jd:j[2], hh:d.getHours(), mi:d.getMinutes()}; }
  function pad(n){ return String(n).padStart(2,'0'); }

  function buildJalaliPicker(){
    var $host=$('#sn-card-paid-at-picker'); if(!$host.length || $host.data('ready')) return;
    var now=currentJalali(), years='', months='', days='', hours='', mins='', rounded=Math.min(55, Math.floor(now.mi/5)*5);
    for(var y=now.jy-1;y<=now.jy+1;y++) years+='<option value="'+y+'" '+(y===now.jy?'selected':'')+'>'+toFaDigits(y)+'</option>';
    for(var m=1;m<=12;m++) months+='<option value="'+m+'" '+(m===now.jm?'selected':'')+'>'+toFaDigits(m)+'</option>';
    for(var d=1;d<=31;d++) days+='<option value="'+d+'" '+(d===now.jd?'selected':'')+'>'+toFaDigits(d)+'</option>';
    for(var h=0;h<24;h++) hours+='<option value="'+h+'" '+(h===now.hh?'selected':'')+'>'+toFaDigits(pad(h))+'</option>';
    for(var i=0;i<60;i+=5) mins+='<option value="'+i+'" '+(rounded===i?'selected':'')+'>'+toFaDigits(pad(i))+'</option>';
    $host.html('<div class="sn-jalali-row"><select id="sn-paid-jy">'+years+'</select><span>/</span><select id="sn-paid-jm">'+months+'</select><span>/</span><select id="sn-paid-jd">'+days+'</select><span class="sn-time-sep">ساعت</span><select id="sn-paid-hh">'+hours+'</select><span>:</span><select id="sn-paid-mi">'+mins+'</select></div><small>تاریخ و ساعت واریز را به شمسی انتخاب کنید.</small>').data('ready',1);
    syncJalaliPicker();
  }
  function syncJalaliPicker(){
    if(!$('#sn-card-paid-at-picker').length) return;
    var jy=$('#sn-paid-jy').val(), jm=pad($('#sn-paid-jm').val()), jd=pad($('#sn-paid-jd').val()), hh=pad($('#sn-paid-hh').val()), mi=pad($('#sn-paid-mi').val());
    $('#sn-card-paid-at').val(toFaDigits(jy+'/'+jm+'/'+jd+' '+hh+':'+mi));
  }
  $(document).on('change','#sn-paid-jy,#sn-paid-jm,#sn-paid-jd,#sn-paid-hh,#sn-paid-mi',syncJalaliPicker);
  $(document).on('click','#sn-pay-card,#sn-card-manual-toggle',function(){ $('#sn-card-manual-fields').slideDown(); setTimeout(buildJalaliPicker,30); });
  $(function(){ buildJalaliPicker(); });

  $(document).off('click', '#sn-submit-manual-payment').on('click', '#sn-submit-manual-payment', function(e){
    e.preventDefault(); buildJalaliPicker(); syncJalaliPicker();
    var code = $('#sn-invoice-page').data('active-code') || $('#sn-inv-code').val();
    var from = toEnDigits($('#sn-card-from4').val());
    var to = toEnDigits($('#sn-card-to4').val());
    var amount = toEnDigits($('#sn-card-amount').val()).replace(/,/g,'');
    if(!/^\d{4}$/.test(from) || !/^\d{4}$/.test(to)){ alert('۴ رقم آخر کارت باید عددی باشد'); return; }
    if(!amount || isNaN(amount)){ alert('مبلغ باید عدد باشد'); return; }
    var data={action:'sn_submit_manual_payment',nonce:nonce,invoice_code:code,card_from:from,card_to:to,amount:amount,paid_at:$('#sn-card-paid-at').val(),paid_jy:$('#sn-paid-jy').val(),paid_jm:$('#sn-paid-jm').val(),paid_jd:$('#sn-paid-jd').val(),paid_hh:$('#sn-paid-hh').val(),paid_mi:$('#sn-paid-mi').val()};
    var $btn=$(this).prop('disabled',true).text('در حال ثبت...');
    $.post(ajax,data,function(res){
      $btn.prop('disabled',false).text('ثبت اطلاعات واریز');
      if(res&&res.success){ $('#sn-payment-section').hide(); $('.sn-manual-result').remove(); $('<div class="sn-notice sn-success sn-manual-result">✅ '+esc(res.message||'اطلاعات واریز ثبت شد')+'</div>').insertBefore('#sn-payment-section'); if(typeof window.loadInvoice==='function') window.loadInvoice(code); }
      else alert((res&&res.message)||'خطا در ثبت اطلاعات واریز');
    }).fail(function(xhr){ $btn.prop('disabled',false).text('ثبت اطلاعات واریز'); alert('خطای سرور: '+xhr.status); });
  });

  $(document).off('click','.sn-fin-approve').on('click','.sn-fin-approve',function(e){
    e.preventDefault(); var id=$(this).data('id'), $btn=$(this), $row=$btn.closest('tr');
    $btn.prop('disabled',true).text('در حال تایید...');
    $.post(ajax,{action:'sn_financial_approve_payment',nonce:nonce,invoice_id:id},function(res){
      if(res&&res.success){ $row.addClass('sn-row-done'); $row.find('td').eq(5).text('تایید شده'); $row.find('td').last().html('<span class="sn-notice sn-success">✅ تایید شد</span>'); }
      else { $btn.prop('disabled',false).text('تایید'); alert((res&&res.message)||'خطا'); }
    }).fail(function(xhr){ $btn.prop('disabled',false).text('تایید'); alert('خطای سرور: '+xhr.status); });
  });
  $(document).off('click','.sn-fin-reject').on('click','.sn-fin-reject',function(e){
    e.preventDefault(); var reason=prompt('دلیل رد پرداخت را وارد کنید:'); if(!reason) return; var id=$(this).data('id'), $btn=$(this), $row=$btn.closest('tr');
    $btn.prop('disabled',true).text('در حال رد...');
    $.post(ajax,{action:'sn_financial_reject_payment',nonce:nonce,invoice_id:id,reason:reason},function(res){
      if(res&&res.success){ $row.addClass('sn-row-rejected'); $row.find('td').eq(5).text('رد شده'); $row.find('td').last().html('<span class="sn-notice sn-error">❌ رد شد</span>'); }
      else { $btn.prop('disabled',false).text('رد'); alert((res&&res.message)||'خطا'); }
    }).fail(function(xhr){ $btn.prop('disabled',false).text('رد'); alert('خطای سرور: '+xhr.status); });
  });

  function translatePanelText(){
    var map={'invoice':'پیش‌فاکتور','invoices':'پیش‌فاکتورها','assigned':'تخصیص داده‌شده','unassigned':'بدون تخصیص','pending':'در انتظار','approved':'تایید شده','rejected':'رد شده','paid':'پرداخت‌شده','online':'پرداخت آنلاین','card':'کارت به کارت'};
    $('.sn-panel, .sn-invoice-page').find('td,th,span,strong,option,button,label,small').contents().filter(function(){return this.nodeType===3;}).each(function(){ var t=this.nodeValue; Object.keys(map).forEach(function(k){ t=t.replace(new RegExp('\\b'+k+'\\b','g'),map[k]); }); this.nodeValue=t; });
  }
  $(document).ajaxComplete(function(){ setTimeout(translatePanelText,30); });
  $(translatePanelText);
})(jQuery);

/* SN Verified Final Fixes: AJAX receipt/manual payment + real Jalali picker + Persian labels */
(function($){
  'use strict';
  window.snAjax = window.snAjax || window.snData || {};
  window.snData = window.snData || window.snAjax;
  var ajax = window.snAjax.ajaxurl || window.snData.ajaxurl || window.ajaxurl || '/wp-admin/admin-ajax.php';
  var nonce = window.snAjax.nonce || window.snData.nonce || '';

  function esc(v){ return $('<div>').text(v == null || v === '' ? '—' : v).html(); }
  function faDigits(v){ return String(v).replace(/\d/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'[d];}); }
  function enDigits(v){ return String(v).replace(/[۰-۹]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);}).replace(/[٠-٩]/g,function(d){return '٠١٢٣٤٥٦٧٨٩'.indexOf(d);}); }
  function pad(n){ return String(n).padStart(2,'0'); }
  function faStatus(v){
    var m={
      pre_invoice:'پیش‌فاکتور', pending:'در انتظار پرداخت', pending_payment:'در انتظار پرداخت',
      receipt_uploaded:'نیاز به بررسی فیش', pending_financial_approval:'نیاز به بررسی فیش',
      paid:'پرداخت‌شده درگاهی', approved:'تایید شده مالی', rejected:'رد شده', cancelled:'لغوشده',
      assigned:'تخصیص داده‌شده', unassigned:'بدون تخصیص', supervisor_pool:'در پنل سرپرست', invoiced:'پیش‌فاکتور صادر شده',
      online:'پرداخت آنلاین', gateway:'درگاه پرداخت', card:'کارت به کارت', customer_upload:'ثبت توسط مشتری', supervisor_upload:'ثبت توسط سرپرست'
    };
    return m[v] || v || '—';
  }
  function gregorianToJalali(gy, gm, gd) {
    var gdm=[0,31,59,90,120,151,181,212,243,273,304,334], gy2=(gm>2)?gy+1:gy;
    var days=355666+(365*gy)+Math.floor((gy2+3)/4)-Math.floor((gy2+99)/100)+Math.floor((gy2+399)/400)+gd+gdm[gm-1];
    var jy=-1595+33*Math.floor(days/12053); days%=12053; jy+=4*Math.floor(days/1461); days%=1461;
    if(days>365){jy+=Math.floor((days-1)/365); days=(days-1)%365;}
    var jm=(days<186)?1+Math.floor(days/31):7+Math.floor((days-186)/30);
    var jd=1+((days<186)?days%31:(days-186)%30);
    return [jy,jm,jd];
  }
  function todayJalali(){ var d=new Date(), j=gregorianToJalali(d.getFullYear(),d.getMonth()+1,d.getDate()); return {jy:j[0],jm:j[1],jd:j[2],hh:d.getHours(),mi:Math.floor(d.getMinutes()/5)*5}; }

  function buildVerifiedJalaliPicker(){
    var $host=$('#sn-card-paid-at-picker');
    if(!$host.length) return;
    var now=todayJalali(), years='', months='', days='', hours='', mins='';
    for(var y=now.jy-2;y<=now.jy+1;y++) years+='<option value="'+y+'" '+(y===now.jy?'selected':'')+'>'+faDigits(y)+'</option>';
    for(var m=1;m<=12;m++) months+='<option value="'+m+'" '+(m===now.jm?'selected':'')+'>'+faDigits(m)+'</option>';
    for(var d=1;d<=31;d++) days+='<option value="'+d+'" '+(d===now.jd?'selected':'')+'>'+faDigits(d)+'</option>';
    for(var h=0;h<24;h++) hours+='<option value="'+h+'" '+(h===now.hh?'selected':'')+'>'+faDigits(pad(h))+'</option>';
    for(var i=0;i<60;i+=5) mins+='<option value="'+i+'" '+(i===now.mi?'selected':'')+'>'+faDigits(pad(i))+'</option>';
    $host.html('<div class="sn-jalali-picker-box"><div class="sn-jalali-title">انتخاب تاریخ و ساعت واریز شمسی</div><div class="sn-jalali-row"><label>سال<select id="sn-paid-jy">'+years+'</select></label><label>ماه<select id="sn-paid-jm">'+months+'</select></label><label>روز<select id="sn-paid-jd">'+days+'</select></label><label>ساعت<select id="sn-paid-hh">'+hours+'</select></label><label>دقیقه<select id="sn-paid-mi">'+mins+'</select></label></div><div class="sn-jalali-selected">تاریخ انتخاب‌شده: <strong id="sn-paid-at-view"></strong></div></div>');
    syncVerifiedJalaliPicker();
  }
  function syncVerifiedJalaliPicker(){
    if(!$('#sn-paid-jy').length) return;
    var val=$('#sn-paid-jy').val()+'/'+pad($('#sn-paid-jm').val())+'/'+pad($('#sn-paid-jd').val())+' '+pad($('#sn-paid-hh').val())+':'+pad($('#sn-paid-mi').val());
    $('#sn-card-paid-at').val(faDigits(val));
    $('#sn-paid-at-view').text(faDigits(val));
  }
  $(document).on('change','#sn-paid-jy,#sn-paid-jm,#sn-paid-jd,#sn-paid-hh,#sn-paid-mi',syncVerifiedJalaliPicker);

  function showCardPaymentBox(){
    $('#sn-card-info').slideDown();
    $('#sn-card-manual-toggle').hide();
    $('#sn-card-manual-fields').slideDown();
    buildVerifiedJalaliPicker();
    syncVerifiedJalaliPicker();
  }

  function refreshInvoiceBox(code){
    if(!code) code = $('#sn-invoice-page').data('active-code') || $('#sn-inv-code').val();
    if(!code) return;
    $.post(ajax,{action:'sn_invoice_info',nonce:nonce,invoice_code:code},function(res){
      if(!res || !res.success || !res.invoice) return;
      var inv=res.invoice, card=res.card||{};
      $('#sn-inv-display-code').text(inv.code||'');
      $('#sn-inv-name').text(inv.customer_name||'');
      $('#sn-inv-phone').text(inv.customer_phone||'');
      $('#sn-inv-location').text([inv.province,inv.city].filter(Boolean).join(' — ') || '—');
      $('#sn-inv-product').text(inv.product_name||'');
      $('#sn-inv-price').text(inv.price_fmt||'');
      $('#sn-inv-status').text(inv.status_label || faStatus(inv.status));
      $('#sn-card-number').text(card.number||'—');
      $('#sn-card-owner').text(card.owner||'—');
      $('#sn-invoice-lookup').hide(); $('#sn-invoice-detail').show();
      $('#sn-invoice-page').data('active-code', code).data('active-price', inv.product_price||0);
      if(inv.status==='pending_financial_approval' || inv.status==='receipt_uploaded'){
        $('#sn-payment-section').hide();
        $('#sn-inv-paid-msg').show().removeClass('sn-success sn-error').addClass('sn-info').text('پرداخت/فیش ثبت شده و در وضعیت «نیاز به بررسی فیش» قرار دارد.');
      } else if(inv.status==='paid' || inv.status==='approved'){
        $('#sn-payment-section').hide();
        $('#sn-inv-paid-msg').show().removeClass('sn-error sn-info').addClass('sn-success').text('این فاکتور پرداخت و تایید شده است.');
      } else if(inv.status==='rejected'){
        $('#sn-payment-section').show();
        $('#sn-inv-paid-msg').show().removeClass('sn-success sn-info').addClass('sn-error').text('پرداخت قبلی رد شده است. دوباره فیش یا اطلاعات واریزی را ثبت کنید.');
      } else {
        $('#sn-payment-section').show(); $('#sn-inv-paid-msg').hide();
      }
    });
  }
  window.snRefreshInvoiceBox = refreshInvoiceBox;

  $(function(){
    $('#sn-upload-receipt,#sn-pay-card,#sn-card-manual-toggle,#sn-submit-manual-payment').off('click');
    if($('#sn-card-paid-at-picker').length) buildVerifiedJalaliPicker();
  });

  $(document).off('click.snVerifiedCard','#sn-pay-card,#sn-card-manual-toggle').on('click.snVerifiedCard','#sn-pay-card,#sn-card-manual-toggle',function(e){
    e.preventDefault(); showCardPaymentBox();
  });

  $(document).off('click.snVerifiedReceipt','#sn-upload-receipt').on('click.snVerifiedReceipt','#sn-upload-receipt',function(e){
    e.preventDefault();
    var code=$('#sn-invoice-page').data('active-code') || $('#sn-inv-code').val();
    var input=$('#sn-receipt-file').get(0);
    var file=input && input.files ? input.files[0] : null;
    if(!code){ alert('کد فاکتور مشخص نیست'); return; }
    if(!file){ alert('لطفاً فایل فیش را انتخاب کنید'); return; }
    var fd=new FormData(); fd.append('action','sn_upload_receipt'); fd.append('nonce',nonce); fd.append('invoice_code',code); fd.append('receipt',file);
    var $btn=$(this).prop('disabled',true).text('در حال ارسال...');
    $.ajax({url:ajax,type:'POST',data:fd,processData:false,contentType:false,success:function(res){
      $btn.prop('disabled',false).text('ارسال فیش');
      if(res&&res.success){
        $('.sn-upload-result').remove();
        $('<div class="sn-notice sn-success sn-upload-result">✅ '+esc(res.message||'فیش ثبت شد')+'</div>').insertBefore('#sn-payment-section');
        refreshInvoiceBox(code);
      } else alert((res&&res.message)||'خطا در آپلود فیش');
    },error:function(xhr){ $btn.prop('disabled',false).text('ارسال فیش'); alert('خطای سرور: '+xhr.status); }});
  });

  $(document).off('click.snVerifiedManual','#sn-submit-manual-payment').on('click.snVerifiedManual','#sn-submit-manual-payment',function(e){
    e.preventDefault(); buildVerifiedJalaliPicker(); syncVerifiedJalaliPicker();
    var code=$('#sn-invoice-page').data('active-code') || $('#sn-inv-code').val();
    var from=enDigits($('#sn-card-from4').val()), to=enDigits($('#sn-card-to4').val()), amount=enDigits($('#sn-card-amount').val()).replace(/,/g,'');
    if(!code){ alert('کد فاکتور مشخص نیست'); return; }
    if(!/^\d{4}$/.test(from) || !/^\d{4}$/.test(to)){ alert('۴ رقم آخر کارت باید عددی باشد'); return; }
    if(!amount || isNaN(amount)){ alert('مبلغ باید عدد باشد'); return; }
    var data={action:'sn_submit_manual_payment',nonce:nonce,invoice_code:code,card_from:from,card_to:to,amount:amount,paid_at:$('#sn-card-paid-at').val(),paid_jy:$('#sn-paid-jy').val(),paid_jm:$('#sn-paid-jm').val(),paid_jd:$('#sn-paid-jd').val(),paid_hh:$('#sn-paid-hh').val(),paid_mi:$('#sn-paid-mi').val()};
    var $btn=$(this).prop('disabled',true).text('در حال ثبت...');
    $.post(ajax,data,function(res){
      $btn.prop('disabled',false).text('ثبت اطلاعات واریز');
      if(res&&res.success){
        $('.sn-manual-result').remove();
        $('<div class="sn-notice sn-success sn-manual-result">✅ '+esc(res.message||'اطلاعات واریز ثبت شد')+'</div>').insertBefore('#sn-payment-section');
        refreshInvoiceBox(code);
      } else alert((res&&res.message)||'خطا در ثبت اطلاعات واریز');
    }).fail(function(xhr){ $btn.prop('disabled',false).text('ثبت اطلاعات واریز'); alert('خطای سرور: '+xhr.status); });
  });

  function translateAllLabels(){
    var map={'invoice':'پیش‌فاکتور','invoices':'پیش‌فاکتورها','assigned':'تخصیص داده‌شده','unassigned':'بدون تخصیص','pending':'در انتظار','approved':'تایید شده','rejected':'رد شده','paid':'پرداخت‌شده','online':'پرداخت آنلاین','gateway':'درگاه پرداخت','card':'کارت به کارت','receipt_uploaded':'نیاز به بررسی فیش','pending_financial_approval':'نیاز به بررسی فیش'};
    $('.sn-panel, .sn-invoice-page, .sn-admin').find('td,th,span,strong,option,button,label,small,h1,h2,h3,h4,p').contents().filter(function(){return this.nodeType===3;}).each(function(){
      var t=this.nodeValue; Object.keys(map).forEach(function(k){ t=t.replace(new RegExp('\\b'+k+'\\b','g'),map[k]); }); this.nodeValue=t;
    });
  }
  $(document).ajaxComplete(function(){ setTimeout(translateAllLabels,20); });
  $(translateAllLabels);
})(jQuery);
