jQuery(function($){
  function setTrack($wrap,$btn, autoplay){
    var audio = $wrap.find('#aipex-floating-audio')[0];
    if(!audio || !$btn.length) return;
    $wrap.find('.aipex-floating-track').removeClass('is-active');
    $btn.addClass('is-active');
    $wrap.attr('data-current',$btn.data('index'));
    $wrap.find('.aipex-floating-now strong').text($btn.data('title'));
    $(audio).attr('src',$btn.data('audio'));
    if(autoplay) audio.play();
  }

  $(document).on('click','.aipex-floating-track',function(){
    var $wrap = $(this).closest('.aipex-floating-player');
    setTrack($wrap, $(this), true);
    $wrap.find('.aipex-episode-drawer').attr('hidden', true);
    $wrap.removeClass('drawer-open');
  });

  $(document).on('click','.aipex-float-next,.aipex-float-prev',function(){
    var $wrap=$(this).closest('.aipex-floating-player'), $tracks=$wrap.find('.aipex-floating-track:visible'), cur=parseInt($wrap.attr('data-current')||0,10);
    var $all=$wrap.find('.aipex-floating-track');
    var currentVisibleIndex = $tracks.index($all.eq(cur));
    if(currentVisibleIndex < 0) currentVisibleIndex = 0;
    currentVisibleIndex += $(this).hasClass('aipex-float-next') ? 1 : -1;
    if(currentVisibleIndex<0) currentVisibleIndex=$tracks.length-1; if(currentVisibleIndex>=$tracks.length) currentVisibleIndex=0;
    setTrack($wrap, $tracks.eq(currentVisibleIndex), true);
  });

  $(document).on('click','.aipex-episode-drawer-toggle',function(){
    var $wrap=$(this).closest('.aipex-floating-player'), $drawer=$wrap.find('.aipex-episode-drawer');
    var open = $drawer.is('[hidden]');
    $drawer.attr('hidden', !open);
    $wrap.toggleClass('drawer-open', open);
    if(open) $drawer.find('.aipex-episode-search').trigger('focus');
  });

  $(document).on('click','.aipex-player-minimise',function(){
    $(this).closest('.aipex-floating-player').toggleClass('is-minimised').find('.aipex-episode-drawer').attr('hidden', true);
  });

  $(document).on('input','.aipex-episode-search',function(){
    var term = ($(this).val()||'').toLowerCase(), $wrap=$(this).closest('.aipex-floating-player');
    $wrap.find('.aipex-floating-track').each(function(){
      var title = ($(this).data('title')||$(this).text()||'').toLowerCase();
      $(this).toggle(title.indexOf(term)!==-1);
    });
  });

  $(document).on('click','.aipex-load-more',function(){
    var $btn=$(this), ctx=$btn.data('context')||{}, kind=$btn.data('kind')||ctx.kind||'episodes', page=parseInt($btn.attr('data-page')||1,10), $grid=$btn.closest('.aipex-load-wrap').prev('.aipex-card-grid');
    var label = $btn.data('label') || $.trim($btn.text()) || 'Load More';
    var action = ctx.relationship ? 'aipex_relationship_grid_load_more' : 'aipex_grid_load_more';
    $btn.data('label', label).prop('disabled',true).text('Loading...');
    $.post(AipexPodcast.ajaxurl,{action:action,nonce:AipexPodcast.nonce,page:page,kind:kind,context:JSON.stringify(ctx)},function(resp){
      if(resp && resp.success && resp.data && resp.data.html){ $grid.append(resp.data.html); $btn.attr('data-page',resp.data.page); }
      if(!resp || !resp.success || !resp.data || !resp.data.has_more){ $btn.closest('.aipex-load-wrap').remove(); return; }
      $btn.prop('disabled',false).html('<span class="aipex-btn-icon">＋</span> '+label);
    }).fail(function(){ $btn.prop('disabled',false).html('<span class="aipex-btn-icon">＋</span> '+label); });
  });
});
