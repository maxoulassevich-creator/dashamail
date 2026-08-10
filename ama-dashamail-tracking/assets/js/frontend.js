/* global amaDashaMail, dashamail, jQuery */
(function (window, document, $) {
    'use strict';

    var cfg = window.amaDashaMail || {};
    var deliveredKey = 'ama_dm_delivered_event_ids_v1';
    var identityKey = 'ama_dm_known_identity_v1';
    var delivered = [];
    var knownCustomer = (cfg.knownCustomer && typeof cfg.knownCustomer === 'object') ? cfg.knownCustomer : {};

    try {
        delivered = JSON.parse(window.sessionStorage.getItem(deliveredKey) || '[]');
        if (!Array.isArray(delivered)) delivered = [];
    } catch (e) {
        delivered = [];
    }

    function debug() {
        if (!cfg.debug || !window.console || !window.console.log) return;
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[AMA DashaMail]');
        window.console.log.apply(window.console, args);
    }

    function remember(id) {
        if (!id) return;
        delivered.push(String(id));
        delivered = delivered.slice(-200);
        try {
            window.sessionStorage.setItem(deliveredKey, JSON.stringify(delivered));
        } catch (e) {}
    }

    function wasDelivered(id) {
        return id && delivered.indexOf(String(id)) !== -1;
    }

    function orderDedupeKey(event) {
        if (!event || !event.payload || !event.payload.operation) return '';
        var operation = event.payload.operation;
        var order = event.payload.data && event.payload.data.order ? event.payload.data.order : null;
        if (!order || !order.orderId) return '';
        if (String(operation).indexOf('Order') !== 0) return '';
        return 'ama_dm_order_' + operation + '_' + String(order.orderId);
    }

    function orderWasDelivered(event) {
        var key = orderDedupeKey(event);
        if (!key) return false;
        try { return window.localStorage.getItem(key) === '1'; } catch (e) { return false; }
    }

    function rememberOrder(event) {
        var key = orderDedupeKey(event);
        if (!key) return;
        try { window.localStorage.setItem(key, '1'); } catch (e) {}
    }

    function ensureTrackerQueue() {
        window.dashamail = window.dashamail || function () {
            (window.dashamail.queue = window.dashamail.queue || []).push(arguments);
        };
        window.dashamail.queue = window.dashamail.queue || [];
    }

    function send(command, payload) {
        ensureTrackerQueue();
        if (typeof payload === 'undefined') {
            window.dashamail(command);
        } else {
            window.dashamail(command, payload);
        }
    }

    /** Идентификация, уже выполненная в этой сессии, повторно не отправляется. */
    function identityEmailOf(event) {
        if (!event || event.command !== 'identify') return '';
        var ident = event.payload && event.payload.identificator;
        return (ident && ident.identity) ? String(ident.identity).trim().toLowerCase() : '';
    }

    function identityWasDelivered(event) {
        var email = identityEmailOf(event);
        if (!email) return false;
        try { return window.sessionStorage.getItem(identityStorageKey(email)) === '1'; } catch (e) { return false; }
    }

    function rememberIdentityEvent(event) {
        var email = identityEmailOf(event);
        if (!email) return;
        try { window.sessionStorage.setItem(identityStorageKey(email), '1'); } catch (e) {}
        knownCustomer = customerPayload({ email: email });
        storeIdentityLocally(email);
    }

    function sendEvent(event) {
        if (!event || !event.command || wasDelivered(event.id) || orderWasDelivered(event)) return;
        if (identityWasDelivered(event)) return;
        var command = String(event.command);
        var payload = event.payload || {};
        try {
            if (command === 'cart.clear' && (!payload || Object.keys(payload).length === 0)) {
                send('cart.clear');
            } else {
                send(command, payload);
            }
            remember(event.id);
            rememberOrder(event);
            rememberIdentityEvent(event);
            debug('sent', command, payload, event.source || '');
        } catch (error) {
            debug('send error', error, event);
        }
    }

    function sendEvents(events) {
        if (!Array.isArray(events)) return;
        events.forEach(sendEvent);
    }

    /* -----------------------------------------------------------------
     * Идентификация посетителя
     * ----------------------------------------------------------------- */

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || '').trim());
    }

    function identityStorageKey(email) {
        return 'ama_dm_identified_' + String(email || '').trim().toLowerCase();
    }

    /** Копирует непустые поля источников в цель — без зависимости от Object.assign. */
    function extend(target) {
        Array.prototype.slice.call(arguments, 1).forEach(function (source) {
            if (!source || typeof source !== 'object') return;
            Object.keys(source).forEach(function (key) {
                if (source[key]) target[key] = source[key];
            });
        });
        return target;
    }

    /** Блок customer для событий, собранных в браузере. */
    function customerPayload(extra) {
        var merged = extend({}, knownCustomer, extra);
        var data = {};
        ['email', 'mobilePhone', 'name', 'fname', 'lname'].forEach(function (key) {
            if (merged[key]) data[key] = String(merged[key]);
        });
        return data;
    }

    function storeIdentityLocally(email) {
        if (!cfg.rememberIdentity) return;
        try { window.localStorage.setItem(identityKey, String(email).toLowerCase()); } catch (e) {}
    }

    function readStoredIdentity() {
        if (!cfg.rememberIdentity) return '';
        try { return window.localStorage.getItem(identityKey) || ''; } catch (e) { return ''; }
    }

    /** Сообщаем серверу email, чтобы серверные события тоже содержали покупателя. */
    var notified = {};
    function notifyServer(email, consent) {
        email = String(email || '').trim().toLowerCase();
        if (!isValidEmail(email) || !cfg.ajaxUrl || !cfg.nonce) return;

        var key = email + '|' + (typeof consent === 'undefined' ? '' : (consent ? '1' : '0'));
        if (notified[key]) return;
        notified[key] = true;

        var payload = { action: 'ama_dm_identify', nonce: cfg.nonce, email: email };
        if (typeof consent !== 'undefined') payload.consent = consent ? '1' : '0';

        $.ajax({ url: cfg.ajaxUrl, method: 'POST', dataType: 'json', data: payload })
            .done(function (response) {
                if (response && response.success && response.data && response.data.customer) {
                    knownCustomer = response.data.customer;
                    debug('server identity updated', knownCustomer);
                }
            })
            .fail(function (xhr) { debug('identify failed', xhr && xhr.status); });
    }

    /**
     * Связывает анонимную сессию трекера с профилем подписчика.
     * Команда identify описана в руководстве DashaMail и порождает
     * событие Authorization; событие Identify фиксирует раскрытие личности.
     */
    function identity(email, extra) {
        email = String(email || '').trim().toLowerCase();
        if (!isValidEmail(email)) return false;

        knownCustomer = customerPayload(extend({}, extra, { email: email }));

        var key = identityStorageKey(email);
        var already = false;
        try { already = window.sessionStorage.getItem(key) === '1'; } catch (e) {}

        if (already) return true;

        send('identify', {
            operation: cfg.authorizationOperation || 'Authorization',
            identificator: { provider: 'email', identity: email }
        });

        if (cfg.trackIdentifyEvent) {
            send('async', {
                operation: cfg.identifyOperation || 'Identify',
                data: { customer: knownCustomer }
            });
        }

        try { window.sessionStorage.setItem(key, '1'); } catch (e) {}
        storeIdentityLocally(email);
        debug('identified', email, knownCustomer);

        return true;
    }

    function subscribe(email, confirmed) {
        var point = String(cfg.pointOfContact || '').trim();
        if (!point || !isValidEmail(email)) return;

        var operation = confirmed
            ? (cfg.subscribeOperation || 'UserSubscribe')
            : (cfg.subscribePendingOperation || 'UserSubscriberNoConfirmBTN');
        var key = 'ama_dm_' + operation + '_' + point + '_' + String(email).toLowerCase();

        try {
            if (window.localStorage.getItem(key) === '1') return;
        } catch (e) {}

        send('async', {
            operation: operation,
            data: {
                customer: customerPayload({ email: String(email).trim().toLowerCase() }),
                pointOfContact: point
            }
        });

        try { window.localStorage.setItem(key, '1'); } catch (e) {}
        debug('subscribed', email, point, operation);
    }

    /** Email из адреса страницы — посетитель пришёл по ссылке из письма. */
    function identityFromUrl() {
        var params = Array.isArray(cfg.identifyUrlParams) ? cfg.identifyUrlParams : [];
        if (!params.length || !window.location.search) return '';

        var query;
        try {
            query = new window.URLSearchParams(window.location.search);
        } catch (e) {
            return '';
        }

        for (var i = 0; i < params.length; i++) {
            var raw = query.get(params[i]);
            if (!raw) continue;
            var value = String(raw).trim();
            if (isValidEmail(value)) return value.toLowerCase();
            try {
                var decoded = window.atob(value.replace(/-/g, '+').replace(/_/g, '/'));
                if (isValidEmail(decoded)) return decoded.toLowerCase();
            } catch (err) {}
        }

        return '';
    }

    /* -----------------------------------------------------------------
     * Корзина
     * ----------------------------------------------------------------- */

    function snapshotHash(items) {
        try { return JSON.stringify(items || []); } catch (e) { return ''; }
    }

    function syncCartSnapshot(force) {
        var items = Array.isArray(cfg.cartSnapshot) ? cfg.cartSnapshot : [];
        var hash = snapshotHash(items);
        var key = 'ama_dm_cart_snapshot_' + hash;
        try {
            if (!force && window.sessionStorage.getItem(key) === '1') return;
        } catch (e) {}

        send('cart.clear');
        items.forEach(function (item) {
            if (!item || !item.productId || !item.quantity) return;
            send('cart.addProduct', {
                productId: String(item.productId),
                quantity: Number(item.quantity),
                price: String(item.price || '0')
            });
        });

        try { window.sessionStorage.setItem(key, '1'); } catch (e) {}
        debug('cart snapshot synced', items);
    }

    /* -----------------------------------------------------------------
     * Оформление заказа
     * ----------------------------------------------------------------- */

    function marketingConsentGranted() {
        if (!cfg.requireMarketingConsent) return true;
        var selector = String(cfg.marketingCheckboxSelector || '').trim();
        if (!selector) return false;
        var node = document.querySelector(selector);
        return !!(node && node.checked);
    }

    function checkoutFieldValue(names) {
        for (var i = 0; i < names.length; i++) {
            var node = document.querySelector(names[i]);
            if (node && String(node.value || '').trim()) return String(node.value).trim();
        }
        return '';
    }

    function identifyCheckout() {
        if (!cfg.identifyCheckoutEmail) return;

        var emailInput = document.querySelector('#billing_email, input[name="billing_email"]');
        if (!emailInput) return;

        var email = String(emailInput.value || '').trim().toLowerCase();
        if (!isValidEmail(email)) return;

        var consent = marketingConsentGranted();
        var extra = {
            fname: checkoutFieldValue(['#billing_first_name', 'input[name="billing_first_name"]']),
            lname: checkoutFieldValue(['#billing_last_name', 'input[name="billing_last_name"]']),
            mobilePhone: checkoutFieldValue(['#billing_phone', 'input[name="billing_phone"]'])
        };

        if (!consent) {
            // Согласия нет: личность не раскрываем, но фиксируем незавершённую подписку.
            if (cfg.subscribePending) subscribe(email, false);
            return;
        }

        notifyServer(email, true);

        if (identity(email, extra)) {
            subscribe(email, true);
            syncCartSnapshot(false);
        }
    }

    /** Email, введённый в любой другой форме сайта. */
    function handleEmailField(node) {
        if (!cfg.identifyAnyEmailField || !node) return;

        var email = String(node.value || '').trim().toLowerCase();
        if (!isValidEmail(email)) return;

        // На оформлении заказа работает отдельный обработчик с проверкой согласия.
        if (node.matches && node.matches('#billing_email, input[name="billing_email"]')) return;

        if (cfg.requireMarketingConsent && !marketingConsentGranted()) {
            if (cfg.subscribePending) subscribe(email, false);
            return;
        }

        notifyServer(email);
        identity(email);
    }

    /* -----------------------------------------------------------------
     * Очередь серверных событий
     * ----------------------------------------------------------------- */

    function pullEvents() {
        if (!cfg.ajaxUrl || !cfg.nonce) return;
        $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: { action: 'ama_dm_pull_events', nonce: cfg.nonce }
        }).done(function (response) {
            if (response && response.success && response.data) {
                sendEvents(response.data.events || []);
            }
        }).fail(function (xhr) {
            debug('pull failed', xhr && xhr.status);
        });
    }

    function bindWooEvents() {
        $(document.body).on(
            'added_to_cart removed_from_cart updated_wc_div updated_cart_totals applied_coupon_in_checkout removed_coupon_in_checkout updated_checkout',
            function () { window.setTimeout(pullEvents, 80); }
        );

        $(document.body).on('checkout_place_order_success', function (event, result) {
            if (result && Array.isArray(result.ama_dm_events)) {
                sendEvents(result.ama_dm_events);
            }
        });

        $(document).on('found_variation', 'form.variations_form', function (event, variation) {
            if (!variation || !variation.ama_dm_product_id) return;

            var product = (variation.ama_dm_product && typeof variation.ama_dm_product === 'object')
                ? variation.ama_dm_product
                : {
                    productId: String(variation.ama_dm_product_id),
                    price: String(variation.ama_dm_price || variation.display_price || '0')
                };

            var data = { product: product };
            var customer = customerPayload();
            if (Object.keys(customer).length) data.customer = customer;

            sendEvent({
                id: 'variation-view-' + variation.variation_id + '-' + Date.now(),
                command: 'async',
                source: 'variation_selected',
                payload: { operation: 'ViewProduct', data: data }
            });
        });
    }

    function bindCheckoutIdentity() {
        if (cfg.identifyCheckoutEmail) {
            $(document).on('change blur', '#billing_email, input[name="billing_email"]', function () {
                window.setTimeout(identifyCheckout, 50);
            });
            $(document).on('change blur', '#billing_first_name, #billing_last_name, #billing_phone', function () {
                window.setTimeout(identifyCheckout, 50);
            });
            if (cfg.marketingCheckboxSelector) {
                $(document).on('change', cfg.marketingCheckboxSelector, function () {
                    window.setTimeout(identifyCheckout, 50);
                });
            }
            $(document.body).on('updated_checkout', function () {
                window.setTimeout(identifyCheckout, 100);
            });
        }

        if (cfg.identifyAnyEmailField) {
            var selector = String(cfg.emailFieldSelector || 'input[type="email"]').trim();
            $(document).on('change blur', selector, function () {
                handleEmailField(this);
            });
            $(document).on('submit', 'form', function () {
                var node = this.querySelector(selector);
                if (node) handleEmailField(node);
            });
        }
    }

    $(function () {
        ensureTrackerQueue();

        // Порядок важен: сначала опознаём посетителя, затем шлём события,
        // чтобы они уже были привязаны к профилю, а не к анонимному UUID.
        var fromUrl = identityFromUrl();
        if (fromUrl) {
            notifyServer(fromUrl);
            identity(fromUrl);
        } else if (cfg.currentUserEmail) {
            identity(cfg.currentUserEmail, knownCustomer);
        } else if (knownCustomer.email) {
            identity(knownCustomer.email, knownCustomer);
        } else {
            var stored = readStoredIdentity();
            if (stored) identity(stored);
        }

        sendEvents(cfg.directEvents || []);
        sendEvents(cfg.orderEvents || []);

        if (cfg.currentUserEmail || knownCustomer.email || fromUrl) {
            syncCartSnapshot(false);
        }

        bindWooEvents();
        bindCheckoutIdentity();
        pullEvents();
        identifyCheckout();
    });
})(window, document, jQuery);
