/* Sales Network - Invoice Page JS (1.0.15 cleanup) */
(function($){
  'use strict';
  var $page = $('#sn-invoice-page');
  if (!$page.length) return;
  var cfg = window.snAjax || window.snData || {};
  var ajax = cfg.ajaxurl, nonce = cfg.nonce;
  var state = { invoice:null, settings:{}, card:{} };

  function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
  function strip(v){return $('<div>').html(String(v||'')).text();}
  function fa(v){return String(v==null?'':v).replace(/\d/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'[d];});}
  function en(v){return String(v==null?'':v).replace(/[۰-۹]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);}).replace(/[٠-٩]/g,function(d){return '٠١٢٣٤٥٦٧٨٩'.indexOf(d);});}
  function pad(v){return String(v).padStart(2,'0');}
  function money(v){try{return Number(v||0).toLocaleString('fa-IR')+' تومان';}catch(e){return String(v||0)+' تومان';}}
  function settingOn(k){ return String((state.settings||{})[k] == null ? '1' : (state.settings||{})[k]) === '1'; }
  function g2j(gy,gm,gd){var gdm=[0,31,59,90,120,151,181,212,243,273,304,334],gy2=(gm>2)?gy+1:gy,days=355666+365*gy+Math.floor((gy2+3)/4)-Math.floor((gy2+99)/100)+Math.floor((gy2+399)/400)+gd+gdm[gm-1],jy=-1595+33*Math.floor(days/12053);days%=12053;jy+=4*Math.floor(days/1461);days%=1461;if(days>365){jy+=Math.floor((days-1)/365);days=(days-1)%365;}var jm=(days<186)?1+Math.floor(days/31):7+Math.floor((days-186)/30),jd=1+((days<186)?days%31:(days-186)%30);return [jy,jm,jd];}

  function statusLabel(st){var m={pre_invoice:'پیش‌فاکتور',pending:'در انتظار پرداخت',pending_payment:'در انتظار پرداخت',receipt_uploaded:'نیاز به بررسی فیش',pending_financial_approval:'در انتظار تایید مالی',approved:'تایید شده',paid:'پرداخت‌شده',rejected:'رد شده',recontact_requested:'ارتباط مجدد با کارشناس',card_submitted:'فیش ثبت شده'};return m[st]||st||'—';}
  function productPriceHtml(it){
    if (Number(it.is_free)) return '<strong class="sn-free-label">رایگان</strong>';
    if (Number(it.has_sale) && Number(it.regular_price)>Number(it.sale_price) && Number(it.sale_price)>0) return '<del>'+money(it.regular_price)+'</del> <ins>'+money(it.sale_price)+'</ins>';
    return '<strong>'+money(it.unit_price || it.sale_price || it.total_price)+'</strong>';
  }
  function invoiceTotalHtml(inv){
    var original = Number(inv.original_total || 0), discount = Number(inv.discount_total || 0), finalTotal = Number(inv.final_total || inv.product_price || 0);
    var h = '<span class="sn-live-total">'+money(finalTotal)+'</span>';
    if (original > finalTotal) h = '<del class="sn-live-original">'+money(original)+'</del> ' + h;
    if (discount > 0) h += '<small class="sn-live-discount">تخفیف اعمال‌شده: '+money(discount)+'</small>';
    if (inv.coupon_code) h += '<small class="sn-live-coupon">کد تخفیف: '+esc(inv.coupon_code)+'</small>';
    if (inv.wheel_reward_summary) h += '<small class="sn-live-reward">'+esc(inv.wheel_reward_summary)+'</small>';
    return h;
  }

  function invoiceProductsInline(inv){
    var items = inv.items || [];
    if(!items.length) return esc(inv.product_name || '—');
    var h = '<div class="sn-products-inline-list">';
    items.forEach(function(it){
      var qty = Number(it.qty||1);
      h += '<div class="sn-product-inline-item">';
      h += '<div class="sn-product-inline-name">'+esc(it.product_name||'محصول')+(qty>1?' <small>× '+fa(qty)+'</small>':'')+'</div>';
      h += '<div class="sn-product-inline-price">'+productPriceHtml(it)+'</div>';
      h += '</div>';
    });
    return h + '</div>';
  }

  function renderMain(inv, card){
    $('#sn-inv-display-code').text(inv.code||'');
    $('#sn-inv-name').text(inv.customer_name||'');
    $('#sn-inv-phone').text(inv.customer_phone||'');
    $('#sn-inv-location').text([inv.province,inv.city].filter(Boolean).join(' — ') || '—');
    $('#sn-inv-product').html(invoiceProductsInline(inv));
    $('#sn-inv-status').text(inv.status_label || statusLabel(inv.status));
    $('#sn-inv-price').html(invoiceTotalHtml(inv));
    $('#sn-card-number').text((card&&card.number)||'—');
    $('#sn-card-owner').text((card&&card.owner)||'—');
    if (inv.status === 'paid' || inv.status === 'approved' || inv.is_paid) { $('#sn-payment-section').hide(); $('#sn-inv-paid-msg').show().removeClass('sn-error sn-info').addClass('sn-success').text('این فاکتور قبلاً پرداخت شده است.'); }
    else if (inv.status === 'receipt_uploaded' || inv.status === 'pending_financial_approval' || inv.status === 'card_submitted') { $('#sn-payment-section').hide(); $('#sn-inv-paid-msg').show().removeClass('sn-success sn-error').addClass('sn-info').text('پرداخت/فیش شما ثبت شده و در انتظار بررسی مالی است.'); }
    else if (inv.status === 'rejected') { $('#sn-payment-section').show(); $('#sn-inv-paid-msg').show().removeClass('sn-success sn-info').addClass('sn-error').text('پرداخت قبلی رد شده است. می‌توانید دوباره فیش یا اطلاعات واریز را ثبت کنید.'); }
    else { $('#sn-payment-section').show(); $('#sn-inv-paid-msg').hide(); }
    $('#sn-pay-online').toggle(settingOn('btn_show_online_payment'));
    $('#sn-pay-card').toggle(settingOn('btn_show_card_payment'));
    if(!settingOn('btn_show_online_payment') && !settingOn('btn_show_card_payment')){ $('#sn-payment-section').hide(); $('#sn-inv-paid-msg').show().removeClass('sn-success sn-error').addClass('sn-info').text('در حال حاضر روش پرداختی برای این پیش‌فاکتور فعال نیست.'); }
    $('#sn-invoice-lookup').hide(); $('#sn-invoice-detail').show();
    $page.data('active-code', inv.code).data('active-price', inv.final_total || inv.product_price || 0);
    renderOptions(inv);
  }
  function loadInvoice(code, cb){
    $.post(ajax,{action:'sn_invoice_info',nonce:nonce,invoice_code:code},function(res){
      if (!(res&&res.success)) { showInline('sn-error', (res&&res.message)||'فاکتور یافت نشد'); return; }
      state.invoice = res.invoice; state.settings = res.settings || {}; state.card = res.card || {};
      renderMain(state.invoice, state.card);
      logOnce('invoice_viewed','مشاهده لینک فاکتور');
      if (typeof cb === 'function') cb(state.invoice);
    }).fail(function(){showInline('sn-error','خطای ارتباط با سرور');});
  }
  window.snRefreshInvoiceLive = function(cb){ var code=$page.data('active-code') || $('#sn-inv-code').val(); if(code) loadInvoice(code,cb); };
  function showInline(type,msg){$('.sn-runtime-notice').remove(); $('<div class="sn-notice '+type+' sn-runtime-notice">'+esc(msg)+'</div>').insertBefore('#sn-invoice-detail').delay(4500).fadeOut(250,function(){$(this).remove();});}
  var snActionQueue=[], snActionTimer=null;
  function flushActionQueue(){
    if(!snActionQueue.length || !ajax) return;
    var code=$page.data('active-code') || $('#sn-inv-code').val(); if(!code) return;
    var batch=snActionQueue.splice(0,10);
    $.post(ajax,{action:'sn_invoice_customer_actions_batch',nonce:nonce,invoice_code:code,events:JSON.stringify(batch)}).always(function(){ if(snActionQueue.length) snActionTimer=setTimeout(flushActionQueue,1200); });
  }
  function logAction(event,label,extra){
    var code=$page.data('active-code') || $('#sn-inv-code').val(); if(!code || !ajax) return;
    snActionQueue.push({event:event,label:label||'',extra:extra||{}});
    if(snActionQueue.length>30) snActionQueue=snActionQueue.slice(-30);
    if(snActionTimer) clearTimeout(snActionTimer);
    snActionTimer=setTimeout(flushActionQueue,900);
  }
  function logOnce(event,label){
    var code=$page.data('active-code') || $('#sn-inv-code').val(); if(!code) return;
    var key='sn_'+event+'_'+code;
    try{ if(sessionStorage.getItem(key)) return; sessionStorage.setItem(key,'1'); }catch(e){}
    logAction(event,label||'');
  }
  $(window).on('beforeunload', function(){ try{ flushActionQueue(); }catch(e){} });
  function modal(title, body){$('.sn-invoice-option-modal').remove();$('body').append('<div class="sn-invoice-option-modal sn-clean-modal" dir="rtl"><div class="sn-modal-backdrop"></div><div class="sn-modal-box"><button type="button" class="sn-modal-close">×</button><h3>'+esc(title)+'</h3><div class="sn-modal-content">'+body+'</div></div></div>');}
  function productInfo(inv){
    var showDesc = state.settings.info_show_short_desc !== '0', showPrice = state.settings.info_show_price !== '0', showLottery = state.settings.info_show_lottery !== '0', showCoupon = state.settings.info_show_coupon !== '0', showImage = state.settings.info_show_image !== '0', showGallery = state.settings.info_show_gallery !== '0';
    var h='<div class="sn-option-products">';
    (inv.items||[]).forEach(function(it){
      h+='<div class="sn-option-product">';
      if (showImage && it.image_url) h+='<img class="sn-option-product-image" src="'+esc(it.image_url)+'" alt="'+esc(it.product_name||'محصول')+'">';
      h+='<h4>'+esc(it.product_name)+'</h4>';
      if (showGallery && Array.isArray(it.gallery_urls) && it.gallery_urls.length) { h+='<div class="sn-option-gallery">'; it.gallery_urls.slice(0,6).forEach(function(src){h+='<img src="'+esc(src)+'" alt="گالری محصول">';}); h+='</div>'; }
      if (showDesc) h+='<p>'+esc(strip(it.short_description)||'توضیح کوتاه محصول ثبت نشده است.')+'</p>';
      if (showPrice) h+='<div class="sn-option-price">'+productPriceHtml(it)+'</div>';
      if (showLottery && Number(it.lottery_chance_count||0)>0) h+='<small class="sn-info-chip">'+fa(Number(it.lottery_chance_count||0)*Number(it.qty||1))+' شانس قرعه‌کشی</small>';
      if (showCoupon && it.has_discount_coupon) h+='<small class="sn-info-chip">امکان استفاده از کد تخفیف</small>';
      h+='</div>';
    });
    return h+'</div>';
  }
  function totalLottery(inv){var n=0;(inv.items||[]).forEach(function(i){n+=Number(i.lottery_chance_count||0)*Number(i.qty||1);});return n;}
  function lotteryInfo(inv){var c=totalLottery(inv), company=(state.settings.wheel_company_name||'کمپین'), tmpl=state.settings.lottery_text_template||'با پرداخت این فاکتور {count} شانس برای شرکت در قرعه‌کشی {company} دریافت می‌کنید.';return '<p class="sn-option-lead">'+esc(tmpl.replace('{count}',fa(c)).replace('{company}',company))+'</p><div class="sn-lottery-count">'+fa(c)+' شانس</div>';}
  function allSegments(inv){var out=[];(inv.items||[]).forEach(function(i){ if(i.has_lucky_wheel && Array.isArray(i.wheel_segments)) i.wheel_segments.forEach(function(s){ if((s.label||'').trim()) out.push(s); });}); return out;}
  function hasWheel(inv){return (inv.items||[]).some(function(i){return !!i.has_lucky_wheel;});}
  function wheelSegmentPath(startDeg, endDeg){
    var r=98, toRad=Math.PI/180, a1=(startDeg-90)*toRad, a2=(endDeg-90)*toRad;
    var x1=Math.cos(a1)*r, y1=Math.sin(a1)*r, x2=Math.cos(a2)*r, y2=Math.sin(a2)*r;
    var large=(endDeg-startDeg)>180?1:0;
    return 'M 0 0 L '+x1.toFixed(3)+' '+y1.toFixed(3)+' A '+r+' '+r+' 0 '+large+' 1 '+x2.toFixed(3)+' '+y2.toFixed(3)+' Z';
  }
  function shortenLabel(label){
    label = strip(label || 'جایزه');
    return label.length > 18 ? label.slice(0,17)+'…' : label;
  }
  function wheelDisc(segs){
    if(!segs.length) segs=[{label:'بدون جایزه',type:'empty_reward'}];
    var n=segs.length, step=360/n, colors=['#8b5cf6','#06b6d4','#22c55e','#f59e0b','#ef4444','#3b82f6','#ec4899','#14b8a6'];
    var svg='<svg class="sn-wheel-svg" viewBox="-105 -105 210 210" aria-label="گردونه شانس" role="img">';
    for(var i=0;i<n;i++){
      var a0=i*step, a1=(i+1)*step, mid=a0+step/2, label=shortenLabel(segs[i].label||('گزینه '+(i+1)));
      svg+='<path class="sn-wheel-slice" d="'+wheelSegmentPath(a0,a1)+'" fill="'+colors[i%colors.length]+'"></path>';
      svg+='<g class="sn-wheel-text" transform="rotate('+mid+') translate(0 -62) rotate('+(-mid)+')"><text text-anchor="middle" dominant-baseline="middle">'+esc(label)+'</text></g>';
    }
    svg+='<circle class="sn-wheel-hub" cx="0" cy="0" r="17"></circle></svg>';
    return '<div class="sn-wheel-shell"><div class="sn-wheel-real-wrap"><div class="sn-wheel-pointer">▼</div><div class="sn-wheel-disc sn-wheel-real" data-parts="'+n+'">'+svg+'</div></div></div>';
  }
  function wheelResultCard(title, desc, icon, tone){
    return '<div class="sn-wheel-result-card sn-wheel-result-'+esc(tone||'success')+'"><div class="sn-wheel-result-icon">'+esc(icon||'🎁')+'</div><div><strong>'+esc(title||'نتیجه گردونه')+'</strong><p>'+esc(desc||'')+'</p></div></div>';
  }
  function wheelInfo(inv){
    var item=(inv.items||[]).filter(function(i){return i.has_lucky_wheel;})[0]||{}, segs=allSegments(inv);
    var used=!!(inv.is_paid||inv.wheel_used), result=inv.wheel_reward_summary?wheelResultCard('جایزه شما', inv.wheel_reward_summary, '🎁', 'success'):'';
    return '<p class="sn-option-lead sn-wheel-lead">'+esc(strip(item.wheel_description)||'گردونه را بچرخانید و جایزه خود را ببینید.')+'</p>'+wheelDisc(segs)+'<div class="sn-wheel-action-row"><button type="button" id="sn-modal-spin-wheel" class="sn-btn sn-btn-primary sn-wheel-start-btn" '+(used?'disabled':'')+'>'+(used?'گردونه قبلاً چرخانده شده':'شروع چرخش')+'</button></div><div id="sn-modal-wheel-result" class="sn-modal-result">'+result+'</div>';
  }
  function couponInfo(){var inv=state.invoice||{}, applied=inv.coupon_code?'<div class="sn-applied-coupon"><span>کد فعال: <strong>'+esc(inv.coupon_code)+'</strong></span><button type="button" class="sn-btn sn-btn-secondary" id="sn-remove-manual-coupon">لغو کد تخفیف</button></div>':'';return '<p class="sn-option-lead">کد تخفیف</p>'+applied+'<div class="sn-coupon-box"><input type="text" id="sn-manual-coupon-code" placeholder="کد تخفیف"><button type="button" class="sn-btn sn-btn-primary" id="sn-apply-manual-coupon">اعمال کد تخفیف</button></div><div id="sn-coupon-result" class="sn-modal-result"></div>';}
  function recontactInfo(){return '<p class="sn-option-lead">'+esc(state.settings.recontact_popup_text||'اگر پیش از پرداخت فاکتور از کارشناس خود سوالی دارید، دکمه ارتباط مجدد با کارشناس را بزنید.')+'</p><button type="button" id="sn-modal-recontact-send" class="sn-btn sn-btn-secondary">ارتباط مجدد با کارشناس</button><div id="sn-modal-recontact-result" class="sn-modal-result"></div>';}
  function renderOptions(inv){
    $('.sn-invoice-option-row').remove();
    var buttons=[];
    if(settingOn('btn_show_product_info')) buttons.push('<button type="button" class="sn-invoice-option" data-option="product">اطلاعات محصول</button>');
    if(settingOn('btn_show_lottery') && totalLottery(inv)>0) buttons.push('<button type="button" class="sn-invoice-option" data-option="lottery">شانس قرعه‌کشی</button>');
    if(settingOn('btn_show_wheel') && hasWheel(inv)) buttons.push('<button type="button" class="sn-invoice-option" data-option="wheel">گردونه شانس</button>');
    if(settingOn('btn_show_coupon') && (inv.items||[]).some(function(i){return i.has_discount_coupon;})) buttons.push('<button type="button" class="sn-invoice-option" data-option="discount">کد تخفیف</button>');
    if(settingOn('btn_show_recontact')) buttons.push('<button type="button" class="sn-invoice-option" data-option="recontact">ارتباط مجدد</button>');
    if(!buttons.length) return;
    $('.sn-invoice-total').last().after('<div class="sn-invoice-option-row">'+buttons.join('')+'</div>');
  }
  function resultIndexFromResponse(res, segs){
    var seg = (res.payload && res.payload.segment) ? res.payload.segment : null;
    var label = String((seg && seg.label) || res.reward_label || res.reward_value || res.summary || '').trim();
    for (var i=0;i<segs.length;i++){ var candidate=String(segs[i].label||segs[i].reward_value||'').trim(); if(candidate && (candidate===label || label.indexOf(candidate)!==-1)) return i; }
    return Math.max(0, Math.min((segs.length||1)-1, Number(res.reward_index||0)||0));
  }
  function spinToIndex($disc, index, total){
    total = Math.max(1,total); var step=360/total; var center=(index*step)+(step/2); state.spinBase = (state.spinBase||0) + 1440; var finalDeg = state.spinBase + (360-center); $disc.css({transition:'none'}); $disc[0] && $disc[0].offsetHeight; $disc.css({transition:'transform 4.15s cubic-bezier(.08,.72,.12,1)',transform:'rotate('+finalDeg+'deg)'});
  }
  function ensurePaymentDateTime(){
    var $host=$('#sn-card-paid-at-picker'); if(!$host.length){ $('#sn-card-paid-at').after('<div id="sn-card-paid-at-picker" class="sn-inline-paid-datetime"></div>'); $host=$('#sn-card-paid-at-picker'); }
    var d=new Date(), j=g2j(d.getFullYear(),d.getMonth()+1,d.getDate()), ys='',ms='',ds='',hs='',mis='';
    for(var y=j[0]-1;y<=j[0]+1;y++) ys+='<option value="'+y+'" '+(y===j[0]?'selected':'')+'>'+fa(y)+'</option>';
    for(var m=1;m<=12;m++) ms+='<option value="'+m+'" '+(m===j[1]?'selected':'')+'>'+fa(m)+'</option>';
    for(var dd=1;dd<=31;dd++) ds+='<option value="'+dd+'" '+(dd===j[2]?'selected':'')+'>'+fa(dd)+'</option>';
    for(var h=0;h<24;h++) hs+='<option value="'+h+'" '+(h===d.getHours()?'selected':'')+'>'+fa(pad(h))+'</option>';
    for(var mi=0;mi<60;mi+=5) mis+='<option value="'+mi+'" '+(mi===Math.floor(d.getMinutes()/5)*5?'selected':'')+'>'+fa(pad(mi))+'</option>';
    $host.html('<div class="sn-inline-date-box"><div class="sn-inline-date-title">تاریخ و ساعت واریز</div><div class="sn-inline-date-row"><label>سال<select id="sn-paid-jy">'+ys+'</select></label><label>ماه<select id="sn-paid-jm">'+ms+'</select></label><label>روز<select id="sn-paid-jd">'+ds+'</select></label><label>ساعت<select id="sn-paid-hh">'+hs+'</select></label><label>دقیقه<select id="sn-paid-mi">'+mis+'</select></label></div><div class="sn-inline-date-selected">انتخاب شده: <strong id="sn-paid-at-view"></strong></div></div>');
    function sync(){var val=$('#sn-paid-jy').val()+'/'+pad($('#sn-paid-jm').val())+'/'+pad($('#sn-paid-jd').val())+' '+pad($('#sn-paid-hh').val())+':'+pad($('#sn-paid-mi').val()); $('#sn-card-paid-at').val(val); $('#sn-paid-at-view').text(fa(val));}
    $(document).off('change.snCleanDt','#sn-paid-jy,#sn-paid-jm,#sn-paid-jd,#sn-paid-hh,#sn-paid-mi').on('change.snCleanDt','#sn-paid-jy,#sn-paid-jm,#sn-paid-jd,#sn-paid-hh,#sn-paid-mi',sync); sync();
  }

  $(document).off('click','#sn-load-invoice').on('click','#sn-load-invoice',function(e){e.preventDefault();var code=$.trim($('#sn-inv-code').val()); if(code) loadInvoice(code);});
  $(document).off('click','#sn-pay-online').on('click','#sn-pay-online',function(e){e.preventDefault();logAction('pay_online_clicked','کلیک پرداخت آنلاین');var code=$page.data('active-code'); var $btn=$(this).prop('disabled',true).text('در حال اتصال به درگاه...'); $.post(ajax,{action:'sn_pay_online',nonce:nonce,invoice_code:code},function(res){if(res&&res.success&&res.redirect) window.location.href=res.redirect; else showInline('sn-error',(res&&res.message)||'خطا در اتصال به درگاه');}).always(function(){$btn.prop('disabled',false).text('💳 پرداخت آنلاین (درگاه)');});});
  $(document).off('click','#sn-pay-card').on('click','#sn-pay-card',function(e){e.preventDefault();logAction('card_payment_selected','انتخاب پرداخت کارت‌به‌کارت');$('#sn-card-info').slideDown(); if(!$('.sn-card-choice').length){$('<div class="sn-card-choice"><button type="button" class="sn-btn sn-btn-secondary" id="sn-choice-upload">بارگذاری فیش</button><button type="button" class="sn-btn sn-btn-secondary" id="sn-choice-manual">وارد کردن اطلاعات پرداختی</button></div>').insertBefore('#sn-receipt-file');} $('#sn-card-manual-toggle').hide(); $('#sn-receipt-file,#sn-upload-receipt,#sn-card-manual-fields').hide();});
  $(document).off('click','#sn-choice-upload').on('click','#sn-choice-upload',function(e){e.preventDefault();logAction('receipt_upload_selected','انتخاب بارگذاری فیش');$('.sn-card-choice .sn-btn').removeClass('active');$(this).addClass('active');$('#sn-card-manual-fields').hide();$('#sn-receipt-file,#sn-upload-receipt').show();});
  $(document).off('click','#sn-choice-manual,#sn-card-manual-toggle').on('click','#sn-choice-manual,#sn-card-manual-toggle',function(e){e.preventDefault();logAction('manual_payment_selected','انتخاب ورود اطلاعات واریزی');$('.sn-card-choice .sn-btn').removeClass('active');$('#sn-choice-manual').addClass('active');$('#sn-receipt-file,#sn-upload-receipt').hide();$('#sn-card-manual-fields').show();ensurePaymentDateTime();});
  $(document).off('click','#sn-upload-receipt').on('click','#sn-upload-receipt',function(e){e.preventDefault();var code=$page.data('active-code'), file=$('#sn-receipt-file')[0] && $('#sn-receipt-file')[0].files[0]; if(!file){showInline('sn-error','لطفاً فایل فیش را انتخاب کنید');return;} var fd=new FormData(); fd.append('action','sn_upload_receipt'); fd.append('nonce',nonce); fd.append('invoice_code',code); fd.append('receipt',file); var $btn=$(this).prop('disabled',true).text('در حال ارسال...'); $.ajax({url:ajax,type:'POST',data:fd,processData:false,contentType:false,success:function(res){if(res&&res.success){showInline('sn-success',res.message||'فیش ثبت شد'); logAction('receipt_uploaded','ارسال فیش پرداخت'); loadInvoice(code);} else showInline('sn-error',(res&&res.message)||'خطا در آپلود');},complete:function(){$btn.prop('disabled',false).text('ارسال فیش');}});});
  $(document).off('click','#sn-submit-manual-payment').on('click','#sn-submit-manual-payment',function(e){e.preventDefault();var code=$page.data('active-code'), from=en($('#sn-card-from4').val()), to=en($('#sn-card-to4').val()), amount=en($('#sn-card-amount').val()), paidAt=en($('#sn-card-paid-at').val()); if(!/^\d{4}$/.test(from)||!/^\d{4}$/.test(to)){showInline('sn-error','۴ رقم کارت باید عددی باشد');return;} amount=String(amount).replace(/,/g,''); if(!amount || isNaN(amount) || Number(amount)<=0){showInline('sn-error','مبلغ باید عددی و بزرگ‌تر از صفر باشد');return;} if(!paidAt){showInline('sn-error','تاریخ و ساعت واریز را انتخاب کنید');return;} var m=paidAt.match(/^(\d{4})\/(\d{2})\/(\d{2}) (\d{2}):(\d{2})$/); if(!m){showInline('sn-error','فرمت تاریخ و ساعت واریز معتبر نیست');return;} var jm=Number(m[2]), jd=Number(m[3]), hh=Number(m[4]), mi=Number(m[5]), maxd=(jm<=6?31:(jm<=11?30:29)); if(jm<1||jm>12||jd<1||jd>maxd||hh<0||hh>23||mi<0||mi>59){showInline('sn-error','تاریخ یا ساعت واریز معتبر نیست');return;} var $btn=$(this).prop('disabled',true).text('در حال ثبت...'); $.post(ajax,{action:'sn_submit_manual_payment',nonce:nonce,invoice_code:code,card_from:from,card_to:to,amount:amount,paid_at:paidAt},function(res){if(res&&res.success){showInline('sn-success',res.message||'اطلاعات واریز ثبت شد'); logAction('manual_payment_submitted','ثبت اطلاعات واریزی'); loadInvoice(code);} else showInline('sn-error',(res&&res.message)||'خطا در ثبت');}).always(function(){$btn.prop('disabled',false).text('ثبت اطلاعات واریز');});});
  $(document).off('click','.sn-modal-backdrop,.sn-modal-close').on('click','.sn-modal-backdrop,.sn-modal-close',function(){$('.sn-invoice-option-modal').remove();});
  $(document).off('click','.sn-invoice-option').on('click','.sn-invoice-option',function(e){e.preventDefault();var inv=state.invoice,opt=$(this).data('option'); if(!inv)return; if(opt==='product'){logAction('product_info_viewed','مطالعه اطلاعات محصول');modal('اطلاعات محصول',productInfo(inv));} if(opt==='lottery'){logAction('lottery_info_viewed','مشاهده شانس قرعه‌کشی');modal('شانس قرعه‌کشی',lotteryInfo(inv));} if(opt==='wheel'){logAction('wheel_opened','باز کردن گردونه شانس');modal('گردونه شانس',wheelInfo(inv));} if(opt==='discount'){logAction('coupon_opened','باز کردن کد تخفیف');modal('کد تخفیف',couponInfo(inv));} if(opt==='recontact'){logAction('recontact_popup_viewed','مشاهده توضیح ارتباط مجدد');modal('ارتباط مجدد با کارشناس',recontactInfo());}});
  $(document).off('click','#sn-modal-spin-wheel').on('click','#sn-modal-spin-wheel',function(e){
    e.preventDefault(); logAction('wheel_spin_clicked','کلیک روی شروع گردونه'); var code=$page.data('active-code')||$('#sn-inv-code').val(), $btn=$(this).prop('disabled',true).text('در حال چرخش...'), segs=allSegments(state.invoice), $disc=$('.sn-wheel-disc');
    $.post(ajax,{action:'sn_spin_invoice_wheel',nonce:nonce,invoice_code:code,apply:'spin_only'},function(res){
      if(!(res&&res.success)){ $('#sn-modal-wheel-result').html('<div class="sn-notice sn-error">'+esc((res&&res.message)||'خطا')+'</div>'); $btn.prop('disabled',false).text('چرخاندن گردونه'); return; }
      var idx=resultIndexFromResponse(res,segs); spinToIndex($disc,idx,Math.max(1,segs.length));
      setTimeout(function(){
        var type=res.reward_type||'', rawMsg=res.summary||res.message||'', msg=esc(rawMsg); var action='', card='';
        if(type==='discount_coupon'){
          card=wheelResultCard('تبریک! کد تخفیف برنده شدید', rawMsg || 'یک کد تخفیف برای این فاکتور دریافت کردید.', '🏷️', 'success');
          action='<div class="sn-wheel-apply-box"><p>می‌خواهید این جایزه روی همین فاکتور اعمال شود؟</p><div class="sn-wheel-apply-actions"><button type="button" class="sn-btn sn-btn-primary" id="sn-apply-wheel-reward">بله، اعمال شود</button><button type="button" class="sn-btn sn-btn-secondary" id="sn-decline-wheel-reward">خیر، استفاده نمی‌کنم</button></div></div>';
        } else if(type==='free_product'){
          card=wheelResultCard('تبریک! محصول رایگان برنده شدید', rawMsg || 'یک محصول رایگان به فاکتور شما اضافه می‌شود.', '🎁', 'success');
          action='<div class="sn-wheel-apply-box"><p>می‌خواهید این جایزه به همین فاکتور اضافه شود؟</p><div class="sn-wheel-apply-actions"><button type="button" class="sn-btn sn-btn-primary" id="sn-apply-wheel-reward">بله، اضافه شود</button></div></div>';
        } else {
          card=wheelResultCard('نتیجه گردونه', rawMsg || 'این بار جایزه‌ای دریافت نشد.', '🙂', 'empty');
          action='<div class="sn-wheel-apply-box sn-wheel-empty-note">این نتیجه ثبت شد.</div>';
        }
        $('#sn-modal-wheel-result').html(card+action);
        $btn.text('گردونه چرخانده شد');
      },3900);
    });
  });
  $(document).off('click','#sn-apply-wheel-reward').on('click','#sn-apply-wheel-reward',function(e){e.preventDefault();logAction('wheel_reward_apply_clicked','درخواست اعمال جایزه گردونه');var code=$page.data('active-code')||$('#sn-inv-code').val(), $btn=$(this).prop('disabled',true).text('در حال اعمال...'); $.post(ajax,{action:'sn_apply_invoice_wheel_reward',nonce:nonce,invoice_code:code},function(res){$('#sn-modal-wheel-result').append('<div class="sn-notice '+(res&&res.success?'sn-success':'sn-error')+'">'+esc((res&&res.message)||'خطا')+'</div>'); if(res&&res.success) window.snRefreshInvoiceLive();}).always(function(){$btn.text('اعمال شد');});});
  $(document).off('click','#sn-decline-wheel-reward').on('click','#sn-decline-wheel-reward',function(e){e.preventDefault();logAction('wheel_reward_declined','عدم استفاده از جایزه گردونه');$('#sn-modal-wheel-result').append(wheelResultCard('جایزه استفاده نشد', 'این جایزه مصرف شد و دیگر قابل استفاده نیست.', '⚠️', 'empty')); window.snRefreshInvoiceLive();});
  $(document).off('click','#sn-apply-manual-coupon').on('click','#sn-apply-manual-coupon',function(e){e.preventDefault();logAction('coupon_apply_clicked','درخواست اعمال کد تخفیف');var code=$page.data('active-code')||$('#sn-inv-code').val(), coupon=$.trim($('#sn-manual-coupon-code').val()); var $btn=$(this).prop('disabled',true).text('در حال بررسی...'); $.post(ajax,{action:'sn_apply_invoice_coupon',nonce:nonce,invoice_code:code,coupon_code:coupon},function(res){$('#sn-coupon-result').html('<div class="sn-notice '+(res&&res.success?'sn-success':'sn-error')+'">'+esc((res&&res.message)||'خطا')+'</div>'); if(res&&res.success) window.snRefreshInvoiceLive();}).always(function(){$btn.prop('disabled',false).text('اعمال کد تخفیف');});});
  $(document).off('click','#sn-remove-manual-coupon').on('click','#sn-remove-manual-coupon',function(e){e.preventDefault();logAction('coupon_remove_clicked','لغو کد تخفیف');var code=$page.data('active-code')||$('#sn-inv-code').val(), $btn=$(this).prop('disabled',true).text('در حال لغو...'); $.post(ajax,{action:'sn_remove_invoice_coupon',nonce:nonce,invoice_code:code},function(res){$('#sn-coupon-result').html('<div class="sn-notice '+(res&&res.success?'sn-success':'sn-error')+'">'+esc((res&&res.message)||'خطا')+'</div>'); if(res&&res.success) window.snRefreshInvoiceLive(function(){modal('کد تخفیف',couponInfo());});}).always(function(){$btn.prop('disabled',false).text('لغو کد تخفیف');});});
  $(document).off('click','#sn-modal-recontact-send').on('click','#sn-modal-recontact-send',function(e){e.preventDefault();logAction('recontact_requested','درخواست ارتباط مجدد با کارشناس');var code=$page.data('active-code')||$('#sn-inv-code').val(); $.post(ajax,{action:'sn_invoice_recontact',nonce:nonce,invoice_code:code,note:''},function(res){$('#sn-modal-recontact-result').html('<div class="sn-notice '+(res&&res.success?'sn-success':'sn-error')+'">'+esc((res&&res.message)||'خطا')+'</div>'); if(res&&res.success) window.snRefreshInvoiceLive();});});

  var initCode=$page.data('code'), initResult=$page.data('result');
  if(initCode && initResult!=='success') { $('#sn-inv-code').val(initCode); loadInvoice(initCode); }
})(jQuery);
