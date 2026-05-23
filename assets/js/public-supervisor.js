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

  function snEsc(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\"/g, '&quot;')
      .replace(/'/g, '&#039;');
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

/* SN 1.0.8 supervisor invoice tabs */
(function($){
  'use strict';
  if(!$('#sn-supervisor-panel').length) return;
  var ajax=(window.snAjax||window.snData||{}).ajaxurl, nonce=(window.snAjax||window.snData||{}).nonce;
  function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
  function loadSupInvoices(tab){
    tab=tab||$('.sn-supervisor-invoice-tabs .sn-subtab.active').data('status')||'pre_invoice';
    $('#sn-supervisor-invoices-list').html('<div class="sn-loading">در حال بارگذاری...</div>');
    $.post(ajax,{action:'sn_supervisor_invoices',nonce:nonce,tab:tab},function(res){
      var rows=(res&&res.items)||[];
      if(!res || !res.success || !rows.length){ $('#sn-supervisor-invoices-list').html('<p class="sn-notice">فاکتوری در این تب وجود ندارد.</p>'); return; }
      var html='<div class="sn-table-wrap"><table class="sn-table"><thead><tr><th>کد</th><th>فروشنده</th><th>مشتری</th><th>مبلغ</th><th>وضعیت</th><th>آپلود فیش توسط سرپرست</th></tr></thead><tbody>';
      rows.forEach(function(i){ html+='<tr><td><code>'+esc(i.invoice_code)+'</code></td><td>'+esc(i.seller_name||'')+'</td><td>'+esc(i.customer_name)+'<br><small>'+esc(i.customer_phone)+'</small></td><td>'+esc(i.product_price)+'</td><td>'+esc(i.status_label||i.status)+'</td><td><input type="file" class="sn-sup-receipt-file" data-id="'+esc(i.id)+'" accept="image/*,.pdf"> <button type="button" class="sn-btn sn-btn-sm sn-sup-upload-receipt" data-id="'+esc(i.id)+'">ارسال به مالی</button></td></tr>'; });
      html+='</tbody></table></div>'; $('#sn-supervisor-invoices-list').html(html);
    });
  }
  $(document).on('click','.sn-supervisor-invoice-tabs .sn-subtab',function(){ $('.sn-supervisor-invoice-tabs .sn-subtab').removeClass('active'); $(this).addClass('active'); loadSupInvoices($(this).data('status')); });
  $(document).on('click','#sn-supervisor-panel .sn-tab[data-tab="invoices"]',function(){ setTimeout(function(){loadSupInvoices();},20); });
  $(document).on('click','.sn-sup-upload-receipt',function(){ var id=$(this).data('id'), file=$('.sn-sup-receipt-file[data-id="'+id+'"]').prop('files')[0]; if(!file){alert('فایل فیش را انتخاب کنید'); return;} var fd=new FormData(); fd.append('action','sn_supervisor_upload_receipt'); fd.append('nonce',nonce); fd.append('invoice_id',id); fd.append('receipt',file); $.ajax({url:ajax,method:'POST',data:fd,processData:false,contentType:false,success:function(res){alert((res&&res.message)||'انجام شد'); loadSupInvoices();}}); });
})(jQuery);


/* SN 1.0.10 final supervisor invoice tab verifier */
(function($){
  'use strict';
  if(!$('#sn-supervisor-panel').length) return;
  var ajax=(window.snAjax||window.snData||{}).ajaxurl, nonce=(window.snAjax||window.snData||{}).nonce;
  function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
  function money(v){try{return Number(v||0).toLocaleString('fa-IR')+' تومان';}catch(e){return (v||0)+' تومان';}}
  function loadSupInvoicesFinal(tab){
    tab=tab||$('.sn-supervisor-invoice-tabs .sn-subtab.active').data('status')||'pre_invoice';
    $('#sn-supervisor-invoices-list').html('<div class="sn-loading">در حال بارگذاری...</div>');
    $.post(ajax,{action:'sn_supervisor_invoices',nonce:nonce,tab:tab,limit:30},function(res){
      var rows=(res&&res.items)||[];
      if(!res || !res.success || !rows.length){ $('#sn-supervisor-invoices-list').html('<p class="sn-notice">فاکتوری در این تب وجود ندارد.</p>'); return; }
      var html='<div class="sn-table-wrap"><table class="sn-table"><thead><tr><th>کد</th><th>فروشنده</th><th>مشتری</th><th>مبلغ</th><th>نوع/منبع پرداخت</th><th>وضعیت</th><th>آپلود فیش توسط سرپرست</th></tr></thead><tbody>';
      rows.forEach(function(i){
        html+='<tr><td><code>'+esc(i.invoice_code)+'</code></td><td>'+esc(i.seller_name||'')+'</td><td>'+esc(i.customer_name)+'<br><small>'+esc(i.customer_phone)+'</small></td><td>'+esc(i.amount_fmt||money(i.product_price))+'</td><td>'+esc(i.pay_method_label||'—')+'<br><small>'+esc(i.payment_source_label||'—')+'</small></td><td>'+esc(i.status_label||i.status)+'</td><td><input type="file" class="sn-sup-receipt-file" data-id="'+esc(i.id)+'" accept="image/*,.pdf"> <button type="button" class="sn-btn sn-btn-sm sn-sup-upload-receipt" data-id="'+esc(i.id)+'">ارسال به مالی</button></td></tr>';
      });
      html+='</tbody></table></div>'; $('#sn-supervisor-invoices-list').html(html);
    });
  }
  window.snLoadSupervisorInvoicesFinal = loadSupInvoicesFinal;
  $(document).off('click.snSupFinal','.sn-supervisor-invoice-tabs .sn-subtab').on('click.snSupFinal','.sn-supervisor-invoice-tabs .sn-subtab',function(){ $('.sn-supervisor-invoice-tabs .sn-subtab').removeClass('active'); $(this).addClass('active'); loadSupInvoicesFinal($(this).data('status')); });
  $(document).off('click.snSupTabFinal','#sn-supervisor-panel .sn-tab[data-tab="invoices"]').on('click.snSupTabFinal','#sn-supervisor-panel .sn-tab[data-tab="invoices"]',function(){ setTimeout(function(){loadSupInvoicesFinal();},20); });
  $(document).off('click.snSupUploadFinal','.sn-sup-upload-receipt').on('click.snSupUploadFinal','.sn-sup-upload-receipt',function(){ var id=$(this).data('id'), file=$('.sn-sup-receipt-file[data-id="'+id+'"]').prop('files')[0]; if(!file){alert('فایل فیش را انتخاب کنید'); return;} var fd=new FormData(); fd.append('action','sn_supervisor_upload_receipt'); fd.append('nonce',nonce); fd.append('invoice_id',id); fd.append('receipt',file); $.ajax({url:ajax,method:'POST',data:fd,processData:false,contentType:false,success:function(res){alert((res&&res.message)||'انجام شد'); loadSupInvoicesFinal();}}); });
})(jQuery);
