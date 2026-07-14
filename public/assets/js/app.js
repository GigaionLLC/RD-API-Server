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
    var labels = {
      idle: 'Save',
      dirty: 'Save changes',
      saving: 'Saving...',
      saved: 'Saved',
      error: 'Retry'
    };
    var html = labels[state] || labels.idle;

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
      setLiveFormState($button, 'idle');

      $form.on('input.rdLive change.rdLive', ':input', function () {
        if ($button.attr('data-state') !== 'saving') {
          setLiveFormState($button, 'dirty');
        }
      });

      $form.on('submit.rdLive', function (event) {
        event.preventDefault();
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

          setLiveFormState($button, 'saved');
          RD.toast('Saved successfully', 'success');
          window.setTimeout(function () {
            setLiveFormState($button, 'idle');
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
    var request = { deferred: deferred };
    var $dialog = ensureConfirmDialog();
    var $accept = $dialog.find('[data-rd-confirm-accept]');
    var modal;

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
      activeConfirm = null;
      deferred.resolve(confirmed);
    }

    if (window.bootstrap && window.bootstrap.Modal) {
      modal = window.bootstrap.Modal.getOrCreateInstance($dialog[0]);
      $dialog
        .off('hidden.bs.modal.rdConfirmDialog')
        .one('hidden.bs.modal.rdConfirmDialog', function () {
          finish(false);
        });
      $accept
        .off('click.rdConfirmDialog')
        .one('click.rdConfirmDialog', function () {
          finish(true);
          modal.hide();
        });
      modal.show();
    } else {
      finish(false);
      RD.toast('Unable to open the confirmation dialog', 'error');
    }

    return deferred.promise();
  };

  RD.bindConfirms = function (root) {
    var $root = $(root || document);

    $root.off('click.rdConfirm', '[data-confirm]');
    $root.on('click.rdConfirm', '[data-confirm]', function (event) {
      var $trigger = $(this);

      if ($trigger.data('rdConfirmed')) {
        $trigger.removeData('rdConfirmed');
        return;
      }

      if ($trigger.is(':disabled') || $trigger.attr('aria-disabled') === 'true') {
        return;
      }

      event.preventDefault();
      event.stopImmediatePropagation();

      var title = $trigger.attr('data-confirm-title') || 'Confirm action';
      var message = $trigger.attr('data-confirm') ||
        'Are you sure? This action cannot be undone.';
      var acceptLabel = $trigger.attr('data-confirm-action') || 'Confirm';

      RD.confirm(message, {
        title: title,
        action: acceptLabel,
        danger: $trigger.attr('data-confirm-tone') !== 'primary'
      }).done(function (confirmed) {
        if (confirmed) {
          $trigger.data('rdConfirmed', true);
          window.setTimeout(function () {
            $trigger[0].click();
          }, 0);
        } else {
          $trigger.trigger('focus');
        }
      });
    });
  };

  /* Theme and application shell */
  function readStoredTheme() {
    try {
      return window.localStorage.getItem('rd_theme');
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
        .attr('aria-pressed', theme === 'light' ? 'true' : 'false')
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

  function setSidebar(open, returnFocus) {
    var $sidebar = $('.rd-sidebar');
    var $backdrop = $('.rd-sidebar__backdrop');
    var $toggle = $('.rd-sidebar__toggle');

    $sidebar.toggleClass('is-open', open);
    $backdrop.toggleClass('is-visible', open);
    $('body').toggleClass('rd-menu-open', open);
    $toggle.attr('aria-expanded', open ? 'true' : 'false');
    $sidebar.attr('aria-hidden', open ? 'false' : 'true');

    if (open) {
      window.setTimeout(function () {
        $sidebar.find('a, button').filter(':visible').first().trigger('focus');
      }, 210);
    } else if (returnFocus) {
      $toggle.first().trigger('focus');
    }
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

    $(document)
      .off('click.rdShell', '.rd-sidebar__toggle')
      .on('click.rdShell', '.rd-sidebar__toggle', function () {
        setSidebar(!$('.rd-sidebar').hasClass('is-open'), false);
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
        var expanded = $(this).attr('aria-expanded') !== 'false';
        $(this).attr('aria-expanded', expanded ? 'false' : 'true');
      })
      .off('keydown.rdShell')
      .on('keydown.rdShell', function (event) {
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
        $('.rd-sidebar').attr(
          'aria-hidden',
          window.innerWidth > 991 ? 'false' : ($('.rd-sidebar').hasClass('is-open') ? 'false' : 'true')
        );
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
        $menu.removeClass('is-open');
        $input.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
        activeIndex = -1;
        if (clear) {
          $menu.empty();
        }
      }

      function open() {
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
        var thisRequest = ++requestNumber;
        var query = $.trim($input.val());
        if (!url) {
          return;
        }

        $input.attr('aria-busy', 'true');
        RD.api({
          url: url + (url.indexOf('?') < 0 ? '?' : '&') +
            'q=' + window.encodeURIComponent(query)
        }).done(function (results) {
          if (thisRequest === requestNumber) {
            render(results);
          }
        }).fail(function () {
          if (thisRequest === requestNumber) {
            close(true);
          }
        }).always(function () {
          if (thisRequest === requestNumber) {
            $input.removeAttr('aria-busy');
          }
        });
      }

      $input.on('input.rdCombo', function () {
        $hidden.val('');
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
  RD.bindCopyButtons = function (root) {
    $(root || document)
      .off('click.rdCopy', '[data-copy]')
      .on('click.rdCopy', '[data-copy]', function () {
        var selector = $(this).attr('data-copy');
        var source = selector ? document.querySelector(selector) : null;
        var text = source
          ? (source.value || source.textContent || '')
          : ($(this).attr('data-copy-text') || '');

        if (!text || !window.navigator.clipboard) {
          RD.toast('Unable to copy this value', 'error');
          return;
        }

        window.navigator.clipboard.writeText(text).then(function () {
          RD.toast('Copied to clipboard', 'success');
        }).catch(function () {
          RD.toast('Unable to copy this value', 'error');
        });
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
