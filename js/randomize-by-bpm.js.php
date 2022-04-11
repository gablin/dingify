<?php
require '../autoload.php';
?>

function setupRandomizeByBpm() {
  setupFormElementsForRandomizeByBpm();
}

function setupFormElementsForRandomizeByBpm() {
  var form = getPlaylistForm();
  var table = getPlaylistTable();
  var action_area = $('div[name=randomize-by-bpm]');

  // Randomize button
  var rnd_b = form.find('button[id=randomizeBtn]');
  rnd_b.click(
    function() {
      var b = $(this);
      b.prop('disabled', true);
      b.addClass('loading');
      var body = $(document.body);
      body.addClass('loading');
      function restoreButton() {
        b.prop('disabled', false);
        b.removeClass('loading');
        body.removeClass('loading');
      };

      var playlist_data = getPlaylistTrackData().concat(getScratchpadTrackData());
      playlist_data = removePlaceholdersFromTracks(playlist_data);
      var track_ids = [];
      var bpms = [];
      var genres = [];
      for (var i = 0; i < playlist_data.length; i++) {
        var track = playlist_data[i];
        track_ids.push(track.trackId)
        bpms.push(track.bpm);
        var genre = 0;
        if (track.genre.by_user != 0) {
          genre = track.genre.by_user;
        }
        else if (track.genre.by_others.length > 0) {
          genre = track.genre.by_others[0];
        }
        genres.push(genre);
      }
      var bpm_data = getBpmSettings();
      var data = { trackIdList: track_ids
                 , trackBpmList: bpms
                 , trackGenreList: genres
                 , bpmRangeList: bpm_data.bpmRangeList
                 , bpmDifferenceList: bpm_data.bpmDifferenceList
                 , danceSlotSameGenre:
                     form.find('input[name=dance-slot-has-same-genre]')
                     .prop('checked')
                 };
      callApi( '/api/randomize-by-bpm/'
             , data
             , function(d) {
                 updatePlaylistAfterRandomize( d.trackOrder
                                             , data.bpmRangeList
                                             );
                 restoreButton();
                 clearActionInputs();
               }
             , function fail(msg) {
                 alert('ERROR: <?= LNG_ERR_FAILED_TO_RANDOMIZE ?>');
                 restoreButton();
                 clearActionInputs();
               }
             );
      return false;
    }
  );

  // BPM differences
  var buildBpmDiffSlider = function(tr) {
    var printValues =
      function(v1, v2) { tr.find('td.label > span').text(v1 + ' - ' + v2); };
    tr.find('td.difference-controller > div').each(
      function() {
        if ($(this).children().length > 0) {
          $(this).empty();
        }
        $(this).slider(
          { range: true
          , min: 0
          , max: 255
          , values: [10, 40]
          , slide: function(event, ui) {
              printValues(ui.values[0], ui.values[1]);
            }
          }
        );
        printValues( $(this).slider('values', 0)
                   , $(this).slider('values', 1)
                   );
      }
    );
  };
  action_area.find('table.bpm-range-area tr.difference').each(
    function() { buildBpmDiffSlider($(this)); }
  );

  // BPM ranges and buttons
  var buildBpmRangeSlider = function(tr) {
    var printValues =
      function(v1, v2) { tr.find('td.label > span').text(v1 + ' - ' + v2); };
    tr.find('td.range-controller > div').each(
      function() {
        if ($(this).children().length > 0) {
          $(this).empty();
        }
        $(this).slider(
          { range: true
          , min: 0
          , max: 255
          , values: [0, 255]
          , slide: function(event, ui) {
              printValues(ui.values[0], ui.values[1]);
            }
          }
        );
        printValues( $(this).slider('values', 0)
                   , $(this).slider('values', 1)
                   );
      }
    );
  };
  var setupBpmRangeButtons = function(range_tr) {
    var base_range_tr = range_tr.clone();
    var diff_tr = range_tr.next().length > 0 ? range_tr.next() : range_tr.prev();
    var base_diff_tr = diff_tr.clone();

    // Add button
    var btn = range_tr.find('button.add');
    btn.click(
      function() {
        var new_range_tr = base_range_tr.clone();
        var new_diff_tr = base_diff_tr.clone();
        buildBpmRangeSlider(new_range_tr);
        buildBpmDiffSlider(new_diff_tr);
        range_tr.after(new_diff_tr);
        new_diff_tr.after(new_range_tr);
        setupBpmRangeButtons(new_range_tr);
        updateBpmRangeTrackCounters();
        enableRemoveButtons();
      }
    );

    // Remove button
    range_tr.find('button.remove').each(
      function() {
        $(this).click(
          function() {
            var range_tr = $(this).parent().parent();
            var diff_tr = range_tr.next().length > 0
                            ? range_tr.next() : range_tr.prev();
            range_tr.remove();
            diff_tr.remove();
            disableRemoveButtonsIfNeeded();
            updateBpmRangeTrackCounters();
          }
        );
      }
    );
  };
  var enableRemoveButtons = function() {
    action_area.find('table.bpm-range-area button.remove').each(
      function() {
        $(this).prop('disabled', false);
      }
    );
  };
  var disableRemoveButtonsIfNeeded = function() {
    var table = action_area.find('table.bpm-range-area');
    var num_ranges = table.find('tr.range').length;
    if (num_ranges <= 2) {
      table.find('button.remove').each(
        function() {
          $(this).prop('disabled', true);
        }
      );
    }
  };
  var updateBpmRangeTrackCounters = function() {
    action_area.find('table.bpm-range-area tr > td.track > span').each(
      function(i) {
        $(this).text(i+1);
      }
    );
  };
  action_area.find('table.bpm-range-area tr.range').each(
    function() {
      var tr = $(this);
      buildBpmRangeSlider(tr);
      setupBpmRangeButtons(tr);
      updateBpmRangeTrackCounters();
    }
  );
  disableRemoveButtonsIfNeeded();
}

function getBpmSettings() {
  var form = getPlaylistForm();
  var data = { bpmRangeList: []
             , bpmDifferenceList: []
             };
  var action_area = $('div[name=randomize-by-bpm]');

  action_area.find('table.bpm-range-area tr').each(
    function() {
      var tr = $(this);
      tr.find('td.range-controller > div').each(
        function() {
          v1 = $(this).slider('values', 0);
          v2 = $(this).slider('values', 1);
          data.bpmRangeList.push([v1, v2]);
        }
      );
      tr.find('.difference-controller > div').each(
        function() {
          v1 = $(this).slider('values', 0);
          v2 = $(this).slider('values', 1);
          var tr = $(this).closest('tr');
          var direction =
            parseInt(tr.find('select[name=direction] :selected').val());
          if (direction < 0) {
            var tmp = v1;
            v1 = -v2;
            v2 = -tmp;
          }
          data.bpmDifferenceList.push([v1, v2]);
        }
      );
    }
  );

  return data;
}

function updatePlaylistAfterRandomize(track_order, bpm_ranges, unused_tracks) {
  let playlist = getPlaylistTrackData().concat(getScratchpadTrackData());
  playlist = removePlaceholdersFromTracks(playlist);
  let new_playlist = [];
  for (let i = 0; i < track_order.length; i++) {
    let tid = track_order[i];
    if (tid.length > 0) {
      let track = getTrackWithMatchingId(playlist, tid);
      if (track == null) {
        console.log('failed to find track with ID: ' + tid);
        continue;
      }
      new_playlist.push(track);
    }
  }

  let new_scratchpad = [];
  for (let i = 0; i < playlist.length; i++) {
    let track = playlist[i]
    let tid = track.trackId;
    if (getTrackWithMatchingId(new_playlist, tid) === null) {
      new_scratchpad.push(track);
    }
  }

  replaceTracks(getPlaylistTable(), new_playlist);
  renderPlaylist();
  if (new_scratchpad.length > 0) {
    replaceTracks(getScratchpadTable(), new_scratchpad);
    renderScratchpad();
    showScratchpad();
  }
  else {
    clearTable(getScratchpadTable());
    renderScratchpad();
  }
  indicateStateUpdate();
}
