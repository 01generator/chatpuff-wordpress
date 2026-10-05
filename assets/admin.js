/**
 * ChatPuff live chat for WooCommerce: the settings and inbox pages.
 *
 * @package ChatPuff
 * @license GPL-2.0-or-later
 */
(function () {
  'use strict';

  function init() {
    // Connect: open ChatPuff in a new tab right away. The tab is opened during the click so that
    // pop-up blockers allow it; without JavaScript the form posts and the page shows a link instead.
    document.querySelectorAll('form[data-chatpuff-connect]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!window.fetch) {
          return;
        }
        event.preventDefault();
        var tab = window.open('', '_blank');
        fetch(form.getAttribute('data-chatpuff-connect'), { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (response) { return response.json(); })
          .then(function (data) {
            if (tab && data.confirmation_url) {
              tab.opener = null;
              tab.location.href = data.confirmation_url;
            } else if (tab) {
              tab.close();
            }
            // A refusal is shown on the page, as after the form without JavaScript.
            if (!data.confirmation_url && data.code) {
              var url = new URL(window.location.href);
              url.searchParams.set('chatpuff_error', data.code);
              url.searchParams.set('chatpuff_reference', data.reference || '');
              window.location.href = url.toString();
              return;
            }
            window.location.reload();
          })
          .catch(function () {
            if (tab) {
              tab.close();
            }
            form.submit();
          });
      });
    });

    // While the owner confirms in ChatPuff, check every three seconds and reload once it is decided.
    var poll = document.querySelector('[data-chatpuff-poll]');
    if (poll && window.fetch) {
      var timer = window.setInterval(function () {
        fetch(poll.getAttribute('data-chatpuff-poll'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (response) { return response.json(); })
          .then(function (data) {
            if (data.state && data.state !== 'pending') {
              window.clearInterval(timer);
              window.location.reload();
            }
          })
          .catch(function () {});
      }, 3000);
    }

    document.querySelectorAll('form[data-chatpuff-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!window.confirm(form.getAttribute('data-chatpuff-confirm'))) {
          event.preventDefault();
        }
      });
    });

    var backoffice = document.querySelector('[data-chatpuff-backoffice]');
    if (backoffice && window.fetch && window.Promise) {
      initInbox(backoffice);
    }

    var sync = document.querySelector('[data-chatpuff-sync]');
    if (sync && window.fetch && window.Promise) {
      initSync(sync);
    }
  }

  /**
   * Synchronize now: step after step through the plugin's ajax action, a few seconds each, with
   * the progress drawn until the pass is complete. Without JavaScript the form posts one run.
   */
  function initSync(box) {
    var form = box.querySelector('form');
    var button = form ? form.querySelector('button') : null;
    var bar = box.querySelector('[data-chatpuff-sync-bar]');
    var fill = bar ? bar.querySelector('.chatpuff-progress-fill') : null;
    var label = box.querySelector('[data-chatpuff-sync-label]');
    if (!form || !button || !bar || !fill || !label) {
      return;
    }
    var text = function (name, values) {
      var template = box.getAttribute('data-label-' + name) || '';
      Object.keys(values).forEach(function (key) {
        template = template.split('{' + key + '}').join(String(values[key]));
      });
      return template;
    };
    var finish = function (message) {
      label.textContent = message;
      button.disabled = false;
    };
    var step = function () {
      fetch(box.getAttribute('data-chatpuff-sync'), { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          var done = parseInt(data.done, 10) || 0;
          var total = parseInt(data.total, 10) || 0;
          var percent = total > 0 ? Math.min(100, Math.round(100 * done / total)) : 100;
          fill.style.width = percent + '%';
          bar.setAttribute('aria-valuenow', String(percent));
          if (data.state === 'running') {
            label.textContent = text('progress', { done: done, total: total, percent: percent });
            step();
          } else if (data.state === 'complete') {
            fill.style.width = '100%';
            bar.setAttribute('aria-valuenow', '100');
            finish(text('complete', { sent: data.sent || 0 }));
          } else {
            finish(data.state === 'failed' ? text('error', { code: data.error || '?' }) : (data.error || text('error', { code: data.state || '?' })));
          }
        })
        .catch(function () {
          finish(text('error', { code: 'network' }));
        });
    };
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      button.disabled = true;
      bar.hidden = false;
      label.hidden = false;
      fill.style.width = '0%';
      label.textContent = text('progress', { done: 0, total: '…', percent: 0 });
      step();
    });
  }

  /**
   * The ChatPuff inbox (api-contract.md §7.3). The plugin gives this page 15-minute staff tokens for
   * the signed-in user; ChatPuff's inbox script draws the inbox and asks for a new token when
   * one expires. A user who has not linked their ChatPuff account yet links it once, in a
   * ChatPuff window.
   */
  function initInbox(host) {
    var api = host.getAttribute('data-api');
    var current = null;
    var pending = null;
    var mounted = false;
    var popup = null;
    var popupTimer = null;
    var linkOrigin = null;

    function show(state, error) {
      host.querySelectorAll('[data-chatpuff-when]').forEach(function (node) {
        node.hidden = node.getAttribute('data-chatpuff-when') !== state;
      });
      if (state === 'error') {
        host.querySelector('[data-chatpuff-when="error"]').textContent = error || host.getAttribute('data-failed');
      }
    }

    function post(url) {
      return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (response) { return response.json(); });
    }

    // The current token, or a new one from the plugin when asked to renew or close to expiry.
    function token(renew) {
      if (!renew && current && current.renewAt > Date.now()) {
        return Promise.resolve(current.token);
      }
      if (!pending) {
        pending = post(host.getAttribute('data-token-url')).then(function (data) {
          pending = null;
          if (data.state !== 'ready' || !data.access_token) {
            current = null;
            // The link was replaced or access removed: say so. Other failures leave a running inbox
            // alone; it retries by itself.
            if (data.state === 'not_linked' || data.state === 'no_access') {
              show(data.state);
            } else if (!mounted) {
              show('error', data.error);
            }
            throw new Error(data.state || 'error');
          }
          // The browser's clock may differ from ChatPuff's: count from now, a minute early.
          var lifetime = Date.parse(String(data.expires_at).replace(/(\.\d{3})\d+/, '$1')) - Date.now();
          if (!(lifetime > 120000 && lifetime <= 900000)) {
            lifetime = 600000;
          }
          current = { token: data.access_token, shopId: data.shop_id, renewAt: Date.now() + lifetime - 60000 };

          return current.token;
        }, function (error) {
          pending = null;
          if (!mounted) {
            show('error');
          }
          throw error;
        });
      }

      return pending;
    }

    function mount() {
      show('ready');
      if (mounted) {
        return;
      }
      mounted = true;
      var script = document.createElement('script');
      script.async = true;
      script.src = api + '/backoffice/v1/inbox.js';
      script.onload = function () {
        window.ChatPuffInbox.mount(host.querySelector('[data-chatpuff-when="ready"]'), {
          api: api,
          locale: host.getAttribute('data-locale'),
          shopId: current ? current.shopId : null,
          auth: { mode: 'bearer', token: token }
        });
      };
      script.onerror = function () {
        mounted = false;
        show('error');
      };
      document.head.appendChild(script);
    }

    function start() {
      show('loading');
      token(true).then(mount, function () {});
    }

    // After linking, or when the ChatPuff window closes without it: ask again. A page whose inbox
    // already ran for another account starts over.
    function afterLinking() {
      window.clearInterval(popupTimer);
      if (mounted) {
        window.location.reload();
      } else {
        start();
      }
    }

    host.querySelectorAll('[data-chatpuff-link]').forEach(function (button) {
      button.addEventListener('click', function () {
        // Opened during the click so that pop-up blockers allow it.
        popup = window.open('', 'chatpuff-link', 'popup,width=520,height=760');
        if (!popup) {
          show('error', host.getAttribute('data-popup-blocked'));
          return;
        }
        show('linking');
        post(host.getAttribute('data-link-url')).then(function (data) {
          if (!data.link_url) {
            popup.close();
            show('error', data.error);
            return;
          }
          linkOrigin = new URL(data.link_url).origin;
          popup.location.href = data.link_url;
          window.clearInterval(popupTimer);
          popupTimer = window.setInterval(function () {
            if (popup.closed) {
              afterLinking();
            }
          }, 1000);
        }).catch(function () {
          popup.close();
          show('error');
        });
      });
    });

    // ChatPuff's page says when the link is made, so the inbox opens without waiting.
    window.addEventListener('message', function (event) {
      if (linkOrigin !== null && event.origin === linkOrigin && event.data && event.data.type === 'chatpuff:employee-linked') {
        if (popup && !popup.closed) {
          popup.close();
        }
        afterLinking();
      }
    });

    start();
  }

  // Loaded in the footer; the check keeps it safe wherever it is loaded.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
