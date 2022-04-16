<?php
require '../autoload.php';

ensureSession();
$session = getSession();
$api = createWebApi($session);
?>

var PLAYLIST_ID = '';
var PREVIEW_AUDIO = $('<audio />');
var PLAYLIST_DANCE_DELIMITER = 0;
var TRACK_DRAG_STATE = 0;
var UNDO_STACK_LIMIT = 100;
var UNDO_STACK = Array(UNDO_STACK_LIMIT).fill(null);
var UNDO_STACK_OFFSET = -1;
var LAST_SPOTIFY_PLAYLIST_HASH = '';

const BPM_MIN = 0;
const BPM_MAX = 255;

function setupPlaylist(playlist_id) {
  PLAYLIST_ID = playlist_id;
  initTable(getPlaylistTable());
  initTable(getScratchpadTable());
  $(document).on( 'keyup'
                , function(e) {
                    if (e.key == 'Escape') {
                      clearTrackTrSelection();
                    }
                    else if (e.key == 'Delete') {
                      deleteSelectedTrackTrs();
                    }
                  }
                );
  $(window).resize(setPlaylistHeight);
  $(window).resize(renderBpmOverview);
}

function getTableOfTr(tr) {
  return tr.closest('table');
}

function loadPlaylist(playlist_id) {
  var body = $(document.body);
  body.addClass('loading');
  setStatus('<?= LNG_DESC_LOADING ?>...');
  function success() {
    body.removeClass('loading');
    clearStatus();
    saveUndoState();
  }
  function fail(msg) {
    setStatus('<?= LNG_ERR_FAILED_LOAD_PLAYLIST ?>', true);
    body.removeClass('loading');
  }
  function snapshot_success() {
    success();
    checkForChangesInSpotifyPlaylist(playlist_id);
  }
  function noSnapshot() {
    loadPlaylistFromSpotify(playlist_id, success, fail);
  }
  loadPlaylistFromSnapshot(playlist_id, snapshot_success, noSnapshot, fail);
}

function loadPlaylistFromSpotify(playlist_id, success_f, fail_f) {
  function updatePlaylistHash() {
    var track_ids = getPlaylistTrackData().map(t => t.trackId);
    LAST_SPOTIFY_PLAYLIST_HASH = computePlaylistHash(track_ids);
  }
  function load(offset) {
    var data = { playlistId: playlist_id
               , offset: offset
               };
    callApi( '/api/get-playlist-tracks/'
           , data
           , function(d) {
               var data = { trackIds: d.tracks };
               callApi( '/api/get-track-info/'
                      , data
                      , function(dd) {
                          var tracks = [];
                          for (var i = 0; i < dd.tracks.length; i++) {
                            var t = dd.tracks[i];
                            var o = createPlaylistTrackObject( t.trackId
                                                             , t.artists
                                                             , t.name
                                                             , t.length
                                                             , t.bpm
                                                             , t.genre.by_user
                                                             , t.genre.by_others
                                                             , t.comments
                                                             , t.preview_url
                                                             );
                            tracks.push(o);
                          }
                          appendTracks(getPlaylistTable(), tracks);
                          var next_offset = offset + tracks.length;
                          if (next_offset < d.total) {
                            load(next_offset);
                          }
                          else {
                            renderPlaylist();
                            updatePlaylistHash();
                            success_f();
                          }
                        }
                      , fail_f
                      )
             }
           , fail_f
           );
  }
  load(0);
}

function checkForChangesInSpotifyPlaylist(playlist_id) {
  var body = $(document.body);
  function fail(msg) {
    setStatus('<?= LNG_ERR_FAILED_LOAD_PLAYLIST ?>', true);
    body.removeClass('loading');
  }
  function getActionArea() {
    return $('.action-input-area[name=playlist-inconsistencies]');
  }
  function checkForAdditions(snapshot_tracks, spotify_track_ids, callback_f) {
    body.addClass('loading');
    setStatus('<?= LNG_DESC_LOADING ?>...');
    function cleanup() {
      body.removeClass('loading');
      clearStatus();
    }

    // Find tracks appearing Spotify but not in snapshot
    var new_track_ids = [];
    for (var i = 0; i < spotify_track_ids.length; i++) {
      var tid = spotify_track_ids[i];
      var t = getTrackWithMatchingId(snapshot_tracks, tid);
      if (t === null) {
        new_track_ids.push(tid);
      }
    }
    if (new_track_ids.length == 0) {
      cleanup();
      callback_f();
      return;
    }

    var has_finalized = false;
    function finalize() {
      if (!has_finalized) {
        cleanup();
        clearActionInputs();
        callback_f();
      }
      has_finalized = true;
    }
    function loadTracks(offset, dest_table) {
      var tracks_to_load = [];
      var o = offset;
      for ( var o = offset
          ; o < new_track_ids.length && tracks_to_load.length < LOAD_TRACKS_LIMIT
          ; o++
          )
      {
        tracks_to_load.push(new_track_ids[o]);
      }
      callApi( '/api/get-track-info/'
             , { trackIds: tracks_to_load }
             , function(d) {
                 var tracks = [];
                 for (var i = 0; i < d.tracks.length; i++) {
                   var t = d.tracks[i];
                   var o = createPlaylistTrackObject( t.trackId
                                                    , t.artists
                                                    , t.name
                                                    , t.length
                                                    , t.bpm
                                                    , t.genre.by_user
                                                    , t.genre.by_others
                                                    , t.comments
                                                    , t.preview_url
                                                    );
                   tracks.push(o);
                 }
                 appendTracks(dest_table, tracks);
                 var next_offset = offset + tracks.length;
                 if (next_offset < d.total) {
                   loadTracks(next_offset, dest_table);
                 }
                 else {
                   renderTable(dest_table);
                   indicateStateUpdate();
                   finalize();
                 }
               }
             , fail
             );
    }

    var a = getActionArea();
    a.find('p').text('<?= LNG_DESC_TRACK_ADDITIONS_DETECTED ?>');
    var btn1 = a.find('#inconPlaylistBtn1');
    var btn2 = a.find('#inconPlaylistBtn2');
    var cancel_btn = btn1.closest('div').find('button.cancel');
    btn1.text('<?= LNG_BTN_APPEND_TO_PLAYLIST ?>');
    btn2.text('<?= LNG_BTN_APPEND_TO_SCRATCHPAD ?>');
    btn1.click(
      function() {
        loadTracks(0, getPlaylistTable());
      }
    );
    btn2.click(
      function() {
        loadTracks(0, getScratchpadTable());
        showScratchpad();
      }
    );
    cancel_btn.click(finalize);
    a.show();

    function esc_f(e) {
      if (e.key == 'Escape') {
        finalize();
        $(document).unbind('keyup', esc_f);
      }
    }
    $(document).on('keyup', esc_f);
  }
  function checkForDeletions(snapshot_tracks, spotify_track_ids, callback_f) {
    // Find tracks not appearing Spotify but in snapshot
    var removed_track_ids = [];
    for (var i = 0; i < snapshot_tracks.length; i++) {
      var tid = snapshot_tracks[i].trackId;
      if (tid === undefined) {
        continue;
      }
      var found = false;
      for (var j = 0; j < spotify_track_ids.length; j++) {
        if (spotify_track_ids[j] == tid) {
          found = true;
          break;
        }
      }
      if (!found) {
        removed_track_ids.push(tid);
      }
    }
    if (removed_track_ids.length == 0) {
      callback_f();
      return;
    }

    var has_finalized = false;
    function finalize() {
      if (!has_finalized) {
        clearActionInputs();
        callback_f();
      }
      has_finalized = true;
    }
    function popTracks(tracks_to_remove) {
      var removed_tracks = [];

      // Pop from playlist
      var has_removed = false;
      var playlist_tracks = getPlaylistTrackData();
      for (var i = 0; i < tracks_to_remove.length; i++) {
        var res = popTrackWithMatchingId(playlist_tracks, tracks_to_remove[i]);
        playlist_tracks = res[0];
        var removed_t = res[1];
        if (removed_t !== null) {
          removed_tracks.push(removed_t);
          has_removed = true;
        }
      }
      if (has_removed) {
        replaceTracks(getPlaylistTable(), playlist_tracks);
      }

      // Pop from scratchpad
      has_removed = false;
      var scratchpad_tracks = getScratchpadTrackData();
      for (var i = 0; i < tracks_to_remove.length; i++) {
        var res = popTrackWithMatchingId(scratchpad_tracks, tracks_to_remove[i]);
        scratchpad_tracks = res[0];
        var removed_t = res[1];
        if (removed_t !== null) {
          removed_tracks.push(removed_t);
          has_removed = true;
        }
      }
      if (has_removed) {
        replaceTracks(getScratchpadTable(), scratchpad_tracks);
      }

      return removed_tracks;
    }
    var a = getActionArea();
    a.find('p').text('<?= LNG_DESC_TRACK_DELETIONS_DETECTED ?>');
    var btn1 = a.find('#inconPlaylistBtn1');
    var btn2 = a.find('#inconPlaylistBtn2');
    btn1.text('<?= LNG_BTN_REMOVE ?>');
    btn2.text('<?= LNG_BTN_MOVE_TO_SCRATCHPAD ?>');
    var cancel_btn = btn1.closest('div').find('button.cancel');
    btn1.click(
      function() {
        popTracks(removed_track_ids);
        renderTable(getScratchpadTable());
        indicateStateUpdate();
        finalize();
      }
    );
    btn2.click(
      function() {
        var removed_tracks = popTracks(removed_track_ids);
        var scratchpad_data = getScratchpadTrackData();
        var new_scratchpad_data = scratchpad_data.concat(removed_tracks);
        replaceTracks(getScratchpadTable(), new_scratchpad_data);
        renderTable(getScratchpadTable());
        indicateStateUpdate();
        showScratchpad();
        finalize();
      }
    );
    cancel_btn.click(finalize);
    a.show();

    function esc_f(e) {
      if (e.key == 'Escape') {
        finalize();
        $(document).unbind('keyup', esc_f);
      }
    }
    $(document).on('keyup', esc_f);
  }
  var spotify_track_ids = [];
  function load(offset) {
    var data = { playlistId: playlist_id
               , offset: offset
               };
    callApi( '/api/get-playlist-tracks/'
           , data
           , function(d) {
               spotify_track_ids = spotify_track_ids.concat(d.tracks);
               var next_offset = offset + d.tracks.length;
               if (next_offset < d.total) {
                 load(next_offset);
               }
               else {
                 var playlist_hash = computePlaylistHash(spotify_track_ids);
                 if (playlist_hash == LAST_SPOTIFY_PLAYLIST_HASH) {
                   return;
                 }
                 LAST_SPOTIFY_PLAYLIST_HASH = playlist_hash;
                 var snapshot_tracks =
                   getPlaylistTrackData().concat(getScratchpadTrackData());
                 checkForAdditions( snapshot_tracks
                                  , spotify_track_ids
                                  , function () {
                                      checkForDeletions( snapshot_tracks
                                                       , spotify_track_ids
                                                       , function() {}
                                                       );
                                    }
                                  );
               }
             }
           , fail
           );
  }
  load(0);
}

function computePlaylistHash(track_ids) {
  // https://stackoverflow.com/a/52171480
  function cyrb53(str, seed = 0) {
    let h1 = 0xdeadbeef ^ seed, h2 = 0x41c6ce57 ^ seed;
    for (let i = 0, ch; i < str.length; i++) {
      ch = str.charCodeAt(i);
      h1 = Math.imul(h1 ^ ch, 2654435761);
      h2 = Math.imul(h2 ^ ch, 1597334677);
    }
    h1 = Math.imul(h1 ^ (h1>>>16), 2246822507) ^
         Math.imul(h2 ^ (h2>>>13), 3266489909);
    h2 = Math.imul(h2 ^ (h2>>>16), 2246822507) ^
         Math.imul(h1 ^ (h1>>>13), 3266489909);
    return 4294967296 * (2097151 & h2) + (h1>>>0);
  };

  return cyrb53(track_ids.join(''));
}

function playPreview(jlink, preview_url, playing_text, stop_text) {
  PREVIEW_AUDIO.attr('src', ''); // Stop playing
  var clicked_playing_preview = jlink.hasClass('playing');
  var preview_links = $.merge( getPlaylistTable().find('tr.track .title a')
                             , getScratchpadTable().find('tr.track .title a')
                             );
  preview_links.each(
    function() {
      $(this).removeClass('playing');
      $(this).html(stop_text);
    }
  );
  if (clicked_playing_preview) {
    jlink.html(stop_text);
    return;
  }

  jlink.html(playing_text);
  jlink.addClass('playing');
  PREVIEW_AUDIO.attr('src', preview_url);
  PREVIEW_AUDIO.get(0).play();
}

function updateBpmInDb(track_id, bpm, success_f, fail_f) {
  callApi( '/api/update-bpm/'
         , { trackId: track_id, bpm: bpm }
         , function(d) { success_f(d); }
         , function(msg) { fail_f(msg); }
         );
}

function addTrackBpmHandling(tr) {
  var input = tr.find('input[name=bpm]');
  input.click(
    function(e) {
      e.stopPropagation(); // Prevent row selection
    }
  );
  input.focus(
    function() {
      $(this).css('background-color', '#fff');
      $(this).css('color', '#000');
      $(this).data('old-value', $(this).val().trim());
    }
  );
  input.blur(
    function() {
      renderTrackBpm($(this).closest('tr'));
    }
  );
  function fail(msg) {
    setStatus('<?= LNG_ERR_FAILED_UPDATE_BPM ?>', true);
  }
  input.change(
    function() {
      var input = $(this);

      // Find corresponding track ID
      var tid_input = input.closest('tr').find('input[name=track_id]');
      if (tid_input.length == 0) {
        console.log('could not find track ID');
        return;
      }
      var tid = tid_input.val().trim();
      if (tid.length == 0) {
        return;
      }

      // Check BPM value
      var bpm = input.val().trim();
      if (!checkBpmInput(bpm)) {
        input.addClass('invalid');
        return;
      }
      bpm = parseInt(bpm);
      input.removeClass('invalid');

      setStatus('<?= LNG_DESC_SAVING ?>...');
      updateBpmInDb( tid
                   , bpm
                   , clearStatus
                   , fail
                   );

      // Update BPM on all duplicate tracks (if any)
      function update(table, tid) {
        table.find('input[name=track_id][value=' + tid + ']').each(
          function() {
            var tr = $(this).closest('tr');
            tr.find('input[name=bpm]').val(bpm);
            renderTrackBpm(tr);
          }
        );
      }
      update(getPlaylistTable(), tid);
      update(getScratchpadTable(), tid);

      var old_value = parseInt(input.data('old-value'));
      // .data() must be read here or else it will disappear upon undo/redo
      setCurrentUndoStateCallback(
        function() {
          updateBpmInDb( tid
                       , old_value
                       , function() {}
                       , fail
                       );
        }
      );
      indicateStateUpdate();
      setCurrentUndoStateCallback(
        function() {
          updateBpmInDb( tid
                       , bpm
                       , function() {}
                       , fail
                       );
        }
      );

      renderBpmOverview();
    }
  );
}

function renderTrackBpm(tr) {
  var input = tr.find('input[name=bpm]');
  if (input.length == 0) {
    return;
  }

  var bpm = input.val().trim();
  if (!checkBpmInput(bpm, false)) {
    return;
  }
  bpm = parseInt(bpm);
  let cs = getBpmRgbColor(bpm);
  input.css('background-color', 'rgb(' + cs.join(',') + ')');
  $text_color = (bpm <= 50 || bpm  > 210) ? '#fff' : '#000';
  input.css('color', $text_color);
}

function getBpmRgbColor(bpm) {
  //               bpm    color (RGB)
  const colors = [ [   0, [  0,   0,   0] ] // Black
                 , [  40, [  0,   0, 255] ] // Blue
                 , [  65, [  0, 255, 255] ] // Turquoise
                 , [  80, [  0, 255,   0] ] // Green
                 , [  95, [255, 255,   0] ] // Yellow
                 , [ 140, [255,   0,   0] ] // Red
                 , [ 180, [255,   0, 255] ] // Purple
                 , [ 210, [  0,   0, 255] ] // Blue
                 , [ 255, [  0,   0,   0] ] // Black
                 ];
  for (var i = 0; i < colors.length; i++) {
    if (i == colors.length-2 || bpm < colors[i+1][0]) {
      var p = (bpm - colors[i][0]) / (colors[i+1][0] - colors[i][0]);
      var c = [...colors[i][1]];
      for (var j = 0; j < c.length; j++) {
        c[j] += Math.round((colors[i+1][1][j] - c[j]) * p);
      }
      return c;
    }
  }
  console.log('ERROR: getBpmColor() with arg ' + bpm);
  return null;
}

function checkBpmInput(str, report_on_fail = true) {
  bpm = parseInt(str);
  if (isNaN(bpm)) {
    if (report_on_fail) {
      alert('<?= LNG_ERR_BPM_NAN ?>');
    }
    return false;
  }
  if (bpm < BPM_MIN) {
    if (report_on_fail) {
      alert('<?= LNG_ERR_BPM_TOO_SMALL ?>');
    }
    return false;
  }
  if (bpm > BPM_MAX) {
    if (report_on_fail) {
      alert('<?= LNG_ERR_BPM_TOO_LARGE ?>');
    }
    return false;
  }
  return true;
}

function updateGenreInDb(track_id, genre, success_f, fail_f) {
  callApi( '/api/update-genre/'
         , { trackId: track_id, genre: genre }
         , function(d) { success_f(d); }
         , function(msg) { fail_f(msg); }
         );
}

function addTrackGenreHandling(tr) {
  var select = tr.find('select[name=genre]');
  function update(s) {
    function fail(msg) {
      setStatus('<?= LNG_ERR_FAILED_UPDATE_GENRE ?>', true);
    }

    var genre = parseInt(s.find(':selected').val().trim());
    var old_value = parseInt(s.data('old-value'));
    if (genre == old_value && !s.hasClass('chosen-by-others')) {
      return;
    }
    s.removeClass('chosen-by-others');
    s.data('old-value', genre);

    // Find corresponding track ID
    var tid_input = s.closest('tr').find('input[name=track_id]');
    if (tid_input.length == 0) {
      console.log('could not find track ID');
      return;
    }
    var tid = tid_input.val().trim();
    if (tid.length == 0) {
      return;
    }

    setStatus('<?= LNG_DESC_SAVING ?>...');
    updateGenreInDb( tid
                   , genre
                   , clearStatus
                   , fail
                   );

    // Update genre on all duplicate tracks (if any)
    function update(table, tid) {
      table.find('input[name=track_id][value=' + tid + ']').each(
        function() {
          var tr = $(this).closest('tr');
          tr.find('select[name=genre] option').prop('selected', false);
          tr.find('select[name=genre] option[value=' + genre + ']')
            .prop('selected', true);
        }
      );
    }
    update(getPlaylistTable(), tid);
    update(getScratchpadTable(), tid);

    setCurrentUndoStateCallback(
      function() {
        updateGenreInDb( tid
                       , old_value
                       , function() {}
                       , fail
                       );
      }
    );
    indicateStateUpdate();
    setCurrentUndoStateCallback(
      function() {
        updateGenreInDb( tid
                       , genre
                       , function() {}
                       , fail
                       );
      }
    );
  }
  select.click(
    function(e) {
      e.stopPropagation(); // Prevent row selection
      update($(this));
    }
  );
  select.change(function() { update($(this)); });
  select.focus(
    function() {
      $(this).data('old-value', $(this).find(':selected').val().trim());
    }
  );
}

function updateCommentsInDb(track_id, comments, success_f, fail_f) {
  callApi( '/api/update-comments/'
         , { trackId: track_id, comments: comments }
         , function(d) { success_f(d); }
         , function(msg) { fail_f(msg); }
         );
}

function addTrackCommentsHandling(tr) {
  var textarea = tr.find('textarea[name=comments]');
  textarea.click(
    function(e) {
      e.stopPropagation(); // Prevent row selection
    }
  );
  textarea.focus(
    function() {
      $(this).data('old-value', $(this).val().trim());
    }
  );
  function fail(msg) {
    setStatus('<?= LNG_ERR_FAILED_UPDATE_COMMENTS ?>', true);
  }
  textarea.change(
    function() {
      var textarea = $(this);
      renderTrackComments(textarea.closest('tr'));

      // Find corresponding track ID
      var tid_input = textarea.closest('tr').find('input[name=track_id]');
      if (tid_input.length == 0) {
        console.log('could not find track ID');
        return;
      }
      var tid = tid_input.val().trim();
      if (tid.length == 0) {
        return;
      }

      var comments = textarea.val().trim();
      setStatus('<?= LNG_DESC_SAVING ?>...');
      updateCommentsInDb( tid
                        , comments
                        , clearStatus
                        , fail
                        );

      // Update comments on all duplicate tracks (if any)
      function update(table, tid) {
        table.find('input[name=track_id][value=' + tid + ']').each(
          function() {
            var tr = $(this).closest('tr');
            tr.find('textarea[name=comments]').val(comments);
            renderTrackComments(tr);
          }
        );
      }
      update(getPlaylistTable(), tid);
      update(getScratchpadTable(), tid);

      var old_value = parseInt(textarea.data('old-value'));
      // .data() must be read here or else it will disappear upon undo/redo
      setCurrentUndoStateCallback(
        function() {
          updateCommentsInDb( tid
                            , old_value
                            , function() {}
                            , fail
                            );
        }
      );
      indicateStateUpdate();
      setCurrentUndoStateCallback(
        function() {
          updateCommentsInDb( tid
                            , comments
                            , function() {}
                            , fail
                            );
        }
      );
    }
  );
}

function renderTrackComments(tr) {
  var textarea = tr.find('textarea[name=comments]');
  if (textarea.length == 0) {
    return;
  }

  // Adjust height
  if (textarea.data('defaultHeight') === undefined) {
    textarea.data('defaultHeight', textarea.height());
  }
  textarea.css('height', textarea.data('defaultHeight'));
  textarea.css( 'height'
              , textarea.prop('scrollHeight')+2 + 'px'
                // +2 is to prevent scrollbars that appear otherwise
              );
}

function getTrTitleText(tr) {
  var nodes = tr.find('td.title').contents().filter(
                function() { return this.nodeType == 3; }
              );
  if (nodes.length > 0) {
    return nodes[0].nodeValue;
  }
  return '';
}

function getTrackData(table) {
  var playlist = [];
  table.find('tr.track, tr.empty-track').each(
    function() {
      var tr = $(this);
      if (tr.hasClass('track')) {
        var track_id = tr.find('input[name=track_id]').val().trim();
        var artists = tr.find('input[name=artists]').val().trim();
        artists = artists.length > 0 ? artists.split(',') : [];
        var name = tr.find('input[name=name]').val().trim();
        var preview_url = tr.find('input[name=preview_url]').val().trim();
        var bpm = parseInt(tr.find('input[name=bpm]').val().trim());
        var genre_by_user =
          parseInt(tr.find('select[name=genre] option:selected').val().trim());
        var genres_by_others_text =
          tr.find('input[name=genres_by_others]').val().trim();
        var genres_by_others =
          genres_by_others_text.length > 0
            ? genres_by_others_text.split(',').map(s => parseInt(s))
            : [];
        var title = getTrTitleText(tr);
        var len_ms = parseInt(tr.find('input[name=length_ms]').val().trim());
        var comments = tr.find('textarea[name=comments]').val().trim();
        var o = createPlaylistTrackObject( track_id
                                         , artists
                                         , name
                                         , len_ms
                                         , bpm
                                         , genre_by_user
                                         , genres_by_others
                                         , comments
                                         , preview_url
                                         );
        playlist.push(o);
      }
      else{
        var name = tr.find('td.title').text().trim();
        var length = tr.find('td.length').text().trim();
        var bpm = tr.find('td.bpm').text().trim();
        var genre = tr.find('td.genre').text().trim();
        playlist.push(createPlaylistPlaceholderObject(name, length, bpm, genre));
      }
    }
  );

  return playlist;
}

function removePlaceholdersFromTracks(tracks) {
  return tracks.filter( function(t) { return t.trackId !== undefined } );
}

function getPlaylistTrackData() {
  var table = getPlaylistTable();
  return getTrackData(table);
}

function getScratchpadTrackData() {
  var table = getScratchpadTable();
  return getTrackData(table);
}

function createPlaylistTrackObject( track_id
                                  , artists
                                  , name
                                  , length_ms
                                  , bpm
                                  , genre_by_user
                                  , genres_by_others
                                  , comments
                                  , preview_url
                                  )
{
  return { trackId: track_id
         , artists: artists
         , name: name
         , length: length_ms
         , bpm: bpm
         , genre: { by_user: genre_by_user
                  , by_others: genres_by_others
                  }
         , comments: comments
         , previewUrl: preview_url
         }
}

function createPlaylistPlaceholderObject( name_text
                                        , length_text
                                        , bpm_text
                                        , genre_text
                                        )
{
  if (name_text === undefined) {
    return { name: '<?= LNG_DESC_PLACEHOLDER ?>'
           , length: ''
           , bpm: ''
           , genre: ''
           }
  }
  return { name: name_text
         , length: length_text
         , bpm: bpm_text
         , genre: genre_text
         }
}

function popTrackWithMatchingId(track_list, track_id) {
  var i = 0;
  for (; i < track_list.length && track_list[i].trackId != track_id; i++) {}
  if (i < track_list.length) {
    var t = track_list[i];
    track_list.splice(i, 1);
    return [track_list, t];
  }
  return [track_list, null];
}

function getTrackWithMatchingId(track_list, track_id) {
  var i = 0;
  for (; i < track_list.length && track_list[i].trackId != track_id; i++) {}
  return i < track_list.length ? track_list[i] : null;
}

function initTable(table) {
  var head_tr =
    $( '<tr>' +
       '  <th class="index">#</th>' +
       '  <th class="bpm"><?= LNG_HEAD_BPM ?></th>' +
       '  <th class="genre"><?= LNG_HEAD_GENRE ?></th>' +
       '  <th><?= LNG_HEAD_TITLE ?></th>' +
       '  <th class="comments"><?= LNG_HEAD_COMMENTS ?></th>' +
       '  <th class="length"><?= LNG_HEAD_LENGTH ?></th>' +
       '  <th class="aggr-length"><?= LNG_HEAD_TOTAL ?></th>' +
       '</tr>'
     );
  table.find('thead').append(head_tr);
  table.append(buildNewTableSummaryRow());
}

function getGenreList() {
  return [ [  1, '<?= strtolower(LNG_GENRE_DANCEBAND) ?>']
         , [  2, '<?= strtolower(LNG_GENRE_COUNTRY) ?>']
         , [  3, '<?= strtolower(LNG_GENRE_ROCK) ?>']
         , [  4, '<?= strtolower(LNG_GENRE_POP) ?>']
         , [  5, '<?= strtolower(LNG_GENRE_SCHLAGER) ?>']
         , [  6, '<?= strtolower(LNG_GENRE_METAL) ?>']
         , [  7, '<?= strtolower(LNG_GENRE_PUNK) ?>']
         , [  8, '<?= strtolower(LNG_GENRE_DISCO) ?>']
         , [  9, '<?= strtolower(LNG_GENRE_RNB) ?>']
         , [ 10, '<?= strtolower(LNG_GENRE_BLUES) ?>']
         , [ 11, '<?= strtolower(LNG_GENRE_JAZZ) ?>']
         , [ 12, '<?= strtolower(LNG_GENRE_HIP_HOP) ?>']
         , [ 13, '<?= strtolower(LNG_GENRE_ELECTRONIC) ?>']
         , [ 14, '<?= strtolower(LNG_GENRE_HOUSE) ?>']
         , [ 15, '<?= strtolower(LNG_GENRE_CLASSICAL) ?>']
         , [ 16, '<?= strtolower(LNG_GENRE_SOUL) ?>']
         , [ 17, '<?= strtolower(LNG_GENRE_LATIN) ?>']
         , [ 18, '<?= strtolower(LNG_GENRE_REGGAE) ?>']
         , [ 19, '<?= strtolower(LNG_GENRE_TANGO) ?>']
         , [ 20, '<?= strtolower(LNG_GENRE_OPERA) ?>']
         , [ 21, '<?= strtolower(LNG_GENRE_SALSA) ?>']
         , [ 22, '<?= strtolower(LNG_GENRE_KIZOMBA) ?>']
         , [ 23, '<?= strtolower(LNG_GENRE_ROCKABILLY) ?>']
         , [ 24, '<?= strtolower(LNG_GENRE_ACOUSTIC) ?>']
         , [ 25, '<?= strtolower(LNG_GENRE_BALLAD) ?>']
         , [ 26, '<?= strtolower(LNG_GENRE_FUNK) ?>']
         , [ 27, '<?= strtolower(LNG_GENRE_VISPOP) ?>']
         , [ 28, '<?= strtolower(LNG_GENRE_FOLK_MUSIC) ?>']
         ];
}

function genreToString(g) {
  const g_list = getGenreList();
  for (let i = 0; i < g_list.length; i++) {
    e = g_list[i];
    if (e[0] == g) {
      return e[1];
    }
  }
  return '';
}

function addOptionsToGenreSelect(s, ignore_empty = false) {
  var genres = [ [  0, ''] ].concat(getGenreList());
  if (ignore_empty) {
    genres.shift();
  }
  genres.sort( function(a, b) {
                 if (a[0] == 0) return -1;
                 if (b[0] == 0) return  1;
                 return strcmp(a[1], b[1]);
               }
             );
  genres.map(
    function(g) {
      var o = $('<option value="' + g[0] + '">' + g[1] + '</value>');
      s.append(o);
    }
  )
}

function buildNewTableTrackTr() {
  var tr =
    $( '<tr class="track">' +
       '  <input type="hidden" name="track_id" value="" />' +
       '  <input type="hidden" name="artists" value="" />' +
       '  <input type="hidden" name="name" value="" />' +
       '  <input type="hidden" name="preview_url" value="" />' +
       '  <input type="hidden" name="length_ms" value="" />' +
       '  <input type="hidden" name="genres_by_others" value="" />' +
       '  <td class="index" />' +
       '  <td class="bpm">' +
       '    <input type="text" name="bpm" class="bpm" value="" />' +
       '  </td>' +
       '  <td class="genre">' +
       '    <select class="genre" name="genre"></select>' +
       '  </td>' +
       '  <td class="title" />' +
       '  <td class="comments">' +
       '    <textarea name="comments" class="comments" maxlength="255">' +
           '</textarea>' +
       '  </td>' +
       '  <td class="length" />' +
       '  <td class="aggr-length" />' +
       '</tr>'
     );
  addOptionsToGenreSelect(tr.find('select[name=genre]'));
  return tr;
}

function buildNewTableSummaryRow() {
  return $( '<tr class="summary">' +
             '  <td colspan="5" />' +
             '  <td class="length" />' +
             '  <td class="aggr-length" />' +
             '</tr>'
          );
}

function getTableSummaryTr(table) {
  var tr = table.find('tr.summary')[0];
  return $(tr);
}

function clearTable(table) {
  table.find('tbody tr').remove();
  table.append(buildNewTableSummaryRow());
}

function addTrackPreviewHandling(tr) {
  if (!tr.hasClass('track')) {
    return;
  }

  const static_text = '&#9835;';
  const playing_text = '&#9836;';
  const stop_text = static_text;
  var preview_url = tr.find('input[name=preview_url]').val().trim();
  if (preview_url.length == 0) {
    return;
  }

  var link = $('<a href="#">' + static_text + '</a>');
  link.click(
    function(e) {
      playPreview($(this), preview_url, playing_text, stop_text);
      e.stopPropagation(); // Prevent row selection
    }
  );
  tr.find('td.title div.name').append(link);
}

function buildNewTableTrackTrFromTrackObject(track) {
  var tr = buildNewTableTrackTr();
  if ('trackId' in track) {
    tr.find('td.title').append(formatTrackTitleAsHtml(track.artists, track.name));
    tr.find('input[name=track_id]').prop('value', track.trackId);
    tr.find('input[name=artists]').prop('value', track.artists.join(','));
    tr.find('input[name=name]').prop('value', track.name);
    tr.find('input[name=preview_url]').prop('value', track.previewUrl);
    tr.find('input[name=length_ms]').prop('value', track.length);
    tr.find('input[name=bpm]').prop('value', track.bpm);
    tr.find('input[name=genres_by_others]')
      .prop('value', track.genre.by_others.join(','));
    tr.find('textarea[name=comments]').text(track.comments);
    tr.find('td.length').text(formatTrackLength(track.length));

    // Genre
    var genre_select = tr.find('select[name=genre]');
    var genres_by_others = uniq(track.genre.by_others);
    if (track.genre.by_user != 0) {
      genre_select.find('option[value=' + track.genre.by_user + ']')
        .prop('selected', true);
    }
    else if (genres_by_others.length > 0) {
      genre_select.find('option[value=' + genres_by_others[0] + ']')
        .prop('selected', true);
      if (track.genre.by_user == 0) {
        genre_select.addClass('chosen-by-others');
      }
    }

    addTrackPreviewHandling(tr);
    addTrackBpmHandling(tr);
    addTrackGenreHandling(tr);
    addTrackCommentsHandling(tr);
  }
  else {
    tr.removeClass('track').addClass('empty-track');
    tr.find('td.title').text(track.name);
    tr.find('input[name=track_id]').remove();
    tr.find('input[name=preview_url]').remove();
    tr.find('input[name=length_ms]').remove();
    tr.find('input[name=genres_by_others]').remove();
    tr.find('textarea[name=comments]').remove();
    bpm_td = tr.find('input[name=bpm]').closest('td');
    bpm_td.find('input').remove();
    bpm_td.text(track.bpm);
    genre_td = tr.find('select[name=genre]').closest('td');
    genre_td.find('select').remove();
    genre_td.text(track.genre);
    tr.find('td.length').text(track.length);
  }
  addTrackTrSelectHandling(tr);
  addTrackTrDragHandling(tr);
  addTrackTrRightClickMenu(tr);
  return tr;
}

function appendTracks(table, tracks) {
  for (var i = 0; i < tracks.length; i++) {
    var new_tr = buildNewTableTrackTrFromTrackObject(tracks[i]);
    table.append(new_tr);
    renderTrackBpm(new_tr);
    renderTrackComments(new_tr);
  }
  table.append(getTableSummaryTr(table)); // Move summary to last
}

function replaceTracks(table, tracks) {
  clearTable(table);
  appendTracks(table, tracks);
}

function renderTable(table) {
  const delimiter = (table.is(getPlaylistTable())) ? PLAYLIST_DANCE_DELIMITER : 0;

  // Assign indices
  var trs = table.find('tr.track, tr.empty-track');
  for (var i = 0; i < trs.length; i++) {
    var tr = $(trs[i]);
    tr.find('td.index').text(i+1);
  }

  // Insert delimiters
  table.find('tr.delimiter').remove();
  if (delimiter > 0) {
    var num_cols = buildNewTableTrackTr(table).find('td').length;
    table
      .find('tr.track, tr.empty-track')
      .filter(':nth-child(' + delimiter + 'n)')
      .after(
        $( '<tr class="delimiter">' +
             '<td colspan="' + (num_cols-2) + '"><div /></td>' +
             '<td class="length"></td>' +
             '<td><div /></td>' +
           '</tr>'
         )
      );
  }
  var i = 0;
  var dance_length = 0;
  table.find('tr.track, tr.delimiter').each(
    function() {
      var tr = $(this);
      if (tr.hasClass('track')) {
        dance_length += parseInt(tr.find('input[name=length_ms]').val());
      }
      else if (tr.hasClass('delimiter')) {
        tr.find('td.length').text(formatTrackLength(dance_length));
        dance_length = 0;
      }
    }
  );

  // Compute total length
  let total_length = 0;
  table.find('tr.track').each(
    function() {
      let tr = $(this);
      total_length += parseInt(tr.find('input[name=length_ms]').val());
      tr.find('td.aggr-length').text(formatTrackLength(total_length));
    }
  );
  getTableSummaryTr(table).find('td.length').text(formatTrackLength(total_length));

  if (table.is(getPlaylistTable())) {
    renderBpmOverview();
    setPlaylistHeight();
  }
}

function renderPlaylist() {
  renderTable(getPlaylistTable());
}

function renderScratchpad() {
  renderTable(getScratchpadTable());
}

function formatTrackTitleAsText(artists, name) {
  return artists.join(', ') + ' - ' + name;
}

function formatTrackTitleAsHtml(artists, name) {
  return $( '<div class="title">' +
              '<div class="name">' + name + '</div>' +
              '<div class="artists">' + artists.join(', ') + '</div>' +
            '</div>'
          );
}

function formatTrackLength(ms) {
  var t = Math.trunc(ms / 1000);
  t = [0, 0, t];
  for (var i = t.length - 2; i >= 0; i--) {
    if (t[i+1] < 60) break;
    t[i] = Math.floor(t[i+1] / 60);
    t[i+1] = t[i+1] % 60;
  }

  if (t[0] == 0) t.shift();
  for (var i = 1; i < t.length; i++) {
    if (t[i] < 10) t[i] = '0' + t[i].toString();
  }

  return t.join(':');
}

function setDanceDelimiter(d) {
  PLAYLIST_DANCE_DELIMITER = d;
}

function isUsingDanceDelimiter() {
  return PLAYLIST_DANCE_DELIMITER > 0;
}

function clearTrackTrSelection() {
  $('.playlist tr.selected').removeClass('selected');
}

function addTrackTrSelection(table, track_index) {
  let tr = $(table.find('.track, .empty-track')[track_index]);
  tr.addClass('selected');
}

function removeTrackTrSelection(table, track_index) {
  let tr = $(table.find('.track, .empty-track')[track_index]);
  tr.removeClass('selected');
}

function toggleTrackTrSelection(table, track_index) {
  let tr = $(table.find('.track, .empty-track')[track_index]);
  tr.toggleClass('selected');
}

function getSelectedTrackTrs() {
  return $('.playlist tr.selected');
}

function getTrackIndexOfTr(tr) {
  let track_trs = tr.closest('table').find('.track, .empty-track');
  if (tr.hasClass('track') || tr.hasClass('empty-track')) {
    return track_trs.index(tr);
  }
  return track_trs.length;
}

function updateTrackTrSelection(tr, multi_select_mode, span_mode) {
  function isTrInPlaylistTable(tr) {
    return tr.closest('table').is(getPlaylistTable());
  }

  // Remove active selection in other playlist areas
  $('.playlist').each(
    function() {
      var p = $(this);
      if (p.is(tr.closest('.playlist'))) {
        return;
      }
      p.find('tr.selected').removeClass('selected');

      if (p.find('table').is(getPlaylistTable())) {
        clearTrackBarSelection();
      }
    }
  );

  if (multi_select_mode) {
    tr.toggleClass('selected');
    if (isTrInPlaylistTable(tr)) {
      toggleTrackBarSelection(getTrackIndexOfTr(tr));
    }
    return;
  }

  if (span_mode) {
    var selected_sib_trs =
      tr.siblings().filter(function() { return $(this).hasClass('selected') });
    if (selected_sib_trs.length == 0) {
      tr.addClass('selected');
      if (isTrInPlaylistTable(tr)) {
        addTrackBarSelection(getTrackIndexOfTr(tr));
      }
      return;
    }
    var first = $(selected_sib_trs[0]);
    var last = $(selected_sib_trs[selected_sib_trs.length-1]);
    var trs = tr.siblings().add(tr);
    for ( var i = Math.min(tr.index(), first.index(), last.index())
        ; i <= Math.max(tr.index(), first.index(), last.index())
        ; i++
        )
    {
      let sib_tr = $(trs[i]);
      if (sib_tr.hasClass('track') || sib_tr.hasClass('empty-track')) {
        sib_tr.addClass('selected');
        if (isTrInPlaylistTable(sib_tr)) {
          addTrackBarSelection(getTrackIndexOfTr(sib_tr));
        }
      }
    }
    return;
  }

  var selected_sib_trs =
    tr.siblings().filter(function() { return $(this).hasClass('selected') });
  $.each( selected_sib_trs
        , function() {
            let tr = $(this);
            tr.removeClass('selected');
            if (isTrInPlaylistTable(tr)) {
              removeTrackBarSelection(getTrackIndexOfTr(tr));
            }
          }
        );
  if (selected_sib_trs.length > 0) {
    tr.addClass('selected');
    if (isTrInPlaylistTable(tr)) {
      addTrackBarSelection(getTrackIndexOfTr(tr));
    }
    return;
  }

  tr.toggleClass('selected');
  if (isTrInPlaylistTable(tr)) {
    toggleTrackBarSelection(getTrackIndexOfTr(tr));
  }
}

function togglePlaylistTrackTrSelection(track_index) {
  let table = getPlaylistTable();
  let track_trs = table.find('.track, .empty-track');
  let tr = $(track_trs[track_index]);
  tr.toggleClass('selected');
}

function addTrackTrSelectHandling(tr) {
  tr.click(
    function(e) {
      if (TRACK_DRAG_STATE == 0) {
        updateTrackTrSelection($(this), e.ctrlKey || e.metaKey, e.shiftKey);
      }
      else {
        TRACK_DRAG_STATE = 0;
      }
    }
  );
}

function addTrackTrDragHandling(tr) {
  tr.mousedown(
    function(e) {
      let mousedown_tr = $(e.target).closest('tr');
      if (!mousedown_tr.hasClass('selected')) {
        return;
      }
      let body = $(document.body);
      body.addClass('grabbed');

      let selected_trs =
        mousedown_tr.siblings().add(mousedown_tr).filter(
          function() { return $(this).hasClass('selected') }
        );
      let mb = $('.grabbed-info-block');
      mb.find('span').text(selected_trs.length);

      $('.playlist').toggleClass('drag-mode');

      let ins_point = $('.tr-drag-insertion-point');

      function move(e) {
        function clearInsertionPoint() {
          $('.playlist tr.insert-before, .playlist tr.insert-after')
            .removeClass('insert-before insert-after');
        }

        // Move info block
        const of = 5; // To prevent grabbed-info-block to appear as target
        mb.css({ top: e.pageY+of + 'px', left: e.pageX+of + 'px' });
        mb.show();

        TRACK_DRAG_STATE = 1; // tr.click() and mouseup may both reset this.
                              // This is to prevent deselection if drag stops on
                              // selected tracks

        // Check if moving over insertion-point bar (to prevent flickering)
        if ($(e.target).hasClass('tr-drag-insertion-point')) {
          return;
        }

        // Hide insertion-point bar if we are not over a playlist
        if ($(e.target).closest('.playlist').length == 0) {
          ins_point.hide();
          clearInsertionPoint();
          return;
        }

        let tr = $(e.target).closest('tr');
        let insert_before = false;
        if (tr.length == 1) {
          // We have moved over a table row
          // If moved over empty-track tr, mark entire tr as insertion point
          if (tr.hasClass('empty-track')) {
            clearInsertionPoint();
            tr.addClass('insert-before');
            ins_point.hide();
            return;
          }

          // If moving over a delimiter
          if (tr.hasClass('delimiter')) {
            // Leave everything as is
            return;
          }

          // If moving over table head, move insertion point to next visible
          // tbody tr
          if (tr.closest('thead').length > 0) {
            tr = tr.closest('table').find('tbody tr').first();
            while (!tr.is(':visible')) {
              tr = tr.next();
            }
          }

          let tr_y_half = e.pageY - tr.offset().top - (tr.height() / 2);
          insert_before = tr_y_half <= 0 || tr.hasClass('summary');
        }
        else {
          // We have moved over the table but outside of any rows
          // Find summary row
          tr = $(e.target).closest('.table-wrapper').find('tr.summary');
          if (tr.length == 0) {
            clearInsertionPoint();
            ins_point.hide();
            return;
          }

          // Check that we are underneath the summary row; otherwise do nothing
          if (e.pageY < tr.offset().top) {
            clearInsertionPoint();
            ins_point.hide();
            return;
          }

          insert_before = true;
        }

        // Mark insertion point and draw insertion-point bar
        clearInsertionPoint();
        tr.addClass(insert_before ? 'insert-before' : 'insert-after');
        ins_point.css( { width: tr.width() + 'px'
                       , left: tr.offset().left + 'px'
                       , top: ( tr.offset().top +
                                (insert_before ? 0 : tr.height()) -
                                ins_point.height()/2
                              ) + 'px'
                       }
                     );
        ins_point.show();
      }

      function up() {
        let tr_insert_point =
          $('.playlist tr.insert-before, .playlist tr.insert-after');
        if (tr_insert_point.length == 1) {
          // Forbid dropping adjacent to a selected track as that causes wierd
          // reordering
          let dropped_adjacent_to_selected =
            tr_insert_point.hasClass('selected') ||
            ( tr_insert_point.hasClass('insert-before') &&
              tr_insert_point.prevAll('.track, .empty-track')
                             .first()
                             .hasClass('selected')
            ) ||
            ( tr_insert_point.hasClass('insert-after') &&
              tr_insert_point.nextAll('.track, .empty-track')
                             .first()
                             .hasClass('selected')
            );
          if (!dropped_adjacent_to_selected) {
            let table = tr_insert_point.closest('table');
            let ins_track_index = getTrackIndexOfTr(tr_insert_point);
            if (tr_insert_point.hasClass('insert-after')) {
              ins_track_index++;
            }
            moveSelectedTracksTo(table, ins_track_index);
          }
          tr_insert_point.removeClass('insert-before insert-after');
        }

        // Remove info block and insertion-point bar
        mb.hide();
        body.removeClass('grabbed');
        ins_point.hide();

        $('.playlist').toggleClass('drag-mode');

        if (!tr_insert_point.is(tr)) {
          TRACK_DRAG_STATE = 0;
        }

        $(document).unbind('mousemove', move).unbind('mouseup', up);
      }

      $(document).mousemove(move).mouseup(up);
    }
  );
}

function moveSelectedTracksTo(table, track_index) {
  let selected_trs = getSelectedTrackTrs();
  let trs = table.find('.track, .empty-track');
  if (track_index < trs.length) {
    let tr_insert_point = $(trs[track_index]);
    insertPlaceholdersBeforeMovingTrackTrs(selected_trs);
    tr_insert_point.before(selected_trs);
    if (tr_insert_point.hasClass('empty-track')) {
      tr_insert_point.remove();
    }
  }
  else {
    let tr_insert_point = $(trs[trs.length-1]);
    insertPlaceholdersBeforeMovingTrackTrs(selected_trs);
    tr_insert_point.after(selected_trs);
  }

  renderPlaylist();
  renderScratchpad();
  indicateStateUpdate();
}

function insertPlaceholdersBeforeMovingTrackTrs(selected_trs) {
  let source_table = getTableOfTr($(selected_trs[0]));
  if (isUsingDanceDelimiter() && source_table.is(getPlaylistTable())) {
    // Ignore tracks that covers an entire dance block
    rows_to_keep = [];
    function isTrackRow(tr) {
      return tr.hasClass('track') || tr.hasClass('empty-track');
    }
    for (let i = 0; i < selected_trs.length; i++) {
      let tr = $(selected_trs[i]);
      if (!isTrackRow(tr.prev())) {
        let skip = false;
        let j = i;
        do {
          let next_tr = tr.next();
          if (!isTrackRow(next_tr)) {
            skip = true;
            break;
          }
          j++;
          if (j == selected_trs.length) {
            break;
          }
          tr = $(selected_trs[j]);
          if (!tr.is(next_tr)) {
            break;
          }
        } while (true);
        if (skip) {
          i = j;
          continue;
        }
      }
      rows_to_keep.push(tr);
    }
    for (let i = 0; i < rows_to_keep.length; i++) {
      let old_tr = $(rows_to_keep[i]);
      if (!old_tr.hasClass('empty-track')) {
        let o = createPlaylistPlaceholderObject();
        let new_tr = buildNewTableTrackTrFromTrackObject(o);
        old_tr.before(new_tr);
      }
    }
  }
}

function deleteSelectedTrackTrs() {
  let trs = getSelectedTrackTrs();
  if (trs.length == 0) {
    return;
  }

  let t = getTableOfTr($(trs[0]));
  let is_playlist = t.is(getPlaylistTable());
  insertPlaceholdersBeforeMovingTrackTrs(trs);
  trs.remove();
  if (is_playlist) {
    renderPlaylist();
  }
  else {
    renderScratchpad();
  }
  indicateStateUpdate();
}

function addTrackTrRightClickMenu(tr) {
  function buildMenu(menu, clicked_tr, close_f) {
    function buildPlaceholderTr() {
      let o = createPlaylistPlaceholderObject();
      return buildNewTableTrackTrFromTrackObject(o);
    }
    const actions =
      [ [ '<?= LNG_MENU_SELECT_IDENTICAL_TRACKS ?>'
        , function() {
            clicked_tid_input = clicked_tr.find('input[name=track_id]');
            if (clicked_tid_input.length == 0) {
              return;
            }
            let clicked_tid = clicked_tid_input.val().trim();
            getTableOfTr(clicked_tr).find('tr').each(
              function() {
                let tr = $(this);
                let tr_tid_input = tr.find('input[name=track_id]');
                if (tr_tid_input.length == 0) {
                  return;
                }
                let tr_tid = tr_tid_input.val().trim();
                if (tr_tid == clicked_tid) {
                  tr.addClass('selected');
                }
              }
            );
            close_f();
          }
        , function(a) {
            clicked_tid_input = clicked_tr.find('input[name=track_id]');
            if (clicked_tid_input.length == 0) {
              a.addClass('disabled');
            }
          }
        ]
      , [ '<?= LNG_MENU_INSERT_PLACEHOLDER_BEFORE ?>'
        , function() {
            let new_tr = buildPlaceholderTr();
            clicked_tr.before(new_tr);
            renderTable(getTableOfTr(clicked_tr));
            indicateStateUpdate();
            close_f();
          }
        , function(a) {}
        ]
      , [ '<?= LNG_MENU_INSERT_PLACEHOLDER_AFTER ?>'
        , function() {
            let new_tr = buildPlaceholderTr();
            clicked_tr.after(new_tr);
            renderTable(getTableOfTr(clicked_tr));
            indicateStateUpdate();
            close_f();
          }
        , function(a) {}
        ]
      , [ '<?= LNG_MENU_SHOW_PLAYLISTS_WITH_TRACK ?>'
        , function() {
            clicked_tid_input = clicked_tr.find('input[name=track_id]');
            if (clicked_tid_input.length == 0) {
              return;
            }
            let clicked_tid = clicked_tid_input.val().trim();
            let clicked_title = getTrTitleText(clicked_tr);
            showPlaylistsWithTrack(clicked_tid, clicked_title);
            close_f();
          }
        , function(a) {
            clicked_tid_input = clicked_tr.find('input[name=track_id]');
            if (clicked_tid_input.length == 0) {
              a.addClass('disabled');
            }
          }
        ]
      , [ '<?= LNG_MENU_DELETE_SELECTED ?>'
        , function() {
            deleteSelectedTrackTrs();
            close_f();
          }
        , function(a) {
            let trs = getSelectedTrackTrs();
            if (trs.length == 0) {
              a.addClass('disabled');
            }
          }
        ]
      ];
    menu.empty();
    for (let i = 0; i < actions.length; i++) {
      let a = $('<a href="#" />');
      a.text(actions[i][0]);
      a.click(actions[i][1]);
      actions[i][2](a);
      menu.append(a);
    }
  }

  tr.bind(
    'contextmenu'
  , function(e) {
      function close() {
        tr.removeClass('right-clicked');
        menu.hide();
      }
      tr.addClass('right-clicked');
      let menu = $('.mouse-menu');
      buildMenu(menu, tr, close);
      menu.css({ top: e.pageY + 'px', left: e.pageX + 'px' });
      menu.show();

      function hide(e) {
        if ($(e.target).closest('.mouse-menu').length == 0) {
          close();
          $(document).unbind('mousedown', hide);
        }
      }
      $(document).mousedown(hide);

      // Prevent browser right-click menu from appearing
      e.preventDefault();
      return false;
    }
  );
}

function savePlaylistSnapshot() {
  setStatus('<?= LNG_DESC_SAVING ?>...');

  function getTrackId(t) {
    if (t.trackId === undefined) {
      return '';
    }
    return t.trackId;
  }
  playlist_tracks = getPlaylistTrackData().map(getTrackId);
  scratchpad_tracks = getScratchpadTrackData().map(getTrackId);
  data = { playlistId: PLAYLIST_ID
         , snapshot: { playlistData: playlist_tracks
                     , scratchpadData: scratchpad_tracks
                     , delimiter: PLAYLIST_DANCE_DELIMITER
                     , spotifyPlaylistHash: LAST_SPOTIFY_PLAYLIST_HASH
                     }
         };
  callApi( '/api/save-playlist-snapshot/'
         , data
         , function(d) {
             clearStatus();
           }
         , function(msg) {
             setStatus('<?= LNG_ERR_FAILED_TO_SAVE ?>', true);
           }
         );
}

function loadPlaylistFromSnapshot(playlist_id, success_f, no_snap_f, fail_f) {
  let status = [false, false];
  function done(table, status_offset) {
    status[status_offset] = true;
    renderTable(table);
    if (status.every(x => x)) {
      success_f();
    }
  }
  function load(table, status_offset, track_ids, track_offset) {
    function hasTrackAt(o) {
      return track_ids[o].length > 0;
    }

    if (track_offset >= track_ids.length) {
      done(table, status_offset);
      return;
    }
    if (hasTrackAt(track_offset)) {
      // Currently at a track entry; add tracks until next placeholder entry
      let tracks_to_load = [];
      let o = track_offset;
      for ( ; o < track_ids.length &&
              hasTrackAt(o) &&
              tracks_to_load.length < LOAD_TRACKS_LIMIT
            ; o++
          )
      {
        tracks_to_load.push(track_ids[o]);
      }
      callApi( '/api/get-track-info/'
             , { trackIds: tracks_to_load }
             , function(d) {
                 let tracks = [];
                 for (let i = 0; i < d.tracks.length; i++) {
                   let t = d.tracks[i];
                   let obj = createPlaylistTrackObject( t.trackId
                                                      , t.artists
                                                      , t.name
                                                      , t.length
                                                      , t.bpm
                                                      , t.genre.by_user
                                                      , t.genre.by_others
                                                      , t.comments
                                                      , t.preview_url
                                                      );
                   tracks.push(obj);
                 }
                 appendTracks(table, tracks);
                 load(table, status_offset, track_ids, o);
               }
             , fail_f
             );
    }
    else {
      // Currently at a placeholder entry; add such until next track entry
      let placeholders = [];
      let o = track_offset;
      for (; o < track_ids.length && !hasTrackAt(o); o++) {
        placeholders.push(createPlaylistPlaceholderObject());
      }
      appendTracks(table, placeholders);
      load(table, status_offset, track_ids, o);
    }
  }
  callApi( '/api/get-playlist-snapshot/'
         , { playlistId: playlist_id }
         , function(res) {
             if (res.status == 'OK') {
               PLAYLIST_DANCE_DELIMITER = res.snapshot.delimiter;
               LAST_SPOTIFY_PLAYLIST_HASH = res.snapshot.spotifyPlaylistHash;
               if (PLAYLIST_DANCE_DELIMITER > 0) {
                 setDelimiterAsShowing();
               }
               load(getPlaylistTable(), 0, res.snapshot.playlistData, 0);
               load(getScratchpadTable(), 1, res.snapshot.scratchpadData, 0);
               if (res.snapshot.scratchpadData.length > 0) {
                 showScratchpad();
               }
             }
             else if (res.status == 'NOT-FOUND') {
               no_snap_f();
             }
           }
         , fail_f
         );
}

function indicateStateUpdate() {
  saveUndoState();
  savePlaylistSnapshot();
}

function saveUndoState() {
  const limit = UNDO_STACK_LIMIT;

  // Find slot to save state
  if (UNDO_STACK_OFFSET+1 == limit) {
    // Remove first and shift all states
    for (let i = 1; i < limit; i++) {
      UNDO_STACK[i-1] = UNDO_STACK[i];
    }
  }
  else {
    UNDO_STACK_OFFSET++;
  }
  const offset = UNDO_STACK_OFFSET;

  // Destroy obsolete redo states
  for (let o = offset; o < limit; o++) {
    if (UNDO_STACK[o] !== null) {
      UNDO_STACK[o] = null;
    }
  }

  let playlist = getPlaylistTable().clone(true, true);
  playlist.find('tr.selected').removeClass('selected');
  playlist.find('tr.delimiter').remove();
  let scratchpad = getScratchpadTable().clone(true, true);
  scratchpad.find('tr.selected').removeClass('selected');
  scratchpad.find('tr.delimiter').remove();
  let state = { playlistTracks: getPlaylistTrackData()
              , scratchpadTracks: getScratchpadTrackData()
              , callback: function() {}
              };
  UNDO_STACK[offset] = state;

  renderUndoRedoButtons();
}

function setCurrentUndoStateCallback(callback_f) {
  if (UNDO_STACK_OFFSET < 0 || UNDO_STACK_OFFSET >= UNDO_STACK_LIMIT) {
    console.log('illegal undo-stack offset value: ' + UNDO_STACK_OFFSET);
    return;
  }

  UNDO_STACK[UNDO_STACK_OFFSET].callback = callback_f;
}

function performUndo() {
  if (UNDO_STACK_OFFSET <= 0) {
    return;
  }

  const offset = --UNDO_STACK_OFFSET;
  let state = UNDO_STACK[offset];
  restoreState(state);
  state.callback();
  renderUndoRedoButtons();
}

function performRedo() {
  if ( UNDO_STACK_OFFSET+1 == UNDO_STACK_LIMIT ||
       UNDO_STACK[UNDO_STACK_OFFSET+1] === null
     )
  {
    return;
  }

  const offset = ++UNDO_STACK_OFFSET;
  let state = UNDO_STACK[offset];
  restoreState(state);
  state.callback();
  renderUndoRedoButtons();
}

function restoreState(state) {
  replaceTracks(getPlaylistTable(), state.playlistTracks);
  replaceTracks(getScratchpadTable(), state.scratchpadTracks);
  renderPlaylist();
  renderScratchpad();
  savePlaylistSnapshot();
}

function renderUndoRedoButtons() {
  const offset = UNDO_STACK_OFFSET;
  let undo_b = $('#undoBtn');
  let redo_b = $('#redoBtn');
  if (offset > 0) {
    undo_b.removeClass('disabled');
  }
  else {
    undo_b.addClass('disabled');
  }
  if (offset+1 < UNDO_STACK_LIMIT && UNDO_STACK[offset+1] !== null) {
    redo_b.removeClass('disabled');
  }
  else {
    redo_b.addClass('disabled');
  }
}

function showPlaylistsWithTrack(tid, title) {
  let action_area = $('.action-input-area[name=show-playlists-with-track]');
  function setTableHeight() {
    let search_results_area = action_area.find('.search-results');
    let search_area_bottom =
      search_results_area.offset().top + search_results_area.height();
    let table = search_results_area.find('.table-wrapper');
    let table_top = table.offset().top;
    let table_height = search_area_bottom - table_top;
    table.css('height', table_height + 'px');
  }
  action_area.find('p').text(title);
  action_area.find('button.cancel').on('click', close);
  let search_results_area = action_area.find('table tbody');
  search_results_area.empty();
  clearProgress();
  action_area.show();
  setTableHeight();

  let body = $(document.body);
  body.addClass('loading');

  let cancel_loading = false;
  function finalize() {
    body.removeClass('loading');
  }
  function close() {
    cancel_loading = true;
    clearActionInputs();
    finalize();
  }
  function loadDone() {
    loads_finished++; // Protection not needed for browser JS
    renderProgress();
    if (loads_finished == total_loads) {
      finalize();
    }
  }
  function loadFail(msg) {
    cancel_loading = true;
    finalize();
  }

  let total_loads = 0;
  let loads_finished = 0;
  function clearProgress() {
    let bar = action_area.find('.progress-bar');
    bar.css('width', 0);
  }
  function initProgress(total) {
    total_loads = total;
    let bar = action_area.find('.progress-bar');
    bar.css('width', 0);
  }
  function hasInitProgress() {
    return total_loads > 0;
  }
  function renderProgress() {
    let bar = action_area.find('.progress-bar');
    bar.css('width', (loads_finished / total_loads)*100 + '%');
  }

  function loadPlaylists(offset) {
    if (cancel_loading) {
      return;
    }
    callApi( '/api/get-user-playlists/'
           , { userId: '<?= getThisUserId($api) ?>'
             , offset: offset
             }
           , function(d) {
               if (!hasInitProgress()) {
                 initProgress(d.total);
               }

               for (let i = 0; i < d.playlists.length; i++) {
                 if (cancel_loading) {
                   return;
                 }
                 let pid = d.playlists[i].id;
                 let pname = d.playlists[i].name;
                 if (pid != PLAYLIST_ID) {
                   loadPlaylistTracks(pid, pname, 0);
                 }
                 else {
                   loadDone();
                 }
               }
               offset += d.playlists.length;
               if (offset == d.total) {
                 return;
               }
               loadPlaylists(offset);
             }
           , loadFail
           );
  }
  function loadPlaylistTracks(playlist_id, playlist_name, offset) {
    if (cancel_loading) {
      return;
    }
    callApi( '/api/get-playlist-tracks/'
           , { playlistId: playlist_id
             , offset: offset
             }
           , function(d) {
               for (let i = 0; i < d.tracks.length; i++) {
                 if (cancel_loading) {
                   return;
                 }
                 if (d.tracks[i] == tid) {
                   search_results_area.append(
                     '<tr>' +
                       '<td>' +
                         playlist_name +
                       '</td>' +
                     '</tr>'
                   );
                   loadDone();
                   return;
                 }
               }

               offset += d.tracks.length;
               if (offset == d.total) {
                 loadDone();
                 return;
               }
               loadPlaylistTracks(playlist_id, playlist_name, offset);
             }
           , loadFail
           );
  }
  loadPlaylists(0);
}

function setPlaylistHeight() {
  let screen_vh = window.innerHeight;
  let table_offset = $('div.playlists-wrapper div.table-wrapper').offset().top;
  let footer_vh = $('div.footer').outerHeight(true);
  let bpm_overview = $('div.bpm-overview');
  let bpm_overview_vh =
    bpm_overview.is(':visible') ? bpm_overview.outerHeight(true) : 0;
  let playlist_vh = screen_vh - table_offset - footer_vh - bpm_overview_vh;
  let playlist_px = playlist_vh + 'px';
  getPlaylistTable().closest('.table-wrapper').css('height', playlist_px);
  getScratchpadTable().closest('.table-wrapper').css('height', playlist_px);
}

function getTrackBarArea() {
  return $('div.bpm-overview .bar-area');
}

function isBpmOverviewShowing() {
  return $('div.bpm-overview').is(':visible');
}

function renderBpmOverview() {
  if (!isBpmOverviewShowing()) {
    return;
  }

  let area = getTrackBarArea();
  area.empty();

  let selected_track_indices = [];
  getSelectedTrackTrs().each(
    function() {
      let tr = $(this);
      if (!tr.closest('table').is(getPlaylistTable())) {
        return;
      }
      selected_track_indices.push(getTrackIndexOfTr(tr));
    }
  );

  // Draw bars
  let tracks = getPlaylistTrackData();
  let area_vw = area.innerWidth();
  let area_vh = area.innerHeight();
  const border_size = 1;
  let bar_vw = (area_vw - border_size) / tracks.length - border_size;
  let bar_voffset = 0;
  $.each(
    tracks
  , function(track_index) {
      let t = this;
      let bar_wrapper = $('<div class="bar-wrapper" />');
      bar_wrapper.css('left', bar_voffset + 'px');
      bar_wrapper.css('width', (bar_vw + 2*border_size) + 'px');
      let bar = $('<div class="bar" />');
      let bar_vh = (t.bpm - BPM_MIN) / BPM_MAX * area_vh;
      bar.css('height', bar_vh + 'px');
      bar.css('width', bar_vw + 'px');
      let cs = getBpmRgbColor(t.bpm);
      bar.css('background-color', 'rgb(' + cs.join(',') + ')');
      bar_voffset += bar_vw + border_size;
      bar_wrapper.append(bar);
      area.append(bar_wrapper);

      if ( isUsingDanceDelimiter() &&
           (track_index+1) % PLAYLIST_DANCE_DELIMITER == 0 &&
           track_index != 0 && track_index != tracks.length-1
         )
      {
        let delimiter = $('<div class="delimiter" />');
        delimiter.css('height', area_vh + 'px');
        delimiter.css('left', bar_voffset + 'px');
        area.append(delimiter);
      }

      let title = t.artists !== undefined ? formatTrackTitleAsText( t.artists
                                                                  , t.name
                                                                  )
                                          : t.name;
      let track_info = $( '<div class="bpm-overview-track-info">' +
                            '#' + (track_index+1) + ' ' + title +
                            '<br />' +
                            'BPM: ' + t.bpm +
                          '</div>'
                        );
      if (selected_track_indices.includes(track_index)) {
        bar_wrapper.addClass('selected');
      }
      area.append(track_info);
      bar.hover(
        function() {
          let bar = $(this);
          if (bar.closest('.drag-mode').length == 0) {
            let new_cs = rgbVisIncr(cs, 40);
            bar.css('background-color', 'rgb(' + new_cs.join(',') + ')');
          }

          let bar_wrapper = bar.closest('.bar-wrapper');
          let bar_wrapper_of = bar_wrapper.position();
          let bar_of = bar.position();
          let info_vh = track_info.outerHeight();
          let info_top_of = bar_of.top - info_vh;
          track_info.css('top', info_top_of + 'px');
          let info_vw = track_info.outerWidth();
          if (bar_wrapper_of.left + info_vw <= area_vw) {
            track_info.css('left', bar_wrapper_of.left + 'px');
          }
          else {
            track_info.css('left', (area_vw - info_vw) + 'px');
          }
          track_info.show();
        }
      , function() {
          bar.css('background-color', 'rgb(' + cs.join(',') + ')');
          track_info.hide();
        }
      );
      addTrackBarSelectHandling(bar);
      addTrackBarDragHandling(bar);
    }
  );

  // Compute and show stats
  tracks = tracks.filter(function(t) { return t.bpm !== undefined && t.bpm > 0 });
  tracks.sort(function(a, b) { return intcmp(a.bpm, b.bpm); });
  let bpm_min = 0;
  let bpm_max = 0;
  let bpm_median = 0;
  let bpm_average = 0;
  if (tracks.length > 0) {
    bpm_min = tracks[0].bpm;
    bpm_max = tracks[tracks.length-1].bpm;
    let i = Math.floor(tracks.length / 2);
    bpm_median = tracks.length % 2 == 1 ? tracks[i+1].bpm : tracks[i].bpm;
    bpm_average =
      Math.round( tracks.reduce(function(a, t) { return a + t.bpm; }, 0) /
                  tracks.length
                );
  }
  let stats = $('div.bpm-overview .stats');
  stats.text( '<?= LNG_DESC_BPM_MIN ?>: ' + bpm_min + ' ' +
              '<?= LNG_DESC_BPM_MAX ?>: ' + bpm_max + ' ' +
              '<?= LNG_DESC_BPM_MEDIAN ?>: ' + bpm_median + ' ' +
              '<?= LNG_DESC_BPM_AVERAGE ?>: ' + bpm_average
            );
  stats.show();
}

function addTrackBarSelectHandling(bar) {
  bar.click(
    function(e) {
      if (TRACK_DRAG_STATE == 0) {
        let bar_wr = $(this).closest('.bar-wrapper');
        updateTrackBarSelection(bar_wr, e.ctrlKey || e.metaKey, e.shiftKey);
      }
      else {
        TRACK_DRAG_STATE = 0;
      }
    }
  );
}

function addTrackBarSelection(track_index) {
  if (!isBpmOverviewShowing()) {
    return;
  }

  let area = getTrackBarArea();
  let bar_wrappers = area.find('.bar-wrapper');
  let bar_wr = $(bar_wrappers[track_index]);
  bar_wr.addClass('selected');
}

function removeTrackBarSelection(track_index) {
  if (!isBpmOverviewShowing()) {
    return;
  }

  let area = getTrackBarArea();
  let bar_wrappers = area.find('.bar-wrapper');
  let bar_wr = $(bar_wrappers[track_index]);
  bar_wr.removeClass('selected');
}

function toggleTrackBarSelection(track_index) {
  if (!isBpmOverviewShowing()) {
    return;
  }

  let area = getTrackBarArea();
  let bar_wrappers = area.find('.bar-wrapper');
  let bar_wr = $(bar_wrappers[track_index]);
  bar_wr.toggleClass('selected');
}

function clearTrackBarSelection() {
  if (!isBpmOverviewShowing()) {
    return;
  }

  let area = getTrackBarArea();
  area.find('.bar-wrapper').removeClass('selected');
}

function getTrackIndexOfBarWrapper(bar_wr) {
  return bar_wr.closest('.bar-area').find('.bar-wrapper').index(bar_wr);
}

function updateTrackBarSelection(bar_wr, multi_select_mode, span_mode) {
  if (multi_select_mode) {
    bar_wr.toggleClass('selected');
    toggleTrackTrSelection(getPlaylistTable(), getTrackIndexOfBarWrapper(bar_wr));
    return;
  }

  if (span_mode) {
    let selected_sib_bar_wrappers =
      bar_wr.siblings().filter(
        function() { return $(this).hasClass('selected') }
      );
    if (selected_sib_bar_wrappers.length == 0) {
      bar_wr.addClass('selected');
      addTrackTrSelection(getPlaylistTable(), getTrackIndexOfBarWrapper(bar_wr));
      return;
    }
    let first = $(selected_sib_bar_wrappers[0]);
    let last = $(selected_sib_bar_wrappers[selected_sib_bar_wrappers.length-1]);
    let bar_wrappers = bar_wr.siblings().add(bar_wr);
    for ( let i = Math.min(bar_wr.index(), first.index(), last.index())
        ; i <= Math.max(bar_wr.index(), first.index(), last.index())
        ; i++
        )
    {
      let sib_bar_wr = $(bar_wrappers[i]);
      if (sib_bar_wr.hasClass('bar-wrapper')) {
        sib_bar_wr.addClass('selected');
        addTrackTrSelection( getPlaylistTable()
                           , getTrackIndexOfBarWrapper(sib_bar_wr)
                           );
      }
    }
    return;
  }

  let selected_sib_bar_wrappers =
    bar_wr.siblings().filter(function() { return $(this).hasClass('selected') });
  $.each( selected_sib_bar_wrappers
        , function() {
            let bar_wr = $(this);
            bar_wr.removeClass('selected');
            removeTrackTrSelection( getPlaylistTable()
                                  , getTrackIndexOfBarWrapper(bar_wr)
                                  );
          }
        );
  if (selected_sib_bar_wrappers.length > 0) {
    bar_wr.addClass('selected');
    addTrackTrSelection(getPlaylistTable(), getTrackIndexOfBarWrapper(bar_wr));
    return;
  }

  bar_wr.toggleClass('selected');
  toggleTrackTrSelection(getPlaylistTable(), getTrackIndexOfBarWrapper(bar_wr));
}

function addTrackBarDragHandling(bar) {
  function disableTextSelection(e) {
    e.preventDefault();
  }

  bar.mousedown(
    function(e) {
      let mousedown_bar = $(e.target).closest('.bar');
      let mousedown_bar_wr = mousedown_bar.closest('.bar-wrapper');
      if (!mousedown_bar_wr.hasClass('selected')) {
        return;
      }
      let body = $(document.body);
      body.addClass('grabbed');

      let selected_bars =
        mousedown_bar_wr.siblings().add(mousedown_bar_wr).filter(
          function() { return $(this).hasClass('selected') }
        );
      let mb = $('.grabbed-info-block');
      mb.find('span').text(selected_bars.length);

      let area = mousedown_bar.closest('.bar-area');
      area.toggleClass('drag-mode');

      let ins_point = $('.bar-drag-insertion-point');

      function move(e) {
        // Move info block
        const of = 5; // To prevent grabbed-info-block to appear as target
        mb.css({ top: e.pageY+of + 'px', left: e.pageX+of + 'px' });
        mb.show();

        TRACK_DRAG_STATE = 1; // bar.click() and mouseup may both reset this.
                              // This is to prevent deselection if drag stops on
                              // selected bars

        // Check if moving over insertion-point bar (to prevent flickering)
        if ($(e.target).hasClass('bar-drag-insertion-point')) {
          return;
        }

        // Always clear previous insertion point; else we could in some cases end
        // up with multiple insertion points
        area.find('.insert-before, .insert-after')
          .removeClass('insert-before insert-after');

        // Hide insertion-point bar if we are not over area
        if ($(e.target).closest('.bar-area').length == 0) {
          ins_point.hide();
          return;
        }

        let bar_wrapper = $(e.target).closest('.bar-wrapper');
        let insert_before = false;
        if (bar_wrapper.length == 1) {
          // We have moved over a bar wrapper
          let bar_x_half =
            e.pageX - bar_wrapper.offset().left - (bar_wrapper.width() / 2);
          insert_before = bar_x_half <= 0;
        }
        else {
          // We have moved over the bar area but not over a bar.
          // Don't do anything, as we assume that an insertion point has already
          // been established from previous move
          return;
        }

        // Mark insertion point and draw insertion-point bar
        bar_wrapper.addClass(insert_before ? 'insert-before' : 'insert-after');
        ins_point.css( { height: bar_wrapper.height() + 'px'
                       , top: bar_wrapper.offset().top + 'px'
                       , left: ( bar_wrapper.offset().left +
                                 (insert_before ? 0 : bar_wrapper.width()) -
                                 ins_point.width()/2
                               ) + 'px'
                       }
                     );
        ins_point.show();
      }

      function up() {
        let area = getTrackBarArea();
        let insert_point = area.find('.insert-before, .insert-after');
        if (insert_point.length == 1) {
          // Forbid dropping adjacent to a selected track as that causes wierd
          // reordering
          let dropped_adjacent_to_selected =
            insert_point.find('.selected').length > 0 ||
            ( insert_point.hasClass('insert-before') &&
              insert_point.prevAll('.bar-wrapper')
                          .first()
                          .find('.selected')
                          .length > 0
            ) ||
            ( insert_point.hasClass('insert-after') &&
              insert_point.nextAll('.bar-wrapper')
                          .first()
                          .find('.selected')
                          .length > 0
            );
          if (!dropped_adjacent_to_selected) {
            let ins_track_index = getTrackIndexOfBarWrapper(insert_point);
            if (insert_point.hasClass('insert-after')) {
              ins_track_index++;
            }
            moveSelectedTracksTo(getPlaylistTable(), ins_track_index);
          }
          insert_point.removeClass('insert-before insert-after');
        }

        // Remove info block and insertion-point bar
        mb.hide();
        body.removeClass('grabbed');
        ins_point.hide();

        area.toggleClass('drag-mode');

        if (!insert_point.is(mousedown_bar.closest('.bar-wrapper'))) {
          TRACK_DRAG_STATE = 0;
        }

        $(document).unbind('mousemove', move).unbind('mouseup', up);
      }

      $(document).mousemove(move).mouseup(up);

      return false;
    }
  );
}
