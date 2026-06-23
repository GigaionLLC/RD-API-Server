/*
 * rustdesk-api admin — app.js
 * jQuery helpers for the dark dashboard: AJAX (bearer/CSRF), live-save buttons,
 * toasts, sidebar toggle, theme persistence, and confirm-before-delete.
 * No build step: plain ES5-friendly jQuery so it runs straight from /public/assets.
 */
(function ($, window, document) {
  'use strict';

  var RD = window.RD || {};

  /* --------------------------------------------------------------- API client */
  RD.api = function (opts) {
    opts = opts || {};
    var headers = $.extend({ 'Accept': 'application/json' }, opts.headers || {});
    var token = RD.token();
    if (token) { headers['Authorization'] = 'Bearer ' + token; }
    var csrf = $('meta[name="csrf-token"]').attr('content');
    if (csrf) { headers['X-CSRF-TOKEN'] = csrf; }

    return $.ajax({
      url: opts.url,
      method: opts.method || 'GET',
      data: opts.json ? JSON.stringify(opts.data) : opts.data,
      contentType: opts.json ? 'application/json' : (opts.contentType || 'application/x-www-form-urlencoded'),
      headers: headers,
      dataType: 'json'
    });
  };

  RD.token = function (value) {
    if (typeof value !== 'undefined') {
      if (value === null) { try { localStorage.removeItem('rd_token'); } catch (e) {} }
      else { try { localStorage.setItem('rd_token', value); } catch (e) {} }
      return value;
    }
    try { return localStorage.getItem('rd_token'); } catch (e) { return null; }
  };

  /* ------------------------------------------------------------------- Toasts */
  RD.toast = function (message, type) {
    type = type || 'info';
    var $wrap = $('.rd-toasts');
    if (!$wrap.length) { $wrap = $('<div class="rd-toasts"></div>').appendTo('body'); }
    var icons = { success: 'ri-checkbox-circle-line', error: 'ri-error-warning-line', info: 'ri-information-line' };
    var $t = $('<div class="rd-toast rd-toast--' + type + '">' +
      '<i class="' + (icons[type] || icons.info) + '"></i>' +
      '<span></span></div>');
    $t.find('span').text(message);
    $wrap.append($t);
    window.setTimeout(function () {
      $t.css({ transition: 'opacity .25s', opacity: 0 });
      window.setTimeout(function () { $t.remove(); }, 260);
    }, type === 'error' ? 6000 : 3200);
  };

  /* ---------------------------------------------------- Live-save form binding
   * Markup:
   *   <form class="rd-liveform" data-url="/admin/users/5" data-method="PUT">
   *     <input name="email" ...>
   *     <button type="submit" class="rd-btn rd-btn--save" data-state="idle">Save</button>
   *   </form>
   * Button state machine: idle -> dirty -> saving -> saved|error -> idle
   */
  function setState($btn, state) {
    $btn.attr('data-state', state);
    var labels = { idle: 'Save', dirty: 'Save changes', saving: 'Saving…', saved: 'Saved', error: 'Retry' };
    var html = labels[state] || 'Save';
    if (state === 'saving') { html = '<span class="rd-spin"></span> Saving…'; }
    else if (state === 'saved') { html = '<i class="ri-check-line"></i> Saved'; }
    $btn.html(html).prop('disabled', state === 'saving');
  }

  RD.bindLiveForms = function (root) {
    $(root || document).find('form.rd-liveform').each(function () {
      var $form = $(this);
      var $btn = $form.find('.rd-btn--save').first();
      if (!$btn.length) { return; }
      setState($btn, 'idle');

      $form.on('input change', ':input', function () {
        if ($btn.attr('data-state') !== 'saving') { setState($btn, 'dirty'); }
      });

      $form.on('submit', function (e) {
        e.preventDefault();
        setState($btn, 'saving');
        // Collect fields, supporting bracket notation so structured inputs survive the JSON
        // POST: "foo[]" gathers into an array under "foo"; "foo[key]" gathers into an object
        // under "foo" (e.g. opt[enable-keyboard]); plain fields stay scalar.
        var data = {};
        $.each($form.serializeArray(), function (_, f) {
          var m = f.name.match(/^([^[]+)\[([^\]]*)\]$/);
          if (m) {
            var base = m[1], key = m[2];
            if (key === '') {
              (data[base] = data[base] || []).push(f.value);
            } else {
              (data[base] = data[base] || {})[key] = f.value;
            }
          } else {
            data[f.name] = f.value;
          }
        });
        RD.api({
          url: $form.data('url'),
          method: ($form.data('method') || 'POST').toUpperCase(),
          json: true,
          data: data
        }).done(function (resp) {
          if (resp && resp.error) { setState($btn, 'error'); RD.toast(resp.error, 'error'); return; }
          setState($btn, 'saved');
          RD.toast('Saved successfully', 'success');
          window.setTimeout(function () { setState($btn, 'idle'); }, 1600);
        }).fail(function (xhr) {
          setState($btn, 'error');
          var msg = (xhr.responseJSON && (xhr.responseJSON.error || xhr.responseJSON.message)) || 'Save failed';
          RD.toast(msg, 'error');
        });
      });
    });
  };

  /* ----------------------------------------------------- Confirm-before-delete */
  RD.bindConfirms = function (root) {
    $(root || document).on('click', '[data-confirm]', function (e) {
      var msg = $(this).data('confirm') || 'Are you sure? This action cannot be undone.';
      if (!window.confirm(msg)) { e.preventDefault(); e.stopImmediatePropagation(); }
    });
  };

  /* ---------------------------------------------------------- Sidebar + theme */
  RD.bindShell = function () {
    $(document).on('click', '.rd-sidebar__toggle', function () {
      $('.rd-sidebar').toggleClass('is-open');
    });
    // Theme is dark-first; persist an override if a toggle exists.
    var saved;
    try { saved = localStorage.getItem('rd_theme'); } catch (e) {}
    if (saved) { document.documentElement.setAttribute('data-theme', saved); }
    $(document).on('click', '[data-theme-toggle]', function () {
      var cur = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', cur);
      try { localStorage.setItem('rd_theme', cur); } catch (e) {}
    });
  };

  /* ------------------------------------------------------------------- Charts */
  // Thin wrapper so pages can render an ApexCharts area chart with theme colors.
  RD.areaChart = function (el, series, categories, color) {
    if (!window.ApexCharts) { return null; }
    var chart = new window.ApexCharts(typeof el === 'string' ? document.querySelector(el) : el, {
      chart: { type: 'area', height: 280, toolbar: { show: false }, fontFamily: 'inherit', background: 'transparent' },
      theme: { mode: 'dark' },
      series: series,
      colors: [color || '#6571ff'],
      dataLabels: { enabled: false },
      stroke: { curve: 'smooth', width: 2 },
      fill: { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.05 } },
      grid: { borderColor: '#1b2942', strokeDashArray: 4 },
      xaxis: { categories: categories || [], labels: { style: { colors: '#7987a1' } } },
      yaxis: { labels: { style: { colors: '#7987a1' } } },
      tooltip: { theme: 'dark' }
    });
    chart.render();
    return chart;
  };

  /* ------------------------------------------------------- Searchable combobox
   * A live, server-backed picker for lists too large for a plain <select>.
   * Markup:
   *   <div class="rd-combo" data-url="/admin/devices/search">
   *     <input type="hidden" name="target_id" value="">
   *     <input type="text" class="rd-input rd-combo__input" placeholder="Search…" autocomplete="off">
   *     <div class="rd-combo__menu"></div>
   *   </div>
   * The endpoint returns JSON [{ id, text }, …]; typing queries it (debounced),
   * choosing a row fills the hidden input, clearing the text clears the value.
   */
  RD.bindCombobox = function (root) {
    $(root || document).find('.rd-combo').each(function () {
      var $combo = $(this);
      if ($combo.data('rdBound')) { return; }
      $combo.data('rdBound', true);

      var $hidden = $combo.find('input[type="hidden"]').first();
      var $input = $combo.find('.rd-combo__input');
      var $menu = $combo.find('.rd-combo__menu');
      var url = $combo.data('url');
      var timer = null;

      function close() { $menu.removeClass('is-open').empty(); }

      function render(items) {
        $menu.empty();
        if (!items || !items.length) {
          $menu.append('<div class="rd-combo__empty">No matches</div>');
        } else {
          $.each(items, function (_, it) {
            $('<div class="rd-combo__item"></div>').text(it.text).attr('data-id', it.id).appendTo($menu);
          });
        }
        $menu.addClass('is-open');
      }

      function search() {
        var q = $.trim($input.val());
        RD.api({ url: url + (url.indexOf('?') < 0 ? '?' : '&') + 'q=' + encodeURIComponent(q) })
          .done(render).fail(close);
      }

      $input.on('input', function () {
        $hidden.val('');                       // typing invalidates the prior choice
        window.clearTimeout(timer);
        timer = window.setTimeout(search, 200);
      });
      $input.on('focus', function () {
        if ($menu.children().length) { $menu.addClass('is-open'); } else { search(); }
      });
      $menu.on('mousedown', '.rd-combo__item', function (e) {
        e.preventDefault();
        $hidden.val($(this).attr('data-id'));
        $input.val($(this).text());
        close();
        $hidden.trigger('change'); // notify live-save / other listeners
      });
      $(document).on('click', function (e) {
        if (!$.contains($combo[0], e.target)) { close(); }
      });
    });
  };

  /* --------------------------------------------------------------------- Init */
  $(function () {
    RD.bindShell();
    RD.bindLiveForms(document);
    RD.bindConfirms(document);
    RD.bindCombobox(document);
  });

  window.RD = RD;
})(jQuery, window, document);
