jQuery(function($){
  // ── SoundCloud Widget API ──────────────────────────────────────────────────
  // Initialised lazily on first play; one widget instance per floating player.
  var scWidgets = {};

  function getScWidget($wrap){
    var id = $wrap.attr('id');
    if (!id) { id = 'aipex-fp-'+Date.now(); $wrap.attr('id', id); }
    if (scWidgets[id]) return scWidgets[id];
    var iframe = $wrap.find('.aipex-sc-widget-frame')[0];
    if (!iframe || typeof SC === 'undefined') return null;
    var widget = SC.Widget(iframe);
    widget.bind(SC.Widget.Events.FINISH, function(){ advanceTrack($wrap, 1, false); });
    widget.bind(SC.Widget.Events.PLAY, function(){ $wrap.find('.aipex-sc-btn-play').text('⏸'); });
    widget.bind(SC.Widget.Events.PAUSE, function(){ $wrap.find('.aipex-sc-btn-play').text('▶'); });
    scWidgets[id] = widget;
    return widget;
  }

  // ── Unified setTrack — works for both audio and SoundCloud modes ──────────
  function setTrack($wrap, $btn, autoplay){
    if (!$btn.length) return;
    $wrap.find('.aipex-floating-track').removeClass('is-active');
    $btn.addClass('is-active');
    $wrap.attr('data-current', $btn.data('index'));
    $wrap.find('.aipex-now-title').text($btn.data('title'));

    var mode = $wrap.data('mode') || 'audio';

    if (mode === 'soundcloud') {
      var scUrl = $btn.data('sc-url');
      if (!scUrl) return;
      // SC Widget API may not be loaded yet (async script); retry if needed
      var tryLoad = function(){
        var widget = getScWidget($wrap);
        if (!widget) { setTimeout(tryLoad, 300); return; }
        widget.load(scUrl, {auto_play: autoplay, buying: false, liking: false, download: false, sharing: false, show_artwork: false, show_playcount: false, show_user: false});
      };
      tryLoad();
    } else {
      var audio = $wrap.find('#aipex-floating-audio')[0];
      if (!audio) return;
      $(audio).attr('src', $btn.data('audio'));
      if (autoplay) audio.play();
    }
  }

  function advanceTrack($wrap, dir, autoplay){
    var $tracks = $wrap.find('.aipex-floating-track:visible');
    var $all    = $wrap.find('.aipex-floating-track');
    var cur     = parseInt($wrap.attr('data-current') || 0, 10);
    var vi      = $tracks.index($all.eq(cur));
    if (vi < 0) vi = 0;
    vi += dir;
    if (vi < 0) vi = $tracks.length - 1;
    if (vi >= $tracks.length) vi = 0;
    setTrack($wrap, $tracks.eq(vi), autoplay !== false);
  }

  // SC play/pause toggle button
  $(document).on('click', '.aipex-sc-btn-play', function(){
    var $wrap = $(this).closest('.aipex-floating-player');
    var widget = getScWidget($wrap);
    if (widget) widget.toggle();
  });

  $(document).on('click','.aipex-floating-track',function(){
    var $wrap = $(this).closest('.aipex-floating-player');
    setTrack($wrap, $(this), true);
    $wrap.find('.aipex-episode-drawer').attr('hidden', true);
    $wrap.removeClass('drawer-open');
  });

  $(document).on('click','.aipex-float-next,.aipex-float-prev',function(){
    var dir = $(this).hasClass('aipex-float-next') ? 1 : -1;
    advanceTrack($(this).closest('.aipex-floating-player'), dir, true);
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

  // Search
  var searchTimer;
  $(document).on('input','.aipex-search-input',function(){
    clearTimeout(searchTimer);
    var $wrap=$(this).closest('.aipex-search-wrap'), term=$(this).val(), limit=$wrap.data('limit')||10;
    var $results=$wrap.find('.aipex-search-results');
    if(term.length<2){ $results.html(''); return; }
    $results.html('<p class="aipex-searching">Searching…</p>');
    searchTimer=setTimeout(function(){
      $.post(AipexPodcast.ajaxurl,{action:'aipex_search',nonce:AipexPodcast.nonce,term:term,limit:limit},function(resp){
        if(resp&&resp.success) $results.html(resp.data.html||'<p class="aipex-no-results">No episodes found.</p>');
        else $results.html('<p class="aipex-no-results">Search unavailable.</p>');
      }).fail(function(){ $results.html('<p class="aipex-no-results">Search unavailable.</p>'); });
    },320);
  });

  // Filters — update nearest episode grid using relationship grid AJAX
  $(document).on('click','.aipex-filter-btn',function(){
    var $btn=$(this), $group=$btn.closest('.aipex-filter-buttons');
    $group.find('.aipex-filter-btn').removeClass('is-active');
    $btn.addClass('is-active');
    var entity_type=$group.data('entity-type'), entity_id=parseInt($btn.data('id')||0,10);
    var $grid=$btn.closest('.aipex-filters').nextAll('.aipex-card-grid').first();
    if(!$grid.length) $grid=$('.aipex-card-grid').first();
    $grid.css('opacity','0.5');
    var relationship='episodes', limit=12;
    var ctx={relationship:relationship,entity_type:entity_type,entity_id:entity_id,limit:limit};
    if(entity_id===0){
      // No filter — load latest episodes
      $.post(AipexPodcast.ajaxurl,{action:'aipex_grid_load_more',nonce:AipexPodcast.nonce,page:0,kind:'episodes',context:JSON.stringify({kind:'episodes',limit:limit})},function(resp){
        if(resp&&resp.success&&resp.data.html) $grid.html(resp.data.html);
        $grid.css('opacity','1');
      });
    } else {
      $.post(AipexPodcast.ajaxurl,{action:'aipex_relationship_grid_load_more',nonce:AipexPodcast.nonce,page:0,context:JSON.stringify(ctx)},function(resp){
        if(resp&&resp.success&&resp.data.html) $grid.html(resp.data.html);
        $grid.css('opacity','1');
      }).fail(function(){ $grid.css('opacity','1'); });
    }
  });

  $(document).on('change','.aipex-filter-select',function(){
    var $sel=$(this), entity_type=$sel.data('entity-type'), entity_id=parseInt($sel.val()||0,10);
    $sel.trigger('blur');
    $sel.closest('.aipex-filters').find('.aipex-filter-btn[data-id="'+entity_id+'"]').trigger('click');
  });

  // Play tracking — fires once per audio element per page load, silently.
  // The episode ID is read from the nearest [data-episode-id] ancestor or
  // from the page body's data attribute set by wp_localize_script.
  var trackedAudio = new Set();
  $(document).on('play','audio',function(){
    var $audio=$(this);
    if(trackedAudio.has(this)) return;
    trackedAudio.add(this);
    var episode_id = $audio.closest('[data-episode-id]').data('episode-id')
                  || $('body').data('aipex-episode-id')
                  || (AipexPodcast && AipexPodcast.episode_id ? AipexPodcast.episode_id : 0);
    if(!episode_id || !AipexPodcast || !AipexPodcast.ajaxurl) return;
    $.post(AipexPodcast.ajaxurl,{action:'aipex_track_play',nonce:AipexPodcast.nonce,episode_id:episode_id});
  });

  // Like button — one like per visitor per post, tracked via cookie
  function getLikedIds(){
    try{ return JSON.parse(decodeURIComponent(document.cookie.replace(/(?:(?:^|.*;)\s*aipex_likes\s*=\s*([^;]*).*$)|^.*$/,'$1')||'[]')); }
    catch(e){ return []; }
  }
  function setLikedIds(ids){
    var exp=new Date(); exp.setFullYear(exp.getFullYear()+1);
    document.cookie='aipex_likes='+encodeURIComponent(JSON.stringify(ids))+';expires='+exp.toUTCString()+';path=/;SameSite=Lax';
  }
  function initLikeButtons(){
    var liked=getLikedIds();
    $('.aipex-like-btn').each(function(){
      var id=parseInt($(this).data('post-id'));
      if(liked.indexOf(id)!==-1) $(this).addClass('is-liked');
    });
  }
  $(initLikeButtons);
  $(document).on('click','.aipex-like-btn',function(){
    var $btn=$(this), id=parseInt($btn.data('post-id'));
    if($btn.hasClass('is-liked')) return; // already liked
    $btn.prop('disabled',true);
    $.post(AipexPodcast.ajaxurl,{action:'aipex_like_post',nonce:AipexPodcast.nonce,post_id:id},function(resp){
      if(resp&&resp.success){
        $btn.addClass('is-liked').find('.aipex-like-count').text(resp.data.count.toLocaleString());
        var liked=getLikedIds(); liked.push(id); setLikedIds(liked);
      }
      $btn.prop('disabled',false);
    }).fail(function(){ $btn.prop('disabled',false); });
  });
