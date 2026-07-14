/*
 * RD-API-Server admin interactions.
 * Plain jQuery + Bootstrap 5: API helpers, live forms, accessible feedback,
 * responsive navigation, dual-theme state, charts, and searchable comboboxes.
 */
(function ($, window, document) {
  'use strict';

  var RD = window.RD || {};
  var comboSequence = 0;
  var activeConfirm = null;
  var sidebarFocusTimer = null;
  var supportsInert = 'inert' in document.createElement('div');

  /* API client */
  RD.api = function (opts) {
    opts = opts || {};
    var headers = $.extend({ Accept: 'application/json' }, opts.headers || {});
    var token = RD.token();
    var csrf = $('meta[name="csrf-token"]').attr('content');

    if (token) {
      headers.Authorization = 'Bearer ' + token;
    }
    if (csrf) {
      headers['X-CSRF-TOKEN'] = csrf;
    }

    return $.ajax({
      url: opts.url,
      method: opts.method || 'GET',
      data: opts.json ? JSON.stringify(opts.data) : opts.data,
      contentType: opts.json
        ? 'application/json'
        : (opts.contentType || 'application/x-www-form-urlencoded'),
      headers: headers,
      dataType: opts.dataType || 'json'
    });
  };

  RD.token = function (value) {
    if (typeof value !== 'undefined') {
      try {
        if (value === null) {
          window.localStorage.removeItem('rd_token');
        } else {
          window.localStorage.setItem('rd_token', value);
        }
      } catch (error) {
        // Storage can be unavailable in privacy-restricted browser contexts.
      }
      return value;
    }

    try {
      return window.localStorage.getItem('rd_token');
    } catch (error) {
      return null;
    }
  };

  /* Accessible toast notifications */
  RD.toast = function (message, type) {
    type = type || 'info';

    var $wrap = $('.rd-toasts');
    if (!$wrap.length) {
      $wrap = $('<div class="rd-toasts" aria-live="polite" aria-atomic="false"></div>')
        .appendTo('body');
    }

    var icons = {
      success: 'ri-checkbox-circle-line',
      error: 'ri-error-warning-line',
      warning: 'ri-alert-line',
      info: 'ri-information-line'
    };
    var role = type === 'error' ? 'alert' : 'status';
    var $toast = $('<div class="rd-toast"></div>')
      .addClass('rd-toast--' + type)
      .attr('role', role);
    var $icon = $('<i aria-hidden="true"></i>').addClass(icons[type] || icons.info);
    var $message = $('<span></span>').text(message);
    var $close = $(
      '<button type="button" class="rd-toast__close" aria-label="Dismiss notification">' +
        '<i class="ri-close-line" aria-hidden="true"></i>' +
      '</button>'
    );
    var timer;

    function remove() {
      window.clearTimeout(timer);
      $toast.addClass('is-leaving');
      window.setTimeout(function () {
        $toast.remove();
        if (!$wrap.children().length) {
          $wrap.remove();
        }
      }, 190);
    }

    $close.on('click', remove);
    $toast.on('mouseenter focusin', function () {
      window.clearTimeout(timer);
    });
    $toast.on('mouseleave focusout', function () {
      timer = window.setTimeout(remove, type === 'error' ? 6000 : 3600);
    });
    $toast.append($icon, $message, $close);
    $wrap.append($toast);
    timer = window.setTimeout(remove, type === 'error' ? 6000 : 3600);

    return $toast;
  };

  /* Live-save forms */
  function setLiveFormState($button, state) {
    var html = $button.data('rdOriginalHtml') || 'Save';

    if (state === 'saving') {
      html = '<span class="rd-spin" aria-hidden="true"></span> Saving...';
    } else if (state === 'saved') {
      html = '<i class="ri-check-line" aria-hidden="true"></i> Saved';
    }

    $button
      .attr('data-state', state)
      .attr('aria-busy', state === 'saving' ? 'true' : 'false')
      .html(html)
      .prop('disabled', state === 'saving');
  }

  function serializeLiveForm($form) {
    var data = {};

    $.each($form.serializeArray(), function (_, field) {
      var match = field.name.match(/^([^[]+)\[([^\]]*)\]$/);
      if (!match) {
        data[field.name] = field.value;
        return;
      }

      var base = match[1];
      var key = match[2];
      if (key === '') {
        (data[base] = data[base] || []).push(field.value);
      } else {
        (data[base] = data[base] || {})[key] = field.value;
      }
    });

    return data;
  }

  RD.bindLiveForms = function (root) {
    $(root || document).find('form.rd-liveform').each(function () {
      var $form = $(this);
      var $button = $form.find('.rd-btn--save').first();

      if (!$button.length || $form.data('rdLiveBound')) {
        return;
      }

      $form.data('rdLiveBound', true);
      $form.data('rdRevision', 0);
      $button.data('rdOriginalHtml', $button.html());
      setLiveFormState($button, 'idle');

      $form.on('input.rdLive change.rdLive', ':input', function () {
        $form.data('rdRevision', ($form.data('rdRevision') || 0) + 1);
        if ($button.attr('data-state') !== 'saving') {
          setLiveFormState($button, 'dirty');
        }
      });

      $form.on('submit.rdLive', function (event) {
        event.preventDefault();
        var submittedRevision = $form.data('rdRevision') || 0;
        setLiveFormState($button, 'saving');

        RD.api({
          url: $form.data('url'),
          method: ($form.data('method') || 'POST').toUpperCase(),
          json: true,
          data: serializeLiveForm($form)
        }).done(function (response) {
          if (response && response.error) {
            setLiveFormState($button, 'error');
            RD.toast(response.error, 'error');
            return;
          }

          if (($form.data('rdRevision') || 0) !== submittedRevision) {
            setLiveFormState($button, 'dirty');
            RD.toast('Earlier changes were saved; newer edits still need saving', 'warning');
            return;
          }

          setLiveFormState($button, 'saved');
          RD.toast('Saved successfully', 'success');
          window.setTimeout(function () {
            if (($form.data('rdRevision') || 0) === submittedRevision &&
                $button.attr('data-state') === 'saved') {
              setLiveFormState($button, 'idle');
            }
          }, 1600);
        }).fail(function (xhr) {
          var response = xhr.responseJSON || {};
          setLiveFormState($button, 'error');
          RD.toast(response.error || response.message || 'Save failed', 'error');
        });
      });
    });
  };

  /* Accessible confirmation dialog */
  function ensureConfirmDialog() {
    var $dialog = $('#rdConfirmDialog');
    if ($dialog.length) {
      return $dialog;
    }

    $dialog = $(
      '<div class="modal fade" id="rdConfirmDialog" tabindex="-1" ' +
          'aria-labelledby="rdConfirmTitle" aria-describedby="rdConfirmMessage" aria-hidden="true">' +
        '<div class="modal-dialog modal-dialog-centered modal-sm">' +
          '<div class="modal-content">' +
            '<div class="modal-header">' +
              '<h2 class="modal-title fs-6" id="rdConfirmTitle">Confirm action</h2>' +
              '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
            '</div>' +
            '<div class="modal-body" id="rdConfirmMessage"></div>' +
            '<div class="modal-footer">' +
              '<button type="button" class="rd-btn rd-btn--ghost" data-bs-dismiss="modal">Cancel</button>' +
              '<button type="button" class="rd-btn rd-btn--danger" data-rd-confirm-accept>Confirm</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>'
    ).appendTo('body');

    return $dialog;
  }

  RD.confirm = function (message, options) {
    options = options || {};
    var deferred = $.Deferred();
    var $dialog = ensureConfirmDialog();
    var $accept = $dialog.find('[data-rd-confirm-accept]');
    var focusOrigin = options.focusOrigin || document.activeElement;
    var $parentDialog = $(focusOrigin).closest('.modal.show').not($dialog).first();
    var request = { deferred: deferred, confirmed: false };
    var modal;
    var parentModal = null;

    if (activeConfirm) {
      activeConfirm.deferred.resolve(false);
    }
    activeConfirm = request;

    $dialog.find('#rdConfirmTitle').text(options.title || 'Confirm action');
    $dialog.find('#rdConfirmMessage').text(
      message || 'Are you sure? This action cannot be undone.'
    );
    $accept
      .text(options.action || 'Confirm')
      .removeClass('rd-btn--danger rd-btn--primary')
      .addClass(options.danger === false ? 'rd-btn--primary' : 'rd-btn--danger');

    function finish(confirmed) {
      if (activeConfirm !== request) {
        return;
      }
      request.confirmed = confirmed;
      activeConfirm = null;
      deferred.resolve(confirmed);
    }

    function restoreFocus() {
      if (focusOrigin && document.documentElement.contains(focusOrigin) &&
          typeof focusOrigin.focus === 'function') {
        focusOrigin.focus();
      }
    }

    if (window.bootstrap && window.bootstrap.Modal) {
      modal = window.bootstrap.Modal.getOrCreateInstance($dialog[0]);
      if ($parentDialog.length) {
        parentModal = window.bootstrap.Modal.getOrCreateInstance($parentDialog[0]);
      }
      $dialog
        .off('hidden.bs.modal.rdConfirmDialog')
        .one('hidden.bs.modal.rdConfirmDialog', function () {
          var confirmed = request.confirmed;
          if (!confirmed) {
            finish(false);
          }

          if (!confirmed && parentModal && document.documentElement.contains($parentDialog[0])) {
            $parentDialog
              .off('shown.bs.modal.rdConfirmRestore')
              .one('shown.bs.modal.rdConfirmRestore', restoreFocus);
            parentModal.show();
          } else if (!confirmed) {
            window.setTimeout(restoreFocus, 0);
          }
        });
      $accept
        .off('click.rdConfirmDialog')
        .one('click.rdConfirmDialog', function () {
          finish(true);
          modal.hide();
        });

      if (parentModal) {
        $parentDialog
          .off('hidden.bs.modal.rdConfirmParent')
          .one('hidden.bs.modal.rdConfirmParent', function () {
            modal.show();
          });
        parentModal.hide();
      } else {
        modal.show();
      }
    } else {
      finish(false);
      RD.toast('Unable to open the confirmation dialog', 'error');
    }

    return deferred.promise();
  };

  RD.bindConfirms = function (root) {
    var $root = $(root || document);

    function optionsFor($trigger, focusOrigin) {
      return {
        title: $trigger.attr('data-confirm-title') || 'Confirm action',
        action: $trigger.attr('data-confirm-action') || 'Confirm',
        danger: $trigger.attr('data-confirm-tone') !== 'primary',
        focusOrigin: focusOrigin || $trigger[0]
      };
    }

    $root.off('click.rdConfirm', '[data-confirm]');
    $root.on('click.rdConfirm', '[data-confirm]', function (event) {
      var $trigger = $(this);

      if ($trigger.data('rdConfirmed')) {
        $trigger.removeData('rdConfirmed');
        return;
      }

      if ($trigger.is('button:not([type]), button[type="submit"], input[type="submit"]') &&
          $trigger.closest('form').length) {
        return;
      }

      if ($trigger.is(':disabled') || $trigger.attr('aria-disabled') === 'true') {
        return;
      }

      event.preventDefault();
      event.stopImmediatePropagation();

      var message = $trigger.attr('data-confirm') ||
        'Are you sure? This action cannot be undone.';

      RD.confirm(message, optionsFor($trigger)).done(function (confirmed) {
        if (confirmed) {
          $trigger.data('rdConfirmed', true);
          window.setTimeout(function () {
            $trigger[0].click();
          }, 0);
        }
      });
    });

    $root.off('submit.rdConfirm', 'form');
    $root.on('submit.rdConfirm', 'form', function (event) {
      var form = this;
      var $form = $(form);

      if ($form.data('rdConfirmed')) {
        $form.removeData('rdConfirmed');
        return;
      }

      var nativeEvent = event.originalEvent || event;
      var submitter = nativeEvent.submitter || null;
      var $trigger = submitter
        ? $(submitter)
        : $form.find('button[data-confirm]:not([type]), button[type="submit"][data-confirm], input[type="submit"][data-confirm]').first();
      if ((!$trigger.length || !$trigger.is('[data-confirm]')) && $form.is('[data-confirm]')) {
        $trigger = $form;
      }
      if (!$trigger.length || !$trigger.is('[data-confirm]')) {
        return;
      }

      event.preventDefault();
      event.stopImmediatePropagation();

      RD.confirm(
        $trigger.attr('data-confirm') || 'Are you sure? This action cannot be undone.',
        optionsFor($trigger, document.activeElement)
      ).done(function (confirmed) {
        if (!confirmed) {
          return;
        }

        $form.data('rdConfirmed', true);
        window.setTimeout(function () {
          if (typeof form.requestSubmit === 'function') {
            if (submitter) {
              form.requestSubmit(submitter);
            } else {
              form.requestSubmit();
            }
          } else {
            window.HTMLFormElement.prototype.submit.call(form);
          }
        }, 0);
      });
    });
  };

  /* Theme and application shell */
  function readStoredTheme() {
    try {
      var theme = window.localStorage.getItem('rd_theme');
      return theme === 'light' || theme === 'dark' ? theme : null;
    } catch (error) {
      return null;
    }
  }

  function systemTheme() {
    return window.matchMedia &&
      window.matchMedia('(prefers-color-scheme: light)').matches
      ? 'light'
      : 'dark';
  }

  function updateThemeControls(theme) {
    $('[data-theme-toggle]').each(function () {
      var next = theme === 'dark' ? 'light' : 'dark';
      $(this)
        .attr('aria-label', 'Switch to ' + next + ' theme')
        .attr('title', 'Switch to ' + next + ' theme')
        .removeAttr('aria-pressed')
        .find('i')
        .attr('class', theme === 'dark' ? 'ri-sun-line' : 'ri-moon-line');
    });
  }

  RD.themeTokens = function () {
    var styles = window.getComputedStyle(document.documentElement);
    var value = function (name) {
      return styles.getPropertyValue(name).trim();
    };

    return {
      primary: value('--rd-primary'),
      success: value('--rd-success'),
      warning: value('--rd-warning'),
      danger: value('--rd-danger'),
      info: value('--rd-info'),
      border: value('--rd-border'),
      borderStrong: value('--rd-border-strong'),
      text: value('--rd-text'),
      textMuted: value('--rd-text-muted'),
      surface: value('--rd-surface'),
      surfaceRaised: value('--rd-surface-raised')
    };
  };

  RD.setTheme = function (theme, persist) {
    theme = theme === 'light' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.setAttribute('data-bs-theme', theme);
    updateThemeControls(theme);

    if (persist !== false) {
      try {
        window.localStorage.setItem('rd_theme', theme);
      } catch (error) {
        // The visual change still works when persistence is unavailable.
      }
    }

    document.dispatchEvent(new window.CustomEvent('rd:themechange', {
      detail: {
        theme: theme,
        tokens: RD.themeTokens()
      }
    }));

    return theme;
  };

  function setInert($element, inert) {
    if (!$element.length) {
      return;
    }

    if (supportsInert) {
      $element.prop('inert', inert);
      if (inert) {
        $element.attr('inert', '');
      } else {
        $element.removeAttr('inert');
      }
      return;
    }

    var focusable = 'a[href], button, input, select, textarea, [tabindex]';
    if (inert) {
      $element.attr('inert', '');
      $element.find(focusable).each(function () {
        var $item = $(this);
        if (!$item.is('[data-rd-inert-tabindex]')) {
          $item.attr('data-rd-inert-tabindex', $item.attr('tabindex') || '__none__');
          $item.attr('tabindex', '-1');
        }
      });
    } else {
      $element.removeAttr('inert');
      $element.find('[data-rd-inert-tabindex]').each(function () {
        var $item = $(this);
        var previous = $item.attr('data-rd-inert-tabindex');
        if (previous === '__none__') {
          $item.removeAttr('tabindex');
        } else {
          $item.attr('tabindex', previous);
        }
        $item.removeAttr('data-rd-inert-tabindex');
      });
    }
  }

  function syncSidebarAccessibility() {
    var mobile = window.innerWidth <= 991;
    var $sidebar = $('.rd-sidebar');
    var open = mobile && $sidebar.hasClass('is-open');
    var interactive = !mobile || open;

    $sidebar.attr('aria-hidden', interactive ? 'false' : 'true');
    setInert($sidebar, !interactive);
    setInert($('.rd-main'), open);
  }

  function setSidebar(open, returnFocus) {
    var $sidebar = $('.rd-sidebar');
    var $backdrop = $('.rd-sidebar__backdrop');
    var $toggle = $('.rd-sidebar__toggle');

    window.clearTimeout(sidebarFocusTimer);
    $sidebar.toggleClass('is-open', open);
    $backdrop.toggleClass('is-visible', open);
    $('body').toggleClass('rd-menu-open', open);
    $toggle.attr('aria-expanded', open ? 'true' : 'false');
    syncSidebarAccessibility();

    if (open) {
      sidebarFocusTimer = window.setTimeout(function () {
        $sidebar.find('a, button').filter(':visible').first().trigger('focus');
      }, 0);
    } else if (returnFocus) {
      $toggle.first().trigger('focus');
    }
  }

  function trapSidebarFocus(event) {
    var $sidebar = $('.rd-sidebar.is-open');
    if (window.innerWidth > 991 || !$sidebar.length || event.key !== 'Tab') {
      return false;
    }

    var $items = $sidebar
      .find('a[href], button:not(:disabled), input:not(:disabled), select:not(:disabled), [tabindex]:not([tabindex="-1"])')
      .filter(':visible');
    if (!$items.length) {
      return false;
    }

    var first = $items[0];
    var last = $items[$items.length - 1];
    if (event.shiftKey && (event.target === first || !$.contains($sidebar[0], event.target))) {
      event.preventDefault();
      last.focus();
      return true;
    }
    if (!event.shiftKey && (event.target === last || !$.contains($sidebar[0], event.target))) {
      event.preventDefault();
      first.focus();
      return true;
    }

    return false;
  }

  function readNavGroupState() {
    try {
      var state = JSON.parse(window.localStorage.getItem('rd_nav_groups') || '{}');
      return state && typeof state === 'object' ? state : {};
    } catch (error) {
      return {};
    }
  }

  function saveNavGroupState(id, expanded) {
    if (!id) {
      return;
    }
    try {
      var state = readNavGroupState();
      state[id] = expanded;
      window.localStorage.setItem('rd_nav_groups', JSON.stringify(state));
    } catch (error) {
      // Navigation remains usable when storage is unavailable.
    }
  }

  function restoreNavGroupState() {
    var state = readNavGroupState();
    $('.rd-nav__group-toggle').each(function () {
      var $toggle = $(this);
      var id = $toggle.attr('aria-controls');
      var hasActivePage = $toggle
        .closest('.rd-nav__group')
        .find('[aria-current="page"], .rd-nav__item.active').length > 0;
      var expanded = hasActivePage || state[id] !== false;
      $toggle.attr('aria-expanded', expanded ? 'true' : 'false');
    });
  }

  function dismissTopModalOnEscape(event) {
    if (event.key !== 'Escape' || !window.bootstrap || !window.bootstrap.Modal) {
      return false;
    }

    var $originModal = $(event.target).closest('.modal');
    var $modal = $('.modal.show').last();

    // Bootstrap handles Escape on the modal itself once its opening transition
    // has finished. At that point the `show` class is already gone here.
    if (!$modal.length) {
      return $originModal.length > 0;
    }

    if ($modal.attr('data-bs-keyboard') === 'false') {
      return true;
    }

    var modal = window.bootstrap.Modal.getOrCreateInstance($modal[0]);
    var hideModal = function () {
      modal.hide();
    };

    // A modal is visible before Bootstrap finishes its opening transition, and
    // hide() is intentionally ignored during that interval. Queue the same
    // dismissal for `shown` so a quick Escape is never lost.
    $modal
      .off('shown.bs.modal.rdEarlyEscape')
      .one('shown.bs.modal.rdEarlyEscape', hideModal);
    hideModal();

    if (!$modal.hasClass('show')) {
      $modal.off('shown.bs.modal.rdEarlyEscape');
    }

    return true;
  }

  RD.bindShell = function () {
    var initial = readStoredTheme() || systemTheme();
    RD.setTheme(initial, false);
    restoreNavGroupState();

    $(document)
      .off('click.rdShell', '.rd-sidebar__toggle')
      .on('click.rdShell', '.rd-sidebar__toggle', function () {
        setSidebar(!$('.rd-sidebar').hasClass('is-open'), false);
      })
      .off('click.rdShellClose', '.rd-sidebar__close')
      .on('click.rdShellClose', '.rd-sidebar__close', function () {
        setSidebar(false, true);
      })
      .off('click.rdShellBackdrop', '.rd-sidebar__backdrop')
      .on('click.rdShellBackdrop', '.rd-sidebar__backdrop', function () {
        setSidebar(false, true);
      })
      .off('click.rdTheme', '[data-theme-toggle]')
      .on('click.rdTheme', '[data-theme-toggle]', function () {
        var current = document.documentElement.getAttribute('data-theme');
        RD.setTheme(current === 'light' ? 'dark' : 'light', true);
      })
      .off('click.rdNavGroup', '.rd-nav__group-toggle')
      .on('click.rdNavGroup', '.rd-nav__group-toggle', function () {
        var $toggle = $(this);
        var expanded = $toggle.attr('aria-expanded') !== 'false';
        var next = !expanded;
        $toggle.attr('aria-expanded', next ? 'true' : 'false');
        saveNavGroupState($toggle.attr('aria-controls'), next);
      })
      .off('keydown.rdShell')
      .on('keydown.rdShell', function (event) {
        if (trapSidebarFocus(event)) {
          return;
        }
        if (dismissTopModalOnEscape(event)) {
          return;
        }

        if (event.key === 'Escape' && $('.rd-sidebar').hasClass('is-open')) {
          setSidebar(false, true);
        }
      });

    $(window)
      .off('resize.rdShell')
      .on('resize.rdShell', function () {
        if (window.innerWidth > 991 && $('.rd-sidebar').hasClass('is-open')) {
          setSidebar(false, false);
        }
        syncSidebarAccessibility();
      })
      .triggerHandler('resize.rdShell');

    if (window.matchMedia) {
      var preference = window.matchMedia('(prefers-color-scheme: light)');
      var onPreferenceChange = function () {
        if (!readStoredTheme()) {
          RD.setTheme(systemTheme(), false);
        }
      };

      if (preference.addEventListener) {
        preference.addEventListener('change', onPreferenceChange);
      } else if (preference.addListener) {
        preference.addListener(onPreferenceChange);
      }
    }
  };

  /* ApexCharts wrapper driven entirely by design tokens */
  function resolveChartColors(input, tokens) {
    var colors = input;
    if (!colors) {
      return [tokens.primary];
    }
    if (!Array.isArray(colors)) {
      colors = [colors];
    }

    return colors.map(function (color) {
      return tokens[color] || color;
    });
  }

  RD.areaChart = function (element, series, categories, colors) {
    if (!window.ApexCharts) {
      return null;
    }

    var target = typeof element === 'string'
      ? document.querySelector(element)
      : element;
    if (!target) {
      return null;
    }

    var tokens = RD.themeTokens();
    var mode = document.documentElement.getAttribute('data-theme') === 'light'
      ? 'light'
      : 'dark';
    var chart = new window.ApexCharts(target, {
      chart: {
        type: 'area',
        height: 280,
        toolbar: { show: false },
        fontFamily: 'inherit',
        foreColor: tokens.textMuted,
        background: 'transparent',
        animations: {
          enabled: !window.matchMedia('(prefers-reduced-motion: reduce)').matches
        }
      },
      theme: { mode: mode },
      series: series,
      colors: resolveChartColors(colors, tokens),
      dataLabels: { enabled: false },
      stroke: { curve: 'smooth', width: 2 },
      fill: { type: 'solid', opacity: 0.12 },
      grid: {
        borderColor: tokens.border,
        strokeDashArray: 3,
        padding: { left: 6, right: 8 }
      },
      xaxis: {
        categories: categories || [],
        axisBorder: { color: tokens.border },
        axisTicks: { color: tokens.border },
        labels: { style: { colors: tokens.textMuted } }
      },
      yaxis: {
        labels: { style: { colors: tokens.textMuted } }
      },
      legend: {
        labels: { colors: tokens.textMuted }
      },
      tooltip: { theme: mode }
    });

    chart.render();

    var updateTheme = function (event) {
      var next = event.detail || {};
      var nextTokens = next.tokens || RD.themeTokens();
      var nextMode = next.theme || 'dark';

      chart.updateOptions({
        chart: { foreColor: nextTokens.textMuted },
        theme: { mode: nextMode },
        colors: resolveChartColors(colors, nextTokens),
        grid: { borderColor: nextTokens.border },
        xaxis: {
          axisBorder: { color: nextTokens.border },
          axisTicks: { color: nextTokens.border },
          labels: { style: { colors: nextTokens.textMuted } }
        },
        yaxis: {
          labels: { style: { colors: nextTokens.textMuted } }
        },
        legend: {
          labels: { colors: nextTokens.textMuted }
        },
        tooltip: { theme: nextMode }
      }, false, true);
    };

    document.addEventListener('rd:themechange', updateTheme);
    chart.rdRemoveThemeListener = function () {
      document.removeEventListener('rd:themechange', updateTheme);
    };

    return chart;
  };

  /* WAI-ARIA searchable combobox */
  RD.bindCombobox = function (root) {
    $(root || document).find('.rd-combo').each(function () {
      var $combo = $(this);
      if ($combo.data('rdBound')) {
        return;
      }

      $combo.data('rdBound', true);
      comboSequence += 1;

      var $hidden = $combo.find('input[type="hidden"]').first();
      var $input = $combo.find('.rd-combo__input').first();
      var $menu = $combo.find('.rd-combo__menu').first();
      var url = $combo.data('url');
      var listId = $menu.attr('id') || 'rd-combo-list-' + comboSequence;
      var timer = null;
      var activeIndex = -1;
      var requestNumber = 0;
      var request = null;
      var dismissed = false;

      $menu.attr({ id: listId, role: 'listbox' });
      $input.attr({
        role: 'combobox',
        'aria-autocomplete': 'list',
        'aria-controls': listId,
        'aria-expanded': 'false',
        autocomplete: 'off'
      });

      function items() {
        return $menu.find('.rd-combo__item');
      }

      function close(clear) {
        dismissed = true;
        window.clearTimeout(timer);
        requestNumber += 1;
        if (request && request.readyState !== 4) {
          request.abort();
        }
        request = null;
        $menu.removeClass('is-open');
        $input
          .attr('aria-expanded', 'false')
          .removeAttr('aria-activedescendant aria-busy');
        activeIndex = -1;
        if (clear) {
          $menu.empty();
        }
      }

      function open() {
        dismissed = false;
        $menu.addClass('is-open');
        $input.attr('aria-expanded', 'true');
      }

      function setActive(index) {
        var $items = items();
        if (!$items.length) {
          activeIndex = -1;
          return;
        }

        activeIndex = (index + $items.length) % $items.length;
        $items.removeClass('is-active').attr('aria-selected', 'false');
        var $active = $items.eq(activeIndex)
          .addClass('is-active')
          .attr('aria-selected', 'true');
        $input.attr('aria-activedescendant', $active.attr('id'));
        $active[0].scrollIntoView({ block: 'nearest' });
      }

      function choose($item) {
        if (!$item || !$item.length) {
          return;
        }

        $hidden.val($item.attr('data-id'));
        $input.val($item.text());
        close(false);
        $hidden.trigger('change');
      }

      function render(results) {
        $menu.empty();
        activeIndex = -1;

        if (!results || !results.length) {
          $('<div class="rd-combo__empty" role="presentation">No matches</div>')
            .appendTo($menu);
        } else {
          $.each(results, function (index, result) {
            $('<div class="rd-combo__item"></div>')
              .attr({
                id: listId + '-option-' + index,
                role: 'option',
                'aria-selected': 'false',
                'data-id': result.id
              })
              .text(result.text)
              .appendTo($menu);
          });
        }

        open();
      }

      function search() {
        if (request && request.readyState !== 4) {
          request.abort();
        }
        var thisRequest = ++requestNumber;
        var query = $.trim($input.val());
        if (!url) {
          return;
        }

        dismissed = false;
        $input.attr('aria-busy', 'true');
        request = RD.api({
          url: url + (url.indexOf('?') < 0 ? '?' : '&') +
            'q=' + window.encodeURIComponent(query)
        }).done(function (results) {
          if (thisRequest === requestNumber && !dismissed && document.activeElement === $input[0]) {
            render(results);
          }
        }).fail(function () {
          if (thisRequest === requestNumber && !dismissed) {
            close(true);
          }
        }).always(function () {
          if (thisRequest === requestNumber) {
            $input.removeAttr('aria-busy');
            request = null;
          }
        });
      }

      $input.on('input.rdCombo', function () {
        $hidden.val('');
        dismissed = false;
        window.clearTimeout(timer);
        timer = window.setTimeout(search, 200);
      });

      $input.on('focus.rdCombo', function () {
        if ($menu.children().length) {
          open();
        } else {
          search();
        }
      });

      $input.on('keydown.rdCombo', function (event) {
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          if (!$menu.hasClass('is-open')) {
            search();
          } else {
            setActive(activeIndex + 1);
          }
        } else if (event.key === 'ArrowUp') {
          event.preventDefault();
          if ($menu.hasClass('is-open')) {
            setActive(activeIndex < 0 ? items().length - 1 : activeIndex - 1);
          }
        } else if (event.key === 'Enter' && activeIndex >= 0) {
          event.preventDefault();
          choose(items().eq(activeIndex));
        } else if (event.key === 'Escape') {
          event.preventDefault();
          event.stopPropagation();
          close(false);
        } else if (event.key === 'Tab') {
          close(false);
        }
      });

      $menu.on('mouseenter.rdCombo', '.rd-combo__item', function () {
        setActive(items().index(this));
      });

      $menu.on('mousedown.rdCombo', '.rd-combo__item', function (event) {
        event.preventDefault();
        choose($(this));
      });

      $(document).on('click.rdCombo' + comboSequence, function (event) {
        if (!$.contains($combo[0], event.target) && event.target !== $combo[0]) {
          close(false);
        }
      });
    });
  };

  /* Clipboard buttons */
  function fallbackCopy(source, text) {
    var temporary = null;
    var target = source;
    var focusOrigin = document.activeElement;
    var originalSelection = null;

    try {
      if (focusOrigin && typeof focusOrigin.selectionStart === 'number' &&
          typeof focusOrigin.selectionEnd === 'number') {
        originalSelection = {
          start: focusOrigin.selectionStart,
          end: focusOrigin.selectionEnd,
          direction: focusOrigin.selectionDirection
        };
      }
    } catch (error) {
      originalSelection = null;
    }

    if (!target || typeof target.select !== 'function') {
      temporary = document.createElement('textarea');
      temporary.value = text;
      temporary.setAttribute('readonly', '');
      temporary.style.position = 'fixed';
      temporary.style.opacity = '0';
      document.body.appendChild(temporary);
      target = temporary;
    }

    var copied = false;
    try {
      target.focus();
      target.select();
      copied = document.execCommand('copy');
    } catch (error) {
      copied = false;
    } finally {
      if (temporary) {
        temporary.remove();
      }
      if (focusOrigin && document.documentElement.contains(focusOrigin) &&
          typeof focusOrigin.focus === 'function') {
        try {
          focusOrigin.focus({ preventScroll: true });
        } catch (error) {
          focusOrigin.focus();
        }
        if (originalSelection && typeof focusOrigin.setSelectionRange === 'function') {
          try {
            focusOrigin.setSelectionRange(
              originalSelection.start,
              originalSelection.end,
              originalSelection.direction
            );
          } catch (error) {
            // Some input types do not support explicit selection ranges.
          }
        }
      }
    }
    return copied;
  }

  RD.bindCopyButtons = function (root) {
    $(root || document)
      .off('click.rdCopy', '[data-copy]')
      .on('click.rdCopy', '[data-copy]', function () {
        var selector = $(this).attr('data-copy');
        var source = null;
        try {
          source = selector ? document.querySelector(selector) : null;
        } catch (error) {
          source = null;
        }
        var text = source
          ? (source.value || source.textContent || '')
          : ($(this).attr('data-copy-text') || '');

        if (!text) {
          RD.toast('Unable to copy this value', 'error');
          return;
        }

        if (window.navigator.clipboard && window.isSecureContext) {
          window.navigator.clipboard.writeText(text).then(function () {
            RD.toast('Copied to clipboard', 'success');
          }).catch(function () {
            var copied = fallbackCopy(source, text);
            RD.toast(
              copied ? 'Copied to clipboard' : 'Unable to copy this value',
              copied ? 'success' : 'error'
            );
          });
          return;
        }

        var copied = fallbackCopy(source, text);
        RD.toast(copied ? 'Copied to clipboard' : 'Unable to copy this value', copied ? 'success' : 'error');
      });
  };

  $(function () {
    RD.bindShell();
    RD.bindLiveForms(document);
    RD.bindConfirms(document);
    RD.bindCombobox(document);
    RD.bindCopyButtons(document);

    // Page-level Blade scripts register their own ready handlers after this
    // bundle. Publish readiness on the next task so automation and extensions
    // only interact once every handler has had a chance to bind.
    window.setTimeout(function () {
      document.documentElement.setAttribute('data-rd-ready', 'true');
    }, 0);
  });

  window.RD = RD;
})(jQuery, window, document);
